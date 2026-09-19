// The two values of the skeleton a view draws where data has not arrived yet.
// Kept in the core for the reason `RECONNECT_DRAGGING_COPY` is: the three view
// packages must not drift into three different skeletons for one state.

/**
 * The delay before a page skeleton appears, in milliseconds. A page that answers
 * sooner never shows one, so a quick navigation does not flash grey bars.
 *
 * Equal to `DEFAULT_SPINNER_DELAY_MS` today, but a different quantity: the two
 * are kept apart so that moving one does not silently move the other.
 */
export const DEFAULT_SKELETON_DELAY_MS = 300

/**
 * The widths of the default skeleton's bars, in Bootstrap columns (1..12) — the
 * three bars of the mockup's «The connection went away» screen, in its order.
 */
export const HILOS_SKELETON_LINES: readonly number[] = [9, 6, 10]
