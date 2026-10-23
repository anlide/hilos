import type { Expect, Page } from '@playwright/test'

/**
 * Check the signed-in desktop shell after its live page has answered.
 * The user slot keeps each project's profile id and its existing bell order.
 *
 * @param expect The spec's Playwright assertions.
 * @param page The settled page showing the shell.
 * @param profileId The project's profile link data-id.
 * @returns The announced build text, for comparison after SPA navigation.
 */
export async function expectDesktopShell(
  expect: Expect,
  page: Page,
  profileId: string,
): Promise<string> {
  const controls = [
    'nav-theme',
    'nav-admin',
    'hilos-notification-toggle',
    profileId,
  ]
  if (await page.getByTestId('legal-reconsent-icon').isVisible()) {
    controls.push('legal-reconsent-icon')
  }
  controls.push('nav-logout', 'conn-state')

  for (const id of controls) {
    await expect(page.getByTestId(id)).toBeVisible()
  }
  const selector = controls.map((id) => `[data-id="${id}"]`).join(', ')
  await expect
    .poll(() =>
      page
        .locator(selector)
        .evaluateAll((elements) =>
          elements.map((element) => element.getAttribute('data-id')),
        ),
    )
    .toEqual(controls)
  await expect(page.getByTestId('conn-state')).toHaveJSProperty(
    'nextElementSibling',
    null,
  )
  await expect(page.getByTestId('nav-toggle')).toBeHidden()

  const build = page.getByTestId('footer-build')
  await expect(build).toBeVisible()
  await expect(build).toHaveText(/^\s*build\s+\S+\s*$/)
  return (await build.innerText()).trim()
}
