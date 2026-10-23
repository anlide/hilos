/** The closed line-count catalog for a table's main text region. */
export type HilosTableLineCount =
  1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12

/** The closed rem catalog for a table's main text region. */
export type HilosTableTextRem = 2 | 3 | 4.5 | 6 | 9 | 12

/** Text height only; row controls, badges, work and detail keep their own size. */
export type HilosTableRowHeight =
  | { readonly mode: 'content' }
  | { readonly mode: 'lines'; readonly count: HilosTableLineCount }
  | { readonly mode: 'height'; readonly rem: HilosTableTextRem }

const LINE_CLASSES: Readonly<Record<HilosTableLineCount, string>> = {
  1: 'hilos-table-text-lines-1',
  2: 'hilos-table-text-lines-2',
  3: 'hilos-table-text-lines-3',
  4: 'hilos-table-text-lines-4',
  5: 'hilos-table-text-lines-5',
  6: 'hilos-table-text-lines-6',
  7: 'hilos-table-text-lines-7',
  8: 'hilos-table-text-lines-8',
  9: 'hilos-table-text-lines-9',
  10: 'hilos-table-text-lines-10',
  11: 'hilos-table-text-lines-11',
  12: 'hilos-table-text-lines-12',
}
const HEIGHT_CLASSES: Readonly<Record<HilosTableTextRem, string>> = {
  2: 'hilos-table-text-height-2',
  3: 'hilos-table-text-height-3',
  4.5: 'hilos-table-text-height-4-5',
  6: 'hilos-table-text-height-6',
  9: 'hilos-table-text-height-9',
  12: 'hilos-table-text-height-12',
}

/**
 * Normalize an omitted declaration to intrinsic content and reject every value
 * outside the exact catalog shapes. The returned copy cannot change underneath
 * a mounted controller when its caller mutates the original declaration.
 *
 * @param value Local SDK configuration, including untyped runtime input.
 * @throws TypeError If the declaration has an unknown mode, key or preset.
 */
export function hilosTableRowHeight(value: unknown): HilosTableRowHeight {
  if (value === undefined) {
    return Object.freeze({ mode: 'content' })
  }
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const input = value as Record<string, unknown>
    const keys = Reflect.ownKeys(input)
    if (Object.hasOwn(input, 'mode')) {
      if (input['mode'] === 'content' && keys.length === 1) {
        return Object.freeze({ mode: 'content' })
      }
      if (
        input['mode'] === 'lines' &&
        keys.length === 2 &&
        Object.hasOwn(input, 'count') &&
        typeof input['count'] === 'number' &&
        Object.hasOwn(LINE_CLASSES, input['count'])
      ) {
        return Object.freeze({
          mode: 'lines',
          count: input['count'] as HilosTableLineCount,
        })
      }
      if (
        input['mode'] === 'height' &&
        keys.length === 2 &&
        Object.hasOwn(input, 'rem') &&
        typeof input['rem'] === 'number' &&
        Object.hasOwn(HEIGHT_CLASSES, input['rem'])
      ) {
        return Object.freeze({
          mode: 'height',
          rem: input['rem'] as HilosTableTextRem,
        })
      }
    }
  }

  throw new TypeError('Invalid table rowHeight declaration')
}

/**
 * Map one normalized text policy to the shared Sass catalog, without DOM reads.
 *
 * @param height The normalized policy exposed by the table frame.
 */
export function hilosTableTextClass(height: HilosTableRowHeight): string {
  const policy = hilosTableRowHeight(height)
  switch (policy.mode) {
    case 'content':
      return 'hilos-table-text'
    case 'lines':
      return `hilos-table-text ${LINE_CLASSES[policy.count]}`
    case 'height':
      return `hilos-table-text ${HEIGHT_CLASSES[policy.rem]}`
  }
}
