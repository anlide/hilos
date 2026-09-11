import type { Page } from '@playwright/test'

// The toast stack stands over the page's bottom-right corner, and every card in
// it takes clicks: a card leads where its notice points, and its close cross
// stays clickable over that (docs/agents/frontend/toasts.md). A notice raised by
// the step just performed can therefore cover the control the next step aims at,
// and Playwright answers that by retrying the click until the card expires on
// its own — twenty seconds later, because the countdown measures reading time.
//
// Those twenty seconds prove nothing. The stack has its own specs, which assert
// that a notice appears, that the cross closes it, and that a second tab sees
// the same card; a spec about a table repeating the wait only pays again for
// coverage it already has, and pays in the one currency a suite cannot spare.
//
// So sweeping the stack is how a spec says "this step is not about the notices".
// It is not a way to hide them: a spec that IS about a notice asserts on it and
// never calls this.

/** `data-id` of the close button every toast card carries, in all three SDKs. */
const TOAST_CLOSE = 'hilos-toast-close'

/**
 * Passes over the stack before giving up on emptying it. The stack is capped by
 * height rather than by a card count, so the bound sits deliberately above any
 * plausible stack instead of matching one: it exists to end a sweep that a page
 * keeps refilling, not to say how many cards may stand.
 */
const SWEEP_LIMIT = 12

/**
 * How long one close click may wait. Short on purpose: the cross is on screen
 * when the sweep reaches it, so a click that does not land at once means the
 * card expired under us rather than that it is slow, and the next pass reads the
 * emptier stack correctly.
 */
const CLOSE_CLICK_TIMEOUT_MS = 2_000

/** How long the last card may take to leave once the sweep has run out of passes. */
const SETTLE_TIMEOUT_MS = 5_000

/**
 * Close every toast standing in the stack, and settle only once none is left.
 *
 * Call it after a step whose notice is not the subject of the spec, before the
 * step that clicks what the notice may be covering.
 *
 * @param page the page whose stack is swept; a second window sweeps its own.
 */
export async function dismissToasts(page: Page): Promise<void> {
  const closes = page.getByTestId(TOAST_CLOSE)

  for (let sweep = 0; sweep < SWEEP_LIMIT; sweep += 1) {
    if ((await closes.count()) === 0) {
      return
    }
    // The newest card is the lowest one. A card may expire between the count and
    // the click, and that refusal is the stack shrinking under us — which the
    // next pass reads correctly and the wait below judges.
    await closes
      .last()
      .click({ timeout: CLOSE_CLICK_TIMEOUT_MS })
      .catch(() => undefined)
  }

  // Out of passes with cards still standing: let the wait say so in the language
  // of what is on screen, rather than returning as if the stack were clear.
  await closes
    .first()
    .waitFor({ state: 'detached', timeout: SETTLE_TIMEOUT_MS })
}
