import { test, expect } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import {
  appendLogLines,
  FOLLOWED_STREAM,
  logMarker,
  TAIL_ARRIVAL_TIMEOUT_MS,
} from '../helpers/logs'
import { gotoPage, PAGE_READY } from '../helpers/page'

// The end-to-end pass over the log tail (HIL-395, layer 6 of HIL-350). One
// scenario rather than a suite: the cheap layers — frame shapes, the buffer, the
// grouping — are covered by unit tests, and what only a browser can answer is
// whether a line written by the daemon travels the whole chain on its own.
//
// The line is written by the log-store agent over the command channel
// (helpers/logs.ts), not appended to the file by this process: the agent prints
// it, the master files it under the agent's own log, and only then does the node
// owner notice it grew. A test that wrote the file itself would prove that a
// file grew.
//
// The second half is the one place in the epic where the stickiness threshold is
// measured against a real pane of a real size — the headless unit and the view
// tests run in jsdom, where every height is fictional.

/** The address of the followed stream: this node, the live file, that name. */
const VIEWER_PATH = `/hilos/logs/view/-/live/${FOLLOWED_STREAM}`

/**
 * Lines appended to make the pane taller than its 26rem, so that scrolling means
 * something. Below this the reader is at the tail whatever it does, and the
 * whole second half of the scenario passes without asserting anything.
 */
const PANE_FILLER_LINES = 80

test('follows a live log file: an appended line arrives on its own, and one appended while the reader is up the pane waits behind the return button', async ({
  page,
}) => {
  // Four waits on the tail, each of them a server round rather than a repaint,
  // so the whole scenario does not fit the cap a test of DOM work is given.
  test.slow()

  await signUpAdmin(page)
  await gotoPage(page, VIEWER_PATH, PAGE_READY)

  // Two gates before the first append, and they answer different questions.
  // The badge says a tail is running: the follow starts by itself, from the end
  // of the file as it was at that moment, so a line written before the start
  // reached the owner would never be in a frame. It is waited for rather than
  // the switch, which only says the reader wants one.
  await expect(page.getByTestId('hilos-log-tail-badge')).toBeVisible()
  // The pane says it is drawing lines at all. Until the catalog of sources has
  // reached this browser the pane draws a placeholder INSTEAD of the feed, so a
  // spec that appended here would be asserting against a screen that shows
  // nothing whatever arrives.
  await expect(page.getByTestId('hilos-log-entry').first()).toBeVisible({
    timeout: TAIL_ARRIVAL_TIMEOUT_MS,
  })

  const arriving = logMarker('e2e-follow-arrives')
  expect(await appendLogLines(arriving, 1)).toBe(1)

  const pane = page.getByTestId('hilos-log-pane')
  await expect(
    page.getByTestId('hilos-log-entry').filter({ hasText: `${arriving} #1` }),
  ).toBeVisible({ timeout: TAIL_ARRIVAL_TIMEOUT_MS })

  // Nothing was reloaded and "Earlier" was never pressed: the line is on screen
  // because a frame carried it there.

  const filler = logMarker('e2e-follow-filler')
  expect(await appendLogLines(filler, PANE_FILLER_LINES)).toBe(
    PANE_FILLER_LINES,
  )
  await expect
    .poll(
      () =>
        pane.evaluate((element) => element.scrollHeight - element.clientHeight),
      { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
    )
    .toBeGreaterThan(0)

  // Reading something above. A programmatic scroll raises the ordinary scroll
  // event the view listens to; neither the code nor the test tries to tell a
  // reader's scroll from its own.
  await pane.evaluate((element) => {
    element.scrollTop = 0
  })
  const backToTail = page.getByTestId('hilos-log-back-to-tail')
  await expect(backToTail).toBeVisible()

  const waiting = logMarker('e2e-follow-waits')
  expect(await appendLogLines(waiting, 1)).toBe(1)

  // The counter is asserted first, because it is what proves the line arrived at
  // all: an absence asserted on its own would also hold for a line still in
  // flight. It is matched by shape rather than against a strict one — the owner
  // of the logs writes into this same file now and then (a start, a stop, a
  // rotation), and a hard number would go red on its own schedule.
  await expect(backToTail).toHaveText(/\d+ new/, {
    timeout: TAIL_ARRIVAL_TIMEOUT_MS,
  })
  await expect(
    page.getByTestId('hilos-log-entry').filter({ hasText: `${waiting} #1` }),
  ).toHaveCount(0)

  await backToTail.click()
  await expect(
    page.getByTestId('hilos-log-entry').filter({ hasText: `${waiting} #1` }),
  ).toBeVisible()

  // The Follow switch was not touched once in the whole scenario, which is the
  // decision this asserts: scrolling releases the stickiness, not the tail.
})
