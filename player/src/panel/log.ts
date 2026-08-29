export type LogTone = 'info' | 'ok' | 'warn' | 'error'

export interface LogLine {
  at: number
  tone: LogTone
  message: string
}

/**
 * Newest-first event log.
 *
 * Keeps its lines as data as well as DOM, so a test run can be persisted to
 * the backend exactly as it appeared on screen.
 */
export class LogView {
  #root: HTMLElement
  #max: number
  #lines: LogLine[] = []

  constructor(root: HTMLElement, max = 300) {
    this.#root = root
    this.#max = max
  }

  append(message: string, tone: LogTone = 'info'): LogLine {
    const line: LogLine = { at: Date.now(), tone, message }
    this.#lines.push(line)
    if (this.#lines.length > this.#max) this.#lines.shift()

    const node = document.createElement('div')
    node.className = `ap-log__line ap-log__line--${tone}`
    const time = new Date(line.at).toLocaleTimeString('tr-TR', { hour12: false })
    node.textContent = `${time}  ${message}`
    this.#root.prepend(node)
    while (this.#root.childElementCount > this.#max) this.#root.lastElementChild?.remove()
    return line
  }

  /** Lines gathered so far, oldest first. */
  lines(): LogLine[] {
    return [...this.#lines]
  }

  clear(): void {
    this.#lines = []
    this.#root.replaceChildren()
  }
}
