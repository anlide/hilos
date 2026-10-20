import type { Expect, Page } from '@playwright/test'

// The toast stack stands over the page's bottom-right corner, and every card in
// it takes clicks: a card leads where its notice points, and its close cross
// stays clickable over that (docs/agents/frontend/toasts.md). A notice raised by
// the step just performed can therefore cover the control the next step aims at,
// and Playwright answers that by retrying the click until the card expires on
// its own — twenty seconds later, because the countdown measures reading time.
// While a modal is open the stack takes no clicks at all, so a notice can cover
// only the page's own controls, and this helper is for those.
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

/** `data-id` of the stack's container, in all three SDKs. */
const TOAST_STACK = 'hilos-toasts'

/**
 * The cards of the stack, one `data-id` per severity, copied verbatim from
 * HilosToastSeverity (core/src/state/toasts.ts). The e2e package builds against
 * no framework source, so the list is kept beside the check that reads it.
 */
const TOAST_CARDS = ['error', 'success', 'info', 'warning']
  .map((severity) => `[data-id="hilos-toast-${severity}"]`)
  .join(', ')

/** `data-id` of the service line under the newest card, when it is drawn. */
const TOAST_OVERFLOW = 'hilos-toast-overflow'

/**
 * How far an edge may drift through a repaint's rounding, in CSS pixels — the
 * same slack geometry.ts gives every bookmark.
 */
const SLACK_PX = 1

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

/**
 * Where the stack stands: the edge of the window it sits on, and whether it
 * spans the window's width or keeps its corner.
 */
export interface ToastPlacement {
  edge: 'top' | 'bottom'
  fullWidth: boolean
}

/** One reading of the stack, taken in the page in a single pass. */
interface StackReading {
  viewport: { width: number; height: number }
  stack: { top: number; bottom: number; left: number; right: number }
  inner: { left: number; right: number }
  padding: { left: number; right: number }
  lines: { what: string; left: number; right: number }[]
}

/**
 * Assert where the toast stack stands on screen (docs/agents/frontend/toasts.md).
 *
 * On a narrow screen the stack sits at the bottom across the width and moves to
 * the top while a bottom-sheet dialog is open; on a wide one it keeps the
 * project's corner, bottom-end by default. The spec waits for the state it is
 * proving — the dialog open or gone, the notice raised — and this waits only for
 * a card to be laid out, then reads the stack, its cards and its service line in
 * one pass. Margins are judged by the stack's computed padding rather than by
 * numbers copied from Bootstrap, so the spec names none.
 *
 * @param expect The spec's expect.
 * @param page The page whose stack is read; at least one card must stand in it.
 * @param placement The edge the stack must stand on, and whether it spans the
 *   window: across the width, the stack touches both sides of the window and
 *   every card and the service line fill it within equal margins; in a corner,
 *   the stack touches the window's right side and is narrower than the window.
 */
export async function expectToastPlacement(
  expect: Expect,
  page: Page,
  placement: ToastPlacement,
): Promise<void> {
  const stack = page.getByTestId(TOAST_STACK)
  await expect(stack).toBeVisible()
  const read = (): Promise<StackReading> =>
    stack.evaluate(
      (root, { cards, overflow }) => {
        // A card still being measured stands invisible beside the stack and is
        // not part of what a person sees.
        const shown = (node: Element) =>
          node.checkVisibility({
            visibilityProperty: true,
            opacityProperty: true,
          })
        const box = root.getBoundingClientRect()
        const style = getComputedStyle(root)
        const padding = {
          left: parseFloat(style.paddingLeft),
          right: parseFloat(style.paddingRight),
        }
        const lines = [
          ...Array.from(root.querySelectorAll(`:scope > ${cards}`)),
          ...Array.from(
            root.querySelectorAll(`:scope > [data-id="${overflow}"]`),
          ),
        ]
          .filter(shown)
          .map((node) => {
            const line = node.getBoundingClientRect()

            return {
              what: node.getAttribute('data-id') ?? '',
              left: line.left,
              right: line.right,
            }
          })

        return {
          viewport: {
            width: document.documentElement.clientWidth,
            height: document.documentElement.clientHeight,
          },
          stack: {
            top: box.top,
            bottom: box.bottom,
            left: box.left,
            right: box.right,
          },
          inner: {
            left: box.left + parseFloat(style.borderLeftWidth) + padding.left,
            right:
              box.right - parseFloat(style.borderRightWidth) - padding.right,
          },
          padding,
          lines,
        }
      },
      { cards: TOAST_CARDS, overflow: TOAST_OVERFLOW },
    )

  // A notice is laid out a frame after it arrives; wait for that state, then
  // judge one reading rather than polling for the layout to come right.
  await expect
    .poll(async () => (await read()).lines.length, {
      message: 'a toast card laid out in the stack',
    })
    .toBeGreaterThan(0)
  const { viewport, stack: box, inner, padding, lines } = await read()
  const near = (actual: number, wanted: number, what: string) =>
    expect(
      Math.abs(actual - wanted),
      `${what}: ${actual} against ${wanted}, ${SLACK_PX}px of slack`,
    ).toBeLessThanOrEqual(SLACK_PX)

  if (placement.edge === 'bottom') {
    near(box.bottom, viewport.height, 'stack bottom at the window bottom')
  } else {
    near(box.top, 0, 'stack top at the window top')
  }
  near(box.right, viewport.width, 'stack right edge at the window right')

  if (!placement.fullWidth) {
    expect(
      box.right - box.left,
      `stack width against window width ${viewport.width}: a corner, not the width`,
    ).toBeLessThan(viewport.width - SLACK_PX)

    return
  }
  near(box.left, 0, 'stack left edge at the window left')
  near(padding.left, padding.right, 'stack margins equal on both sides')
  for (const line of lines) {
    near(line.left, inner.left, `${line.what} left edge at the stack margin`)
    near(line.right, inner.right, `${line.what} right edge at the stack margin`)
  }
}
