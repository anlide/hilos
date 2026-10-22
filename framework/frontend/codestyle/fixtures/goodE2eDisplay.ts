// Negative sample: screens E2E-LIGHT-DESKTOP leaves alone. The light theme, a
// desktop width from 1024 up, the viewport the config gives and a spec puts back,
// a change of height alone, and dark or narrow words that set no screen. Values
// this file cannot prove are left alone too, since the rule does not guess: an
// import, a call, a property read, a parameter, a let, a device descriptor, a
// spread written after the width, a const that leads back to itself. A loop
// that does not hand its variable to a width is no loop over widths: a loop over
// pages or people, over heights alone, over palettes kept as data or a palette
// list without dark, and one that reaches a width only through a call, an import
// or options that may hold no viewport. Only the fixture test reads this file;
// it sits outside the demo roots the guard scans.
import { defineConfig, devices, test } from '@playwright/test'
import type { Browser, Expect, Page } from '@playwright/test'

import {
  chooseHeaderTheme,
  chooseProfileTheme,
  emulateSystemTheme,
  expectThemeMode,
  THEME_PICK_STORAGE_KEY,
  THEME_TEST_POSITIONS,
} from '../../e2e/theme.js'

/** A narrow width at the top, which the functions below shadow. */
const width = 375

/** A phone that a desktop width written after it overrides. */
const PHONE = { width: 375, height: 812 } as const

/** Sizes kept as data, read below through a property. */
const SCREENS = { phone: PHONE }

/** A row of data that merely carries the words. */
export const NARROW_DARK_ROW = { width, colorScheme: 'dark' }

/** A config-shaped object that is no config. */
export const NOT_A_CONFIG = { use: { colorScheme: 'dark' } }

/** A width this file declares and does not give. */
declare function narrowestWidth(): number

/** A width per pass that this file declares and does not give. */
declare function widthFor(pass: number): number

/** A `use()` that is not test's. */
declare const pool: { use(options: object): void }

/** A `newContext()` that is no method. */
declare function newContext(options: object): Promise<void>

test.use({ ...devices['Desktop Chrome'] })

test.use({
  viewport: { width: 375, height: 667 },
  ...devices['Desktop Chrome'],
})

/** The desktop widths, the boundary itself included, and a height alone. */
export async function keepsTheDesktop(page: Page): Promise<void> {
  await page.setViewportSize({ width: 1024, height: 768 })
  await page.setViewportSize({ width: 1280, height: 720 })
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.setViewportSize({ width: 1280, height: 200 })
  await page.setViewportSize({ ...PHONE, width: 1280 })
}

/** A parameter and a nearer const shadow the narrow width at the top. */
export async function shadowsTheTop(page: Page, width: number): Promise<void> {
  await page.setViewportSize({ width, height: 800 })
  {
    const width = 1280
    await page.setViewportSize({ width, height: 800 })
  }
}

/** The viewport the config gives, read back and put back. */
export async function inheritsTheConfig(
  browser: Browser,
  page: Page,
): Promise<void> {
  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  await page.setViewportSize({ width: 1024, height: 500 })
  await page.setViewportSize(desktop)
  await page.setViewportSize({ ...desktop, height: 400 })
  await browser.newContext({ viewport: test.info().project.use.viewport })
  const { viewport } = test.info().project.use
  await browser.newContext({ viewport, colorScheme: 'light' })
}

/** The light theme, the system position and the reset, through every place. */
export async function staysLight(
  expect: Expect,
  browser: Browser,
  page: Page,
): Promise<void> {
  await browser.newPage({ colorScheme: 'light' })
  await page.emulateMedia({ colorScheme: 'light' })
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await emulateSystemTheme(page, 'light')
  await chooseHeaderTheme(expect, page, 'system')
  await chooseProfileTheme(expect, page, null)
}

/** Dark read back, stored as data, said in prose or by a look-alike. */
export async function mentionsDark(expect: Expect, page: Page): Promise<void> {
  // await page.emulateMedia({ colorScheme: 'dark' })
  await expectThemeMode(expect, page, 'dark')
  await page.evaluate(
    ([pick]) => localStorage.setItem(pick, 'dark'),
    [THEME_PICK_STORAGE_KEY],
  )
  await expect(page.getByText('setViewportSize({ width: 375 })')).toHaveCount(0)
  pool.use({ colorScheme: 'dark' })
  await newContext({ viewport: { width: 375, height: 667 } })
}

/** Values the file cannot prove: a let, a call, a property read, an import. */
export async function cannotTell(expect: Expect, page: Page): Promise<void> {
  let changing = 375
  if (page.isClosed()) {
    changing = 1280
  }
  await page.setViewportSize({ width: changing, height: 800 })
  await page.setViewportSize({ width: narrowestWidth(), height: 800 })
  await page.setViewportSize({ width: SCREENS.phone.width, height: 800 })
  await chooseHeaderTheme(expect, page, THEME_TEST_POSITIONS[1])
}

/** A const that leads back to itself ends unread rather than running on. */
export async function readsACycle(page: Page): Promise<void> {
  const first: { width: number; height: number } = second
  const second: { width: number; height: number } = first
  await page.setViewportSize(first)
  await page.setViewportSize(second)
}

/** Loops over pages and people, over heights, and over a width overridden. */
export async function loopsOverOtherData(
  browser: Browser,
  page: Page,
  states: readonly string[],
): Promise<void> {
  for (const path of ['/', '/about']) {
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.goto(path)
  }
  for (const state of states) {
    await browser.newContext({ storageState: state })
  }
  await Promise.all(states.map(() => browser.newPage()))
  for (const height of [600, 900]) {
    await page.setViewportSize({ width: 1280, height })
  }
  for (const size of [PHONE, { width: 390, height: 844 }]) {
    await page.setViewportSize({ ...size, width: 1280 })
  }
}

/** Palettes walked where no screen is set, or without dark. */
export async function loopsOverPalettesAsData(
  expect: Expect,
  page: Page,
): Promise<void> {
  const palettes = ['light', 'dark'] as const
  for (const palette of palettes) {
    await expectThemeMode(expect, page, palette)
  }
  palettes.forEach((palette) => localStorage.setItem('palette', palette))
  for (const mode of ['light', 'system'] as const) {
    await chooseHeaderTheme(expect, page, mode)
  }
}

/** A loop reaching a width only through a call, an import or bare options. */
export async function loopsThroughWhatCannotBeRead(
  browser: Browser,
  page: Page,
  people: readonly { options: object }[],
): Promise<void> {
  for (let pass = 0; pass < 3; pass += 1) {
    await page.setViewportSize({ width: widthFor(pass), height: 800 })
  }
  for (const name of ['Desktop Chrome', 'Desktop Firefox']) {
    await browser.newContext({ ...devices[name] })
    await page.setViewportSize(devices[name].viewport)
  }
  for (const person of people) {
    await browser.newContext(person.options)
    await browser.newContext({ ...person.options })
  }
}

export default defineConfig({
  use: { colorScheme: 'light', viewport: { width: 1280, height: 720 } },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'wide', use: { viewport: { width: 1440, height: 900 } } },
  ],
})
