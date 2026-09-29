// The words of the admin view mode (HIL-1261), one set for the three frontends.
// `mark` and `explanation` are the words of the shell's view-mode strip, which
// HIL-1260 draws; `refusal` is the sentence on the screen for a server refusal
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

/**
 * The refusal the server answers a viewer of the admin view mode with (PHP
 * `ActionViewModeException::ERROR_CODE`, HIL-1251): the `errorCode` of an
 * action error.
 */
export const VIEW_MODE_ERROR_CODE = 'view_mode'

/**
 * The words of the admin view mode: `mark` and `explanation` — the shell's
 * view-mode strip (HIL-1260) draws them; `refusal` — the screen's sentence for
 * a server refusal with {@link VIEW_MODE_ERROR_CODE}.
 */
export const HILOS_VIEW_MODE_COPY = {
  mark: 'View mode',
  explanation: 'You can look around, but not change anything.',
  refusal: 'View mode: you can look around, but not change anything.',
} as const

/**
 * The DOM id of the view-mode strip's text: every control the mode disables
 * names it in its `aria-describedby`, so a screen reader reads the one reason
 * the screen shows once.
 */
export const HILOS_VIEW_MODE_STRIP_TEXT_ID = 'hilos-view-mode-strip-text'

/**
 * The sentence the screen shows for a failed action: the view mode's own
 * refusal for {@link VIEW_MODE_ERROR_CODE}, the server's reason for any other
 * code or none.
 *
 * @param reason The failure reason the server sent.
 * @param errorCode The machine-readable code of the failure, or undefined.
 */
export function actionFailureReason(
  reason: string,
  errorCode: string | undefined,
): string {
  return errorCode === VIEW_MODE_ERROR_CODE
    ? HILOS_VIEW_MODE_COPY.refusal
    : reason
}
