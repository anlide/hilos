import type {
  BrowserContextOptions,
  Expect,
  Fixtures,
  Page,
  PlaywrightTestArgs,
  PlaywrightTestOptions,
  PlaywrightWorkerArgs,
  PlaywrightWorkerOptions,
  TestInfo,
  TestType,
} from '@playwright/test'

import { grantAdminToSelf } from './adminGrant.js'
import { THEME_DEFAULT_KEY } from './appearance.js'
import { cleanupFixtures, type TestCleanup } from './cleanupFixtures.js'
import { typeInto } from './input.js'
import { expectPageReady, gotoPage, PAGE_READY } from './page.js'
import { clearCustomSetting, setCustomSetting } from './settings.js'
import { shownByTestId } from './table.js'
import {
  THEME_PICK_STORAGE_KEY,
  THEME_TEST_LABELS,
  THEME_TEST_POSITIONS,
  chooseHeaderTheme,
  emulateSystemTheme,
  expectThemeMode,
  type ThemeTestPosition,
} from './theme.js'

interface GuestThemeFixtures {
  guestThemeCleanup: TestCleanup
}

type GuestThemeBody = (
  args: Pick<PlaywrightTestArgs, 'context' | 'page'> &
    Pick<PlaywrightWorkerArgs, 'browser'> &
    Pick<PlaywrightTestOptions, 'baseURL'> &
    GuestThemeFixtures,
  testInfo: TestInfo,
) => Promise<void>

type GuestThemeCase = [title: string, body: GuestThemeBody]

interface GuestThemeCases {
  sync: GuestThemeCase
  reset: GuestThemeCase
  system: GuestThemeCase
  menu: GuestThemeCase
}

/**
 * Share the guest journeys while each demo registers its own reporting locations.
 * The supplied runner retains its local fixtures, options and adaptive budget.
 */
export function registerGuestThemeScenarios<
  TestArgs extends PlaywrightTestArgs & PlaywrightTestOptions,
  WorkerArgs extends PlaywrightWorkerArgs & PlaywrightWorkerOptions,
>(
  test: TestType<TestArgs, WorkerArgs>,
  expect: Expect,
  register: (
    themeTest: TestType<TestArgs & GuestThemeFixtures, WorkerArgs>,
    cases: GuestThemeCases,
  ) => void,
  adminContextOptions?: (testInfo: TestInfo) => BrowserContextOptions,
): void {
  const fixtures: Fixtures<GuestThemeFixtures, object, PlaywrightTestArgs> = {
    guestThemeCleanup: cleanupFixtures,
  }
  const themeTest = test.extend<GuestThemeFixtures>(fixtures)
  const cases: GuestThemeCases = {
    sync: [
      'guest choices synchronize both ways and survive reload and a new tab',
      async ({ context, page }) => {
        await emulateSystemTheme(page, 'light')
        await gotoPage(expect, page, '/', PAGE_READY)
        const other = await context.newPage()
        await emulateSystemTheme(other, 'light')
        await gotoPage(expect, other, '/', PAGE_READY)

        // The fresh browser follows the catalog default. Choosing that same row is
        // still a choice, and native Enter activation must close and return focus.
        const toggle = page.getByTestId('nav-theme')
        await expectGuestThemeHeader(expect, page, 'system', true)
        await expectThemeMode(expect, page, 'light')
        await toggle.focus()
        await toggle.press('ArrowDown')
        await expect(page.getByTestId('nav-theme-option-light')).toBeFocused()
        await expect(
          page.getByTestId('nav-theme-option-system'),
        ).toHaveAccessibleName(`${THEME_TEST_LABELS.system} default`)
        await page.getByTestId('nav-theme-option-light').press('End')
        await expect(page.getByTestId('nav-theme-default')).toBeFocused()
        await page.getByTestId('nav-theme-default').press('ArrowUp')
        await expect(page.getByTestId('nav-theme-option-system')).toBeFocused()
        await page.getByTestId('nav-theme-option-system').press('Enter')
        await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
        await expect(toggle).toBeFocused()
        expect(
          await page.evaluate(
            (key) => localStorage.getItem(key),
            THEME_PICK_STORAGE_KEY,
          ),
        ).toBe('system')
        await toggle.click()
        await expect(
          page.getByTestId('nav-theme-option-system'),
        ).toHaveAccessibleName(THEME_TEST_LABELS.system)
        await toggle.press('Escape')
        await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
        await expect(toggle).toBeFocused()
        await expectThemeMode(expect, page, 'light')

        await chooseHeaderTheme(expect, page, 'dark')
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark')
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'dark')

        await chooseHeaderTheme(expect, other, 'light')
        await expectThemeMode(expect, other, 'light')
        await expectGuestThemeHeader(expect, other, 'light')
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'light')

        await chooseHeaderTheme(expect, page, 'dark')
        expect(
          await page.evaluate(
            (key) => localStorage.getItem(key),
            THEME_PICK_STORAGE_KEY,
          ),
        ).toBe('dark')
        await page.reload()
        await expectPageReady(expect, page)
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark')

        const newlyOpened = await context.newPage()
        await emulateSystemTheme(newlyOpened, 'light')
        await gotoPage(expect, newlyOpened, '/', PAGE_READY)
        await expectThemeMode(expect, newlyOpened, 'dark')
        await expectGuestThemeHeader(expect, newlyOpened, 'dark')
      },
    ],
    reset: [
      'guest reset clears both tabs and follows later site defaults across reload',
      async (
        { browser, context, page, baseURL, guestThemeCleanup },
        testInfo,
      ) => {
        const adminContext = await browser.newContext({
          baseURL,
          ignoreHTTPSErrors: true,
          ...adminContextOptions?.(testInfo),
        })
        guestThemeCleanup.defer(() => adminContext.close())
        const admin = await adminContext.newPage()
        await emulateSystemTheme(admin, 'light')
        const other = await context.newPage()
        await emulateSystemTheme(page, 'light')
        await emulateSystemTheme(other, 'dark')
        await gotoPage(expect, page, '/', PAGE_READY)
        await gotoPage(expect, other, '/', PAGE_READY)
        await chooseHeaderTheme(expect, page, 'dark')
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark')
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'dark')
        await chooseHeaderTheme(expect, page, null)
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'system', true)
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'system', true)
        for (const tab of [page, other]) {
          expect(
            await tab.evaluate(
              (key) => localStorage.getItem(key),
              THEME_PICK_STORAGE_KEY,
            ),
          ).toBeNull()
        }

        await grantAdminToSelf(expect, admin)
        await gotoPage(expect, admin, '/hilos/settings', PAGE_READY)
        const search = admin.getByTestId('hilos-table-search')
        await typeInto(search, THEME_DEFAULT_KEY)
        await expect(
          admin.getByTestId(`hilos-table-row-${THEME_DEFAULT_KEY}`),
        ).toBeVisible()
        const edit = shownByTestId(
          admin,
          `hilos-settings-edit-${THEME_DEFAULT_KEY}`,
        )
        await expect(edit).toHaveAccessibleName('Set custom value')
        let pendingWrite: Promise<void> | undefined
        guestThemeCleanup.defer(async () => {
          // A timeout can release the test while its UI write is still settling.
          // Its error belongs to the body; cleanup must still reopen and restore.
          await Promise.allSettled(pendingWrite ? [pendingWrite] : [])
          await gotoPage(expect, admin, '/hilos/settings', PAGE_READY)
          await typeInto(
            admin.getByTestId('hilos-table-search'),
            THEME_DEFAULT_KEY,
          )
          await expect(edit).toBeVisible()
          await clearCustomSetting(admin, THEME_DEFAULT_KEY)
          await expect(edit).toHaveAccessibleName('Set custom value')
        })

        // Both open guests must visibly change when the site switches to Dark.
        await emulateSystemTheme(other, 'light')
        await expectThemeMode(expect, page, 'light')
        await expectThemeMode(expect, other, 'light')
        pendingWrite = setCustomSetting(admin, THEME_DEFAULT_KEY, 'dark')
        await pendingWrite
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark', true)
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'dark', true)
        await page.reload()
        await expectPageReady(expect, page)
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark', true)
        await chooseHeaderTheme(expect, other, null)
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark', true)
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'dark', true)
        pendingWrite = setCustomSetting(admin, THEME_DEFAULT_KEY, 'light')
        await pendingWrite
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'light', true)
        await expectThemeMode(expect, other, 'light')
        await expectGuestThemeHeader(expect, other, 'light', true)
        for (const tab of [page, other]) {
          expect(
            await tab.evaluate(
              (key) => localStorage.getItem(key),
              THEME_PICK_STORAGE_KEY,
            ),
          ).toBeNull()
        }
      },
    ],
    system: [
      'System follows each guest tab media while explicit choices ignore it',
      async ({ context, page }) => {
        await emulateSystemTheme(page, 'light')
        await gotoPage(expect, page, '/', PAGE_READY)
        const other = await context.newPage()
        await emulateSystemTheme(other, 'dark')
        await gotoPage(expect, other, '/', PAGE_READY)

        await chooseHeaderTheme(expect, page, 'system')
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'system')
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'system')
        await emulateSystemTheme(page, 'dark')
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'system')
        await emulateSystemTheme(page, 'light')
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'system')
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'system')

        await chooseHeaderTheme(expect, page, 'dark')
        await emulateSystemTheme(page, 'dark')
        await emulateSystemTheme(page, 'light')
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark')
        await expectThemeMode(expect, other, 'dark')
        await expectGuestThemeHeader(expect, other, 'dark')

        await chooseHeaderTheme(expect, other, 'light')
        await emulateSystemTheme(other, 'light')
        await emulateSystemTheme(other, 'dark')
        await expectThemeMode(expect, other, 'light')
        await expectGuestThemeHeader(expect, other, 'light')
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'light')
      },
    ],
    menu: [
      'a guest can choose and reset from the header menu',
      async ({ page }) => {
        await gotoPage(expect, page, '/', PAGE_READY)
        await page.getByTestId('nav-theme').click()
        await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
        await page.getByTestId('nav-theme-option-dark').click()
        await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
        await expectThemeMode(expect, page, 'dark')
        await expectGuestThemeHeader(expect, page, 'dark')
        const toggle = page.getByTestId('nav-theme')
        await toggle.press('ArrowUp')
        const reset = page.getByTestId('nav-theme-default')
        await expect(reset).toBeFocused()
        await expect(reset).toBeVisible()
        await expect(reset).toBeEnabled()
        await expect(reset).toHaveRole('menuitem')
        await expect(reset).not.toHaveAttribute('aria-checked')
        await reset.press('Enter')
        await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
        await expect(toggle).toBeFocused()
        await expectThemeMode(expect, page, 'light')
        await expectGuestThemeHeader(expect, page, 'system', true)
      },
    ],
  }
  register(themeTest, cases)
}

/** Check the guest menu's accessible state without depending on its decoration. */
async function expectGuestThemeHeader(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
  isDefault = false,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await expect(toggle).toHaveAccessibleName(
    `Theme: ${THEME_TEST_LABELS[position]}`,
  )
  await toggle.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  for (const row of THEME_TEST_POSITIONS) {
    const option = page.getByTestId(`nav-theme-option-${row}`)
    await expect(option).toHaveRole('menuitemradio')
    await expect(option).toHaveAttribute(
      'aria-checked',
      String(row === position),
    )
    await expect(option).toHaveAccessibleName(
      `${THEME_TEST_LABELS[row]}${row === position && isDefault ? ' default' : ''}`,
    )
  }
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeVisible()
  await expect(reset).toBeEnabled()
  await expect(reset).toHaveRole('menuitem')
  await expect(reset).toHaveAccessibleName('Use site default')
  await expect(reset).not.toHaveAttribute('aria-checked')
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
}
