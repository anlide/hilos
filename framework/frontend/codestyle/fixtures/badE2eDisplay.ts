// Deliberately narrow and dark screens at every place E2E-LIGHT-DESKTOP reads:
// the page's viewport, a context or a page opened with options, test.use(), the
// config's use and its projects' use, the media emulation and the theme helpers
// of the shared toolbox. The values arrive as literals and through local consts,
// shorthands and spreads. Loops over widths — for, for…of, forEach() and map(),
// desktop widths included — hand their variable on directly and through a
// const, an object, a spread, a member, a pattern and an index; loops over the
// palettes hand dark on. One site with two reasons owes one line, two sites on
// one line owe two. Only the fixture test reads this file; it sits outside the
// demo roots the guard scans, and its path is no theme spec, so its dark is
// refused too.
import { defineConfig, devices, test } from '@playwright/test'
import type { Browser, Expect, Page, ViewportSize } from '@playwright/test'

import {
  chooseHeaderTheme,
  chooseProfileTheme,
  emulateSystemTheme,
} from '../../e2e/theme.js'

/** A desktop width at the top, which a narrow one below shadows. */
const width = 1440

/** A phone, written once and handed on by name. */
const PHONE = { width: 375, height: 812 } as const

/** A desktop that a narrower width written after it overrides. */
const DESKTOP = { width: 1280, height: 720 } satisfies {
  width: number
  height: number
}

/** The dark media, named once. */
const DARK = { colorScheme: 'dark' } as const

/** A project written before the config that lists it. */
const narrowProject = {
  name: 'narrow',
  use: { viewport: { width: 800, height: 600 } },
}

test.use({
  ...devices['Desktop Chrome'],
  viewport: { width: 390, height: 844 },
})

/** A literal, the boundary, a const, a sign and a shadowing shorthand. */
export async function narrowsThePage(page: Page): Promise<void> {
  await page.setViewportSize({ width: 375, height: 667 })
  await page.setViewportSize({ width: 1023, height: 768 })
  await page.setViewportSize(PHONE)
  await page.setViewportSize({ width: -(-800), height: 600 })
  await page.setViewportSize({ width, height: 900 })
  {
    const width = 412
    await page.setViewportSize({ width, height: 915 })
  }
}

/** Local spreads override in the order they are written. */
export async function overridesTheDesktop(page: Page): Promise<void> {
  await page.setViewportSize({ ...DESKTOP, width: 600 })
  await page.setViewportSize({ width: 1280, height: 720, ...PHONE })
}

/** Contexts and pages opened narrow, dark, or both at one site. */
export async function opensNarrowAndDark(browser: Browser): Promise<void> {
  await browser.newContext({ viewport: { width: 375, height: 667 } })
  await browser.newPage({ colorScheme: 'dark' })
  await browser.newContext({ viewport: PHONE, colorScheme: 'dark' })
}

/** The media and every theme helper handed dark. */
export async function choosesDark(expect: Expect, page: Page): Promise<void> {
  const mode = 'dark'
  await page.emulateMedia(DARK)
  await emulateSystemTheme(page, mode)
  await chooseHeaderTheme(expect, page, 'dark')
  await chooseProfileTheme(expect, page, 'dark')
}

/** Two independent sites on one line. */
export async function twoOnOneLine(page: Page): Promise<void> {
  await Promise.all([page.setViewportSize(PHONE), page.emulateMedia(DARK)])
}

/** Desktop widths only, the boundary among them, walked below. */
const WIDTHS = [1024, 1280, 1440]

/** Screens walked below, a phone among them. */
const SIZES = [
  { width: 375, height: 812 },
  { width: 1440, height: 900 },
]

/** The two palettes, walked below. */
const PALETTES = ['light', 'dark'] as const

/** Every kind of loop over widths, desktop widths too. */
export async function loopsOverWidths(page: Page): Promise<void> {
  for (const width of [1280, 1440]) {
    await page.setViewportSize({ width, height: 800 })
  }
  for (let width = 1024; width <= 1440; width += 208) {
    await page.setViewportSize({ width, height: 800 })
  }
  for (let index = 0; index < WIDTHS.length; index += 1) {
    await page.setViewportSize({ width: WIDTHS[index], height: 800 })
  }
  WIDTHS.forEach(async (width) => {
    await page.setViewportSize({ width, height: 800 })
  })
  await Promise.all(
    WIDTHS.map((width) => page.setViewportSize({ width: +width, height: 1 })),
  )
}

/** A loop's width handed on: a const, an object, a member, a pattern. */
export async function handsTheLoopOn(
  browser: Browser,
  page: Page,
): Promise<void> {
  for (const width of WIDTHS) {
    const viewport = { width, height: 800 }
    await page.setViewportSize(viewport)
    const taller = { ...viewport, height: 900 }
    await browser.newContext({ viewport: taller })
  }
  for (const size of SIZES) {
    await page.setViewportSize(size)
    await page.setViewportSize({ width: size.width, height: 600 })
  }
  for (const { width } of SIZES) {
    await page.setViewportSize({ width, height: 600 })
  }
  await Promise.all(SIZES.map((size) => browser.newPage({ viewport: size })))
}

/** Sizes the file cannot read are still a loop's. */
export async function walksUnknownSizes(
  page: Page,
  sizes: readonly ViewportSize[],
): Promise<void> {
  for (const size of sizes) {
    await page.setViewportSize({ ...size, height: 700 })
  }
  sizes.forEach((size) => page.setViewportSize(size))
}

/** The palettes walked into a context, a helper, test.use() and whole options. */
export async function loopsOverPalettes(
  browser: Browser,
  page: Page,
): Promise<void> {
  for (const palette of PALETTES) {
    await browser.newContext({ colorScheme: palette })
    await emulateSystemTheme(page, palette)
  }
  PALETTES.forEach((colorScheme) => test.use({ colorScheme }))
  for (const palette of PALETTES) {
    for (const size of SIZES) {
      await browser.newContext({ viewport: size, colorScheme: palette })
    }
  }
  for (const options of [{ viewport: PHONE }, { colorScheme: 'dark' }]) {
    await browser.newContext(options)
  }
}

export default defineConfig({
  use: { colorScheme: 'dark' },
  projects: [
    narrowProject,
    { name: 'phone', use: { ...devices['Desktop Chrome'], viewport: PHONE } },
  ],
})
