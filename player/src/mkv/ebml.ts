/**
 * EBML primitives.
 *
 * Everything here works on a byte window that may be only part of the file, so
 * every read reports "need more data" rather than throwing — the caller decides
 * whether to range-fetch more or give up.
 */

/** Returned when the window ends mid-element. */
export const NEED_MORE = Symbol('NEED_MORE')
export type NeedMore = typeof NEED_MORE

/** An element with an explicitly unknown size (live/streamed Segments). */
export const UNKNOWN_SIZE = -1

export interface ElementHeader {
  id: number
  /** Byte length of the id + size fields. */
  headerSize: number
  /** Payload length, or `UNKNOWN_SIZE`. */
  size: number
}

export class EbmlReader {
  offset = 0

  readonly bytes: Uint8Array
  /** File offset the window starts at, so positions can be absolute. */
  readonly base: number

  constructor(bytes: Uint8Array, base = 0) {
    this.bytes = bytes
    this.base = base
  }

  get remaining(): number {
    return this.bytes.length - this.offset
  }

  /** Absolute file offset of the cursor. */
  get position(): number {
    return this.base + this.offset
  }

  seekTo(absolute: number): void {
    this.offset = absolute - this.base
  }

  /**
   * Read an element id. Ids keep their EBML marker bit, matching `ID.*`.
   * Returns NEED_MORE if the window is too short.
   */
  readId(): number | NeedMore {
    if (this.remaining < 1) return NEED_MORE
    const first = this.bytes[this.offset]!
    const length = vintLength(first)
    // A zero first byte means an id longer than 4 bytes: not valid Matroska.
    if (length === 0 || length > 4) throw new Error(`Invalid EBML id at ${this.position}`)
    if (this.remaining < length) return NEED_MORE

    let value = 0
    for (let i = 0; i < length; i++) {
      value = value * 256 + this.bytes[this.offset + i]!
    }
    this.offset += length
    return value
  }

  /** Read an element size (marker bit stripped). */
  readSize(): number | NeedMore {
    if (this.remaining < 1) return NEED_MORE
    const first = this.bytes[this.offset]!
    const length = vintLength(first)
    if (length === 0 || length > 8) throw new Error(`Invalid EBML size at ${this.position}`)
    if (this.remaining < length) return NEED_MORE

    // Strip the marker bit from the first byte.
    let value = first & ((1 << (8 - length)) - 1)
    let allOnes = value === (1 << (8 - length)) - 1
    for (let i = 1; i < length; i++) {
      const byte = this.bytes[this.offset + i]!
      if (byte !== 0xff) allOnes = false
      value = value * 256 + byte
    }
    this.offset += length
    // All-ones is the "unknown size" encoding.
    if (allOnes) return UNKNOWN_SIZE
    if (!Number.isSafeInteger(value)) throw new Error(`EBML size too large at ${this.position}`)
    return value
  }

  readHeader(): ElementHeader | NeedMore {
    const start = this.offset
    const id = this.readId()
    if (id === NEED_MORE) {
      this.offset = start
      return NEED_MORE
    }
    const size = this.readSize()
    if (size === NEED_MORE) {
      this.offset = start
      return NEED_MORE
    }
    return { id, size, headerSize: this.offset - start }
  }

  /** Unsigned big-endian integer of `length` bytes. */
  readUint(length: number): number {
    let value = 0
    for (let i = 0; i < length; i++) {
      value = value * 256 + this.bytes[this.offset + i]!
    }
    this.offset += length
    if (!Number.isSafeInteger(value)) throw new Error('EBML uint exceeds safe integer range')
    return value
  }

  /**
   * Unsigned big-endian integer as a bigint.
   *
   * Matroska UIDs are random 64-bit values that routinely exceed
   * Number.MAX_SAFE_INTEGER, so they cannot go through `readUint`.
   */
  readBigUint(length: number): bigint {
    let value = 0n
    for (let i = 0; i < length; i++) {
      value = (value << 8n) | BigInt(this.bytes[this.offset + i]!)
    }
    this.offset += length
    return value
  }

  /** Signed big-endian integer, two's complement. */
  readInt(length: number): number {
    if (length === 0) return 0
    let value = this.bytes[this.offset]!
    if (value & 0x80) value -= 0x100
    for (let i = 1; i < length; i++) {
      value = value * 256 + this.bytes[this.offset + i]!
    }
    this.offset += length
    return value
  }

  /** IEEE float, 4 or 8 bytes. Matroska also permits 0 (meaning 0.0). */
  readFloat(length: number): number {
    const view = new DataView(this.bytes.buffer, this.bytes.byteOffset + this.offset, length || 1)
    let value: number
    if (length === 4) value = view.getFloat32(0)
    else if (length === 8) value = view.getFloat64(0)
    else if (length === 0) value = 0
    else throw new Error(`Unsupported float width ${length}`)
    this.offset += length
    return value
  }

  readString(length: number): string {
    const slice = this.bytes.subarray(this.offset, this.offset + length)
    this.offset += length
    // Matroska pads strings with NULs; strip them before decoding.
    let end = slice.length
    while (end > 0 && slice[end - 1] === 0) end--
    return new TextDecoder('utf-8').decode(slice.subarray(0, end))
  }

  /** Copies, so the returned array outlives the window buffer. */
  readBytes(length: number): Uint8Array {
    const slice = this.bytes.slice(this.offset, this.offset + length)
    this.offset += length
    return slice
  }

  skip(length: number): void {
    this.offset += length
  }
}

/** Number of bytes in a VINT whose first byte is `first`. 0 means invalid. */
export function vintLength(first: number): number {
  if (first === 0) return 0
  let length = 1
  let mask = 0x80
  while ((first & mask) === 0) {
    mask >>= 1
    length++
  }
  return length
}

/**
 * Read a VINT *value* (marker stripped) from a raw buffer at `offset`.
 * Used inside blocks, where track numbers are VINT-encoded.
 */
export function readVintValue(
  bytes: Uint8Array,
  offset: number,
): { value: number; length: number } | null {
  if (offset >= bytes.length) return null
  const first = bytes[offset]!
  const length = vintLength(first)
  if (length === 0 || offset + length > bytes.length) return null
  let value = first & ((1 << (8 - length)) - 1)
  for (let i = 1; i < length; i++) {
    value = value * 256 + bytes[offset + i]!
  }
  return { value, length }
}
