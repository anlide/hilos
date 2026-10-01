// The words of the admin view mode (HIL-1261), one set for the three frontends.
// `mark` and `explanation` are the words of the shell's view-mode strip
// (HIL-1260); `hidden` is the word for a value the server keeps from the viewer
// (state/hiddenValue.ts) — the mark's word, and the word a string with no markup
// says in its place; `refusal` is the sentence on the screen for a server refusal
// with the code `view_mode`. The server's own reason on that refusal is the
// impersonal one (PHP `SignalConstants::ACTION_FAILED_REASON`), so the frontend
// chooses the sentence (HIL-1251), and it does so here, in the core, once for
// every frontend: the action lifecycle and the action-error store read the code
// and hand the screen this sentence instead of the reason.
//
// The id of the strip's text lives here, not in a view layer like the page
// heading's id (vue/src/hilosPageHeading.ts): that one is minted per page, this
// one is one per document, and the controls the mode disables in all three
// frontends point at it (aria-describedby), as does the strip itself.

import { type Hideable, isHiddenValue } from '../state/hiddenValue.js'
import {
  IMPERSONATION_ERROR_CODE,
  IMPERSONATION_REFUSAL_COPY,
} from '../session/impersonationRefusal.js'

/**
 * The refusal the server answers a viewer of the admin view mode with (PHP
 * `ActionViewModeException::ERROR_CODE`, HIL-1251): the `errorCode` of an
 * action error.
 */
export const VIEW_MODE_ERROR_CODE = 'view_mode'

/**
 * The words of the admin view mode: `mark` and `explanation` — the shell's
 * view-mode strip (HIL-1260) draws them; `hidden` — the word for a hidden
 * value, on its mark and in a string with no markup; `refusal` — the screen's
 * sentence for a server refusal with {@link VIEW_MODE_ERROR_CODE}.
 */
export const HILOS_VIEW_MODE_COPY = {
  mark: 'View mode',
  explanation: 'You can look around, but not change anything.',
  hidden: 'Hidden',
  refusal: 'View mode: you can look around, but not change anything.',
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
 * otherwise — a modal's title, an aria-label, and every place the React and
 * Angular views draw until they carry the mark of their own (HIL-1271,
 * HIL-1272).
 *
 * @param value The value, or the hidden mark in its place.
 */
export function hiddenAsWord<T>(value: Hideable<T>): T | string {
  return isHiddenValue(value) ? HILOS_VIEW_MODE_COPY.hidden : value
}

/**
 * The sentence the screen shows for a failed action: the view mode's own
 * refusal for {@link VIEW_MODE_ERROR_CODE}, a takeover's for the two codes of
 * {@link IMPERSONATION_ERROR_CODE} (HIL-1170), the server's reason for any other
 * code or none.
 *
 * @param reason The failure reason the server sent.
 * @param errorCode The machine-readable code of the failure, or undefined.
 */
export function actionFailureReason(
  reason: string,
  errorCode: string | undefined,
): string {
  switch (errorCode) {
    case VIEW_MODE_ERROR_CODE:
      return HILOS_VIEW_MODE_COPY.refusal
    case IMPERSONATION_ERROR_CODE.viewOnly:
      return IMPERSONATION_REFUSAL_COPY.viewOnly
    case IMPERSONATION_ERROR_CODE.accountAccess:
      return IMPERSONATION_REFUSAL_COPY.accountAccess
    default:
      return reason
  }
}
