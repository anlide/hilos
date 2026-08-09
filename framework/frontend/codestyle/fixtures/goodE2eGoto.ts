// Negative sample: nothing here navigates behind the rule's back. The wrappers
// are what a spec calls, a method that merely shares the name `goto` on some
// other object is not Playwright's navigation, and a mention of it in prose or in
// a string is not a call at all.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** The shape of the Playwright page this fixture pretends to drive. */
interface FakePage {
  goto(path: string): Promise<void>
}

/** The wrapper a spec is supposed to call, standing in for the real helper. */
declare function gotoPage(page: FakePage, path: string): Promise<void>

/** The wrapper for a page the server is expected to refuse. */
declare function gotoRefusedPage(page: FakePage, path: string): Promise<void>

/** A spec body doing it the prescribed way. */
export async function opensAPage(page: FakePage): Promise<void> {
  await gotoPage(page, '/hilos/settings')
}

/** A spec body asserting a refusal, which waits for the refusal itself. */
export async function opensARefusedPage(page: FakePage): Promise<void> {
  await gotoRefusedPage(page, '/hilos/admin_users')
}

/** The name in a string is not a call: nothing navigates here. */
export const NOT_A_CALL = 'page.goto is what this rule forbids'
