import { available } from './focusTrap.js'

const FOCUS_RETURN_ATTRIBUTE = 'data-hilos-focus-return'

/** The current mounted record parts of one table projection, never placeholders. */
export interface TableFocusReturnRow {
  readonly rowKey: string
  readonly projection: 'wide' | 'card'
  readonly parts: readonly HTMLElement[]
}

/**
 * Capture an explicitly marked control's logical address before its row leaves
 * the DOM. The reader resolves only that row and projection in this table's
 * current mounted parts, accepting exactly one available control with the same
 * literal marker. Repeated data-id, text, or DOM order never supply an address.
 * Keep the reader only for the open dialog's lifetime.
 *
 * @param opener The original control at modal activation, still mounted.
 * @param readRows Read this table's current live record parts on each call.
 * @returns A fresh-target reader, or null without an explicit, unambiguous address.
 */
export function captureTableFocusReturnTarget(
  opener: HTMLElement,
  readRows: () => readonly TableFocusReturnRow[],
): (() => HTMLElement | null) | null {
  const controlKey = opener.getAttribute(FOCUS_RETURN_ATTRIBUTE)
  if (!controlKey || !available(opener)) return null
  const owners = readRows().filter((row) =>
    row.parts.some((part) => available(part) && part.contains(opener)),
  )
  if (owners.length !== 1) return null
  const { rowKey, projection } = owners[0]

  return () => {
    const targets = new Set<HTMLElement>()
    for (const row of readRows()) {
      if (row.rowKey !== rowKey || row.projection !== projection) continue
      for (const part of row.parts) {
        if (!available(part)) continue
        const candidates = [
          part,
          ...part.querySelectorAll(`[${FOCUS_RETURN_ATTRIBUTE}]`),
        ]
        for (const candidate of candidates) {
          if (
            candidate instanceof HTMLElement &&
            candidate.getAttribute(FOCUS_RETURN_ATTRIBUTE) === controlKey &&
            part.contains(candidate) &&
            available(candidate)
          ) {
            targets.add(candidate)
          }
        }
      }
    }

    return targets.size === 1 ? [...targets][0] : null
  }
}
