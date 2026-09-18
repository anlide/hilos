// Negative sample: nothing here navigates behind the rule's back. The wrappers
// are what a spec calls, a stand resident's screen is addressed from the stand's
// base and has no subscription to wait for, a method that merely shares the name
// `goto` on some other object is not Playwright's navigation, and a mention of it
// in prose or in a string is not a call at all.
//
// This file sits outside every scanned root, so only the fixture test reads it.
// The module it imports is not there: the checker reads how the file is written
// and resolves nothing.
import { STAND_GATEWAY_URL } from './helpers/gateway'

/** The shape of the Playwright page this fixture pretends to drive. */
interface FakePage {
  goto(path: string): Promise<void>
}

/** The wrapper a spec is supposed to call, standing in for the real helper. */
declare function gotoPage(
  page: FakePage,
  path: string,
  expected?: string,
): Promise<void>

/** The settled state a spec names when the refusal itself is the point. */
declare const PAGE_REFUSED: 'error'

/** A spec body doing it the prescribed way. */
export async function opensAPage(page: FakePage): Promise<void> {
  await gotoPage(page, '/hilos/settings')
}

/** A spec body asserting a refusal, which waits for the refusal itself. */
export async function opensARefusedPage(page: FakePage): Promise<void> {
  await gotoPage(page, '/hilos/admin_users', PAGE_REFUSED)
}

/** A resident's screen, written from the stand's base inside a template. */
export async function opensAStandScreen(
  page: FakePage,
  query: string,
): Promise<void> {
  await page.goto(`${STAND_GATEWAY_URL}/oauth/github/authorize?${query}`)
}

/** The same base, joined by a plus and standing alone. */
export async function opensTheStandItself(page: FakePage): Promise<void> {
  await page.goto(STAND_GATEWAY_URL + '/oauth/google/authorize')
  await page.goto(STAND_GATEWAY_URL)
}

/** The name in a string is not a call: nothing navigates here. */
export const NOT_A_CALL = 'page.goto is what this rule forbids'
