import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

import { gotoMaintenance, gotoPage } from '../helpers/page'
import {
  enterProtectedMode,
  leaveProtectedMode,
  mintProtectedModePass,
  openProtectedModeIfAny,
} from '../helpers/protectedMode'

// The Angular half of HIL-615 and HIL-616: the verifier's code field belongs to
// administrative surfaces and to nowhere else, and only once a code exists to type
// into it. Angular has no component test of its own (`ng test` is blocked
// upstream), so this live run is where the view package's binding of the route's
// surface type and of the minted marker is actually proven.
//
// The whole node freezes for the duration, so this spec must never leave one
// behind: the runner is serialized (CI=1 → workers: 1), and the teardown lifts
// unconditionally.

const OPERATION = 'e2e-freeze'

// The framework dashboard, an administrative surface by its own route
// declaration (HILOS_ROUTE_DECLARATIONS). Any admin url would do; this one needs
// no route params.
const ADMIN_URL = '/hilos'

test.afterEach(async () => {
  // Unconditional, and an open rather than a leave: an enter can be refused and
  // still land afterwards, a failed assertion can strand the node in any phase,
  // and only the open lifts from all of them.
  await openProtectedModeIfAny()
})

test('the verification window waits for a code before it offers a field', async ({
  page,
}) => {
  // Both loads are cold, and deliberately so: the stub is painted from the
  // welcome frame, before any subscription, so this is the frame where the
  // verdict has to be right without a round trip.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  // A public url in the window looks exactly like the active phase. That is the
  // point of the leaf: a visitor holds no code, and a field would announce to
  // him that a window is open at all.
  await gotoMaintenance(page, '/')
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()

  // The same phase, the same freeze, an administrative url — and nothing minted
  // yet. The verifier is told to wait rather than handed a box that can take
  // nothing.
  await gotoMaintenance(page, ADMIN_URL)
  await expect(page.getByTestId('maintenance-pass-pending')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()

  // The first mint reaches this page as a frame: the sentence becomes the field
  // with nothing clicked and nothing navigated.
  const urlBeforeTheMint = page.url()
  await mintProtectedModePass()

  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-submit')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
  expect(page.url()).toBe(urlBeforeTheMint)
})

test('a public page live through the switch gains no code field', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  expect(await enterProtectedMode(OPERATION)).toBe('active')
  await expect(page.getByTestId('maintenance')).toBeVisible()

  expect(await leaveProtectedMode()).toBe('verifying')
  await mintProtectedModePass()

  // A second tab proves the window really is open to browsers right now, so the
  // assertion below is about the surface type and not about a frame still in
  // flight.
  const verifierTab = await page.context().newPage()
  await gotoMaintenance(verifierTab, ADMIN_URL)
  await expect(verifierTab.getByTestId('maintenance-pass-form')).toBeVisible()
  await verifierTab.close()

  // The public page never navigated, so its route — and its verdict — never
  // changed: neither the switch into the window nor the mint inside it adds
  // anything to it.
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
})

test('a load into a frozen node never flashes the ordinary layout', async ({
  page,
}) => {
  // The defect HIL-613 closes: the shell paints before the welcome frame lands,
  // so a browser reloading into maintenance sees the whole application for a
  // frame — navbar, brand, footer — and only then the stub. The watcher goes in
  // before the app does, because that frame is what has to be proved absent.
  await watchFirstFrame(page)
  expect(await enterProtectedMode(OPERATION)).toBe('active')

  await gotoMaintenance(page, '/')

  const watch = await readFirstFrameWatch(page)
  expect(watch).not.toBeNull()
  // Before anything it saw: a watcher that failed to install reports an absence
  // it never looked for, and every assertion below would pass on it.
  expect(watch?.error).toBe('')
  // The hold engaged and then let go on the welcome — without both halves the
  // case below would pass on a page that simply never booted.
  expect(watch?.states).toEqual(['held', 'ready'])
  // And in between the shell drew nothing: the navbar was never in the document.
  expect(watch?.brand).toBe(false)
})

/**
 * The maintenance hint the core keeps in localStorage
 * (`PROTECTED_MODE_HINT_STORAGE_KEY`, framework/frontend/core/src/connection/
 * maintenanceHint.ts). Restated as a literal because the e2e package builds
 * against no framework source at all; the core exports the same key from its
 * barrel so the two can be held side by side.
 */
const MAINTENANCE_HINT_KEY = 'hilos.protectedMode.hint'

/** Where the watcher below parks its record on the page. */
const BOOT_WATCH_HOOK = 'hilosBootWatch'

/** What the watcher saw the shell do with its first frames. */
interface BootWatch {
  /** Whether the ordinary navbar was ever in the document at all. */
  brand: boolean
  /** Every boot state the marker went through, in order. */
  states: string[]
  /** Why the watcher never got to look, or empty when it did. */
  error: string
}

/**
 * Plant the maintenance hint and start recording what the shell paints.
 *
 * Installed before the application's own scripts, which is the only vantage
 * point a frame that must never exist can be observed from: an assertion after
 * the fact cannot tell "never drawn" from "drawn and gone", and a screenshot
 * proves only that the layout is not there now. A mutation observer sees both.
 *
 * The hint is what a browser would already be carrying, having met this node's
 * maintenance once before; planting it is how a cold load reaches the state the
 * fix is about, without a first visit spent warming it up.
 *
 * @param page The Playwright page, before anything is opened on it.
 */
async function watchFirstFrame(page: Page): Promise<void> {
  await page.addInitScript(
    ([hintKey, hook]: string[]) => {
      window.localStorage.setItem(hintKey, '1')
      const watch = { brand: false, states: [] as string[], error: '' }
      Object.defineProperty(window, hook, { value: watch })
      const look = (): void => {
        if (document.querySelector('[data-id="nav-brand"]') !== null) {
          watch.brand = true
        }
        const state = document
          .querySelector('[data-id="hilos-boot-state"]')
          ?.getAttribute('data-state')
        if (state != null && watch.states.at(-1) !== state) {
          watch.states.push(state)
        }
      }
      try {
        // The document, not `document.documentElement`: an init script runs
        // before the parser has built the root element, so that property is
        // still null here and observing it throws. The document is a node from
        // the start, and `subtree` reaches everything the parser adds under it.
        new MutationObserver(look).observe(document, {
          attributes: true,
          childList: true,
          subtree: true,
        })
        look()
      } catch (cause) {
        // A watcher that dies quietly reports a layout it never looked for as
        // absent, which is this case passing without having tested anything.
        watch.error = String(cause)
      }
    },
    [MAINTENANCE_HINT_KEY, BOOT_WATCH_HOOK],
  )
}

/**
 * Read back what the watcher saw, or null when it never ran.
 *
 * Null is worth telling apart from an empty record: a watcher that failed to
 * install would report a layout it never looked for as absent, and the case
 * would pass without having tested anything.
 *
 * @param page The Playwright page the watcher was installed on.
 */
function readFirstFrameWatch(page: Page): Promise<BootWatch | null> {
  return page.evaluate(
    (hook) =>
      (window as unknown as Record<string, BootWatch | undefined>)[hook] ?? null,
    BOOT_WATCH_HOOK,
  )
}
