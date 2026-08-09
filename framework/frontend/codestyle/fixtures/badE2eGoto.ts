// Deliberately broken sample: every navigation below goes through Playwright's
// own `goto`, so E2E-PAGE-GOTO must report each one — the plain call, the one on
// a second page object of a two-window spec, and the one buried in a helper.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** The shape of the Playwright page this fixture pretends to drive. */
interface FakePage {
  goto(path: string): Promise<void>
}

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
