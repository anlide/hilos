// The words of the admin view mode (HIL-1261), one set for the three frontends.
// `mark` and `explanation` are the words of the shell's view-mode strip
// (HIL-1260); `hidden` is the word for a value the server keeps from the viewer
// (state/hiddenValue.ts) — the mark's word, and the word a string with no markup
// says in its place. Refusal sentences come from the backend's action reason.
//
// The id of the strip's text lives here, not in a view layer like the page
// heading's id (vue/src/hilosPageHeading.ts): that one is minted per page, this
// one is one per document, and the controls the mode disables in all three
// frontends point at it (aria-describedby), as does the strip itself.

import { type Hideable, isHiddenValue } from '../state/hiddenValue.js'

/**
 * The refusal the server answers a viewer of the admin view mode with (PHP
 * `ActionViewModeException::ERROR_CODE`, HIL-1251): the `errorCode` of an
 * action error.
 */
export const VIEW_MODE_ERROR_CODE = 'view_mode'

/**
 * The words of the admin view mode: `mark` and `explanation` — the shell's
 * view-mode strip (HIL-1260) draws them; `hidden` — the word for a hidden
 * value, on its mark and in a string with no markup.
 */
export const HILOS_VIEW_MODE_COPY = {
  mark: 'View mode',
  explanation: 'You can look around, but not change anything.',
  hidden: 'Hidden',
} as const

/**
 * The DOM id of the view-mode strip's text: every control the mode disables
 * names it in its `aria-describedby`, so a screen reader reads the one reason
 * the screen shows once.
 */
export const HILOS_VIEW_MODE_STRIP_TEXT_ID = 'hilos-view-mode-strip-text'

/**
 * A hideable value as a string with no markup can carry it: the word
 * {@link HILOS_VIEW_MODE_COPY.hidden} for a hidden value, the value itself
 * otherwise — a modal's title, an aria-label, the text of a notice.
 *
 * @param value The value, or the hidden mark in its place.
 */
export function hiddenAsWord<T>(value: Hideable<T>): T | string {
  return isHiddenValue(value) ? HILOS_VIEW_MODE_COPY.hidden : value
}
