import type { Expect, Page } from '@playwright/test'

/**
 * The adopted user head resolves from a cold page's own permitted detail.
 * Unbuilt user pages offer no tab row or links.
 *
 * @param expect The spec's assertion function.
 * @param page The page viewing the Overview.
 * @param userId The person the route names.
 * @param name The person's committed display name.
 * @param appName The application's browser-title suffix.
 */
export async function expectHilosUserOverview(
  expect: Expect,
  page: Page,
  userId: number,
  name: string,
  appName: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await expect(page.getByTestId('hilos-user-empty')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head')).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(name)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  await expect(page.getByTestId('hilos-page-head-block')).toContainText(
    `#${userId}`,
  )
  await expect(page).toHaveTitle(`${name} · ${appName}`)
  await expect(page.getByTestId('page-title')).toHaveText(
    `${name} · ${appName}`,
  )
  const breadcrumb = page.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  await expect(breadcrumb.locator('li')).toHaveText(['Hilos', 'Users', name])
  await expect(
    breadcrumb.getByTestId('hilos-breadcrumb-hilos'),
  ).toHaveAttribute('href', '/hilos')
  await expect(
    breadcrumb.getByTestId('hilos-breadcrumb-hilos_users'),
  ).toHaveAttribute('href', '/hilos/users')
  await expect(breadcrumb.locator('[aria-current="page"]')).toHaveText(name)
  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-page-tab-/)).toHaveCount(0)
  await expect(
    page.locator('a[href=""], a[href="#"], a:not([href])'),
  ).toHaveCount(0)
  await expect(page.locator(`a[href^="/hilos/user/${userId}/"]`)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Profile', exact: true }),
  ).toBeVisible()
}

/**
 * Guard the Overview's heading hierarchy and the Profile rename keyboard path.
 *
 * @param expect The spec's assertion function.
 * @param page The ready Overview, with the rename control available.
 */
export async function expectHilosUserOverviewAccessibility(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  const headings = await page
    .getByRole('heading')
    .evaluateAll((nodes) => nodes.map((node) => Number(node.tagName.slice(1))))
  expect(headings[0]).toBe(1)
  for (let index = 1; index < headings.length; index++) {
    expect(headings[index]).toBeLessThanOrEqual(headings[index - 1]! + 1)
    expect(headings[index]).toBeGreaterThan(1)
  }
  await expect(page.getByTestId('hilos-breadcrumb')).toHaveRole('navigation')
  await expect(page.getByTestId('hilos-breadcrumb')).toHaveAccessibleName(
    'Breadcrumb',
  )
  const profile = page
    .getByTestId('hilos-user-detail')
    .locator('section')
    .filter({
      has: page.getByRole('heading', {
        name: 'Profile',
        level: 2,
        exact: true,
      }),
    })
  const rename = profile.getByTestId('hilos-user-edit')
  await expect(rename).toHaveRole('button')
  await expect(rename).toHaveAccessibleName('Change name')
  await rename.focus()
  await expect(rename).toBeFocused()
  await page.keyboard.press('Enter')
  const modal = page.getByTestId('modal')
  const input = page.getByTestId('hilos-user-name-input')
  await expect(modal).toBeVisible()
  await expect(modal).toHaveAttribute('aria-modal', 'true')
  await expect(input).toHaveAccessibleName('Display name')
  await expect(input).toBeFocused()
  // With an unchanged draft Save is disabled. Tab wraps around the enabled
  // Cancel, Close and input controls, keeping the background unreachable.
  await page.getByTestId('hilos-user-cancel').focus()
  await page.keyboard.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(page.getByTestId('hilos-user-cancel')).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(modal).toHaveCount(0)
  await expect(rename).toBeFocused()
}
