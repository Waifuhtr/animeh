/** Key/value measurement table. */
export class StatsTable {
  #root: HTMLElement

  constructor(root: HTMLElement) {
    this.#root = root
  }

  render(rows: [label: string, value: string][]): void {
    this.#root.replaceChildren(
      ...rows.flatMap(([label, value]) => {
        const dt = document.createElement('dt')
        dt.textContent = label
        const dd = document.createElement('dd')
        dd.textContent = value
        return [dt, dd]
      }),
    )
  }
}
