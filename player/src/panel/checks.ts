/**
 * The pass/fail list a test run fills in.
 *
 * Shared between the development harness and the WordPress admin panel so the
 * two cannot drift. A check can carry an action, which is what turns
 * "font missing" into "font missing [Upload]".
 */

export type CheckState = 'pending' | 'ok' | 'warn' | 'bad'

export interface CheckAction {
  label: string
  onSelect: () => void
}

export interface CheckEntry {
  key: string
  label: string
  state: CheckState
  detail: string
  /** Indented under the check above it. */
  nested?: boolean
  action?: CheckAction
}

const MARKS: Record<CheckState, string> = {
  pending: '·',
  ok: '✓',
  warn: '!',
  bad: '✗',
}

export class CheckList {
  #root: HTMLElement
  #entries = new Map<string, CheckEntry>()

  constructor(root: HTMLElement) {
    this.#root = root
  }

  set(entry: CheckEntry): void {
    this.#entries.set(entry.key, entry)
    this.#render()
  }

  /** Update only the state and detail of a check that already exists. */
  update(key: string, state: CheckState, detail = ''): void {
    const existing = this.#entries.get(key)
    if (!existing) return
    this.#entries.set(key, { ...existing, state, detail })
    this.#render()
  }

  get(key: string): CheckEntry | undefined {
    return this.#entries.get(key)
  }

  /** Drop every check whose key starts with `prefix`. */
  removeByPrefix(prefix: string): void {
    let changed = false
    for (const key of [...this.#entries.keys()]) {
      if (key.startsWith(prefix)) {
        this.#entries.delete(key)
        changed = true
      }
    }
    if (changed) this.#render()
  }

  clear(): void {
    this.#entries.clear()
    this.#render()
  }

  entries(): CheckEntry[] {
    return [...this.#entries.values()]
  }

  #render(): void {
    this.#root.replaceChildren(
      ...[...this.#entries.values()].map((entry) => {
        const row = document.createElement('div')
        row.className = 'ap-check'
        row.dataset.state = entry.state
        if (entry.nested) row.dataset.nested = 'true'

        const mark = document.createElement('span')
        mark.className = 'ap-check__mark'
        mark.textContent = MARKS[entry.state]
        mark.setAttribute('aria-hidden', 'true')

        const name = document.createElement('span')
        name.className = 'ap-check__name'
        name.textContent = entry.label

        const detail = document.createElement('span')
        detail.className = 'ap-check__detail'
        detail.textContent = entry.detail

        row.append(mark, name, detail)

        if (entry.action) {
          const button = document.createElement('button')
          button.type = 'button'
          button.className = 'ap-check__action'
          button.textContent = entry.action.label
          button.addEventListener('click', entry.action.onSelect)
          row.append(button)
        }

        // Screen readers get the state as words; the glyph alone says nothing.
        row.setAttribute('aria-label', `${entry.label}: ${entry.state}. ${entry.detail}`)
        return row
      }),
    )
  }
}
