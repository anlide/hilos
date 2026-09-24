// Deliberately broken sample: every navigation below goes through Playwright's
// own `goto`, so E2E-PAGE-GOTO must report each one — the plain call, the one on
// a second page object of a two-window spec, the one buried in a helper, and the
// three that only look like a stand screen: the stand's base in the middle of a
// product address, the base hidden behind a call, and a base the file made up.
//
// This file sits outside every scanned root, so only the fixture test reads it.
// The module it imports is the real one, but the checker still reads only how
// the file is written and resolves nothing.
import { STAND_GATEWAY_URL } from '../../scripts/standGateway.mjs'

/** The shape of the Playwright page this fixture pretends to drive. */
interface FakePage {
  goto(path: string): Promise<void>
}

/** A stand address hidden behind a call, which the checker does not follow. */
declare function consentScreen(): string

/** A stand address the file wrote itself instead of importing it. */
const PROVIDER_URL = 'https://stand-gateway:18000'

/** A spec body that navigates the page it was handed. */
export async function opensAPage(page: FakePage): Promise<void> {
  await page.goto('/hilos/settings')
}

/** A two-window spec, where the second page is navigated as well. */
export async function opensASecondWindow(
  page: FakePage,
  secondPage: FakePage,
): Promise<void> {
  await page.goto('/')
  await secondPage.goto('/hilos/users')
}

/** A helper hiding the navigation one call deeper. */
export async function openSettings(page: FakePage): Promise<void> {
  await page.goto('/hilos/settings')
}

/** Product pages that only mention the stand, or name it in a way not read. */
export async function opensLookAlikes(page: FakePage): Promise<void> {
  await page.goto(`/hilos/login?next=${STAND_GATEWAY_URL}`)
  await page.goto(consentScreen())
  await page.goto(`${PROVIDER_URL}/oauth/github/authorize`)
}
