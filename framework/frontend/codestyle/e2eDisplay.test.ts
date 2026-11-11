// E2E-LIGHT-DESKTOP under test against its seeded fixtures, and against sources
// written here under real demo paths, since the theme specs and the geometry
// stand specs are named by path.
// The real e2e roots are judged by guard.test.ts against the shared baseline,
// never by a second guard.
import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './e2eDisplay.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The tail every report line carries. */
const SEE =
  ' (see docs/agents/frontend/testing-strategy.md, Light theme, desktop width)'

/** The rest of a line reporting a narrow viewport. */
const NARROW = ` — width below 1024${SEE}`

/** The rest of a line reporting dark outside the theme specs. */
const DARK = ` — dark outside theme specs${SEE}`

/** The rest of a line reporting one site for both. */
const NARROW_AND_DARK = ` — width below 1024, dark outside theme specs${SEE}`

/** The rest of a line reporting a width a loop sets. */
const LOOP = ` — width loop${SEE}`

/** The rest of a line reporting a loop that walks a narrow width too. */
const NARROW_LOOP = ` — width below 1024, width loop${SEE}`

/** The rest of a line reporting one site for all three. */
const NARROW_LOOP_DARK = ` — width below 1024, width loop, dark outside theme specs${SEE}`

/**
 * The theme feature's own specs, written out by hand rather than imported: the
 * exact list is what the rule promises.
 */
const THEME_SPECS = [
  'demo/binance-btc-tracker/tests/e2e/cluster/live/theme.spec.ts',
  'demo/binance-btc-tracker/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/chat/tests/e2e/tests/theme-account.spec.ts',
  'demo/chat/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/chat/tests/e2e/tests/theme-guest.spec.ts',
  'demo/ecommerce-shop/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/ecommerce-shop/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/online-testing/tests/e2e/tests/theme-first-frame.spec.ts',
  'demo/online-testing/tests/e2e/tests/theme-smoke.spec.ts',
  'demo/pmd/tests/e2e/tests/appearance.spec.ts',
  'demo/preferans/tests/e2e/tests/appearance.spec.ts',
  'demo/preferans/tests/e2e/tests/theme-account.spec.ts',
  'demo/preferans/tests/e2e/tests/theme-guest.spec.ts',
  'demo/solar-system-model/tests/e2e/tests/theme-account.spec.ts',
  'demo/solar-system-model/tests/e2e/tests/theme-guest.spec.ts',
  'demo/tasks/tests/e2e/tests/appearance.spec.ts',
  'framework/frontend/e2e/themeGuestScenarios.ts',
]

/**
 * The geometry stand specs, written out by hand rather than imported: the exact
 * list is what the rule promises.
 */
const GEOMETRY_STANDS = ['demo/pmd/tests/e2e/tests/modal-geometry.spec.ts']

/** A dark page on line 1 and a narrow one on line 2. */
const DARK_THEN_NARROW =
  "await page.emulateMedia({ colorScheme: 'dark' })\n" +
  'await page.setViewportSize({ width: 375, height: 667 })\n'

/** The palettes walked on line 2 and desktop widths walked on line 5. */
const PALETTES_THEN_WIDTHS =
  "for (const mode of ['light', 'dark'] as const) {\n" +
  '  await emulateSystemTheme(page, mode)\n' +
  '}\n' +
  'for (const width of [1280, 1440]) {\n' +
  '  await page.setViewportSize({ width, height: 800 })\n' +
  '}\n'

/**
 * The stand spec's loop over the three screens of the norm, whose context is
 * set on line 8.
 *
 * @param colorScheme The palette the context is given
 * @returns The source
 */
function screensLoop(colorScheme: string): string {
  return (
    'const SCREENS = [\n' +
    '  { width: 1440, height: 900 },\n' +
    '  { width: 768, height: 1024 },\n' +
    '  { width: 375, height: 667 },\n' +
    '] as const\n' +
    'for (const screen of SCREENS) {\n' +
    '  test.describe(`modal geometry at ${screen.width}`, () => {\n' +
    `    test.use({ viewport: screen, colorScheme: '${colorScheme}' })\n` +
    '  })\n' +
    '}\n'
  )
}

/** The loop over the three screens, then the phone's screen set on line 11. */
const STAND_SCREENS =
  screensLoop('light') +
  'await page.setViewportSize({ width: 375, height: 667 })\n'

/**
 * Runs the checker over a fixture exactly as the repository scan would.
 *
 * @param name File name inside the fixture directory
 * @returns Reported lines, in source order
 */
function reportFixture(name: string): string[] {
  const relativePath = `${FIXTURES}/${name}`

  return checkSource(
    relativePath,
    readFileSync(join(REPOSITORY_ROOT, relativePath), 'utf8'),
  )
}

it('reports each narrow or dark site the bad fixture seeds, once and in source order', () => {
  const bad = `E2E-LIGHT-DESKTOP ${FIXTURES}/badE2eDisplay.ts`

  expect(reportFixture('badE2eDisplay.ts')).toEqual([
    `${bad}:39${NARROW}`,
    `${bad}:42${NARROW}`,
    `${bad}:49${NARROW}`,
    `${bad}:50${NARROW}`,
    `${bad}:51${NARROW}`,
    `${bad}:52${NARROW}`,
    `${bad}:56${NARROW}`,
    `${bad}:62${NARROW}`,
    `${bad}:63${NARROW}`,
    `${bad}:68${NARROW}`,
    `${bad}:69${DARK}`,
    `${bad}:70${NARROW_AND_DARK}`,
    `${bad}:76${DARK}`,
    `${bad}:77${DARK}`,
    `${bad}:78${DARK}`,
    `${bad}:79${DARK}`,
    `${bad}:84${NARROW}`,
    `${bad}:84${DARK}`,
    `${bad}:102${LOOP}`,
    `${bad}:105${LOOP}`,
    `${bad}:108${LOOP}`,
    `${bad}:111${LOOP}`,
    `${bad}:114${LOOP}`,
    `${bad}:125${LOOP}`,
    `${bad}:127${LOOP}`,
    `${bad}:130${NARROW_LOOP}`,
    `${bad}:131${NARROW_LOOP}`,
    `${bad}:134${NARROW_LOOP}`,
    `${bad}:136${NARROW_LOOP}`,
    `${bad}:145${LOOP}`,
    `${bad}:147${LOOP}`,
    `${bad}:156${DARK}`,
    `${bad}:157${DARK}`,
    `${bad}:159${DARK}`,
    `${bad}:162${NARROW_LOOP_DARK}`,
    `${bad}:166${NARROW_LOOP_DARK}`,
    `${bad}:171${DARK}`,
    `${bad}:174${NARROW}`,
  ])
})

it('stays silent on light, desktop, inherited, data and unreadable screens, and on loops over anything but widths', () => {
  expect(reportFixture('goodE2eDisplay.ts')).toEqual([])
})

it('lets the seventeen theme sources choose dark and still judges their width', () => {
  for (const path of THEME_SPECS) {
    expect(checkSource(path, DARK_THEN_NARROW)).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:2${NARROW}`,
    ])
  }
})

it('lets the seventeen theme sources walk the palettes and refuses their width loops', () => {
  for (const path of THEME_SPECS) {
    expect(checkSource(path, PALETTES_THEN_WIDTHS)).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:5${LOOP}`,
    ])
  }

  const path = 'demo/chat/tests/e2e/tests/room.spec.ts'
  expect(checkSource(path, PALETTES_THEN_WIDTHS)).toEqual([
    `E2E-LIGHT-DESKTOP ${path}:2${DARK}`,
    `E2E-LIGHT-DESKTOP ${path}:5${LOOP}`,
  ])
})

it('refuses dark in a theme file the list does not name', () => {
  const lookAlikes = [
    // A theme spec of a kind the list holds, new in this demo.
    'demo/chat/tests/e2e/tests/theme-smoke.spec.ts',
    // Another demo's theme spec, by its name.
    'demo/tasks/tests/e2e/tests/theme-first-frame.spec.ts',
    // The cluster theme spec, by its name, in another demo.
    'demo/online-testing/tests/e2e/cluster/live/theme.spec.ts',
    // A listed name in another directory of the same demo.
    'demo/polls/tests/e2e/helpers/appearance.spec.ts',
    // A theme spec nobody has listed yet.
    'demo/polls/tests/e2e/tests/theme-picker.spec.ts',
  ]

  for (const path of lookAlikes) {
    expect(checkSource(path, DARK_THEN_NARROW)).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:1${DARK}`,
      `E2E-LIGHT-DESKTOP ${path}:2${NARROW}`,
    ])
  }
})

it('lets the geometry stand spec loop over the three screens of the norm and set the phone one', () => {
  for (const path of GEOMETRY_STANDS) {
    expect(checkSource(path, STAND_SCREENS)).toEqual([])
  }
})

it('refuses the screens of the stand in a geometry file the list does not name', () => {
  const lookAlikes = [
    // A geometry spec of another component in the same demo.
    'demo/pmd/tests/e2e/tests/table-geometry.spec.ts',
    // The stand spec, by its name, in another demo.
    'demo/chat/tests/e2e/tests/modal-geometry.spec.ts',
  ]

  for (const path of lookAlikes) {
    expect(checkSource(path, STAND_SCREENS)).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:8${NARROW_LOOP}`,
      `E2E-LIGHT-DESKTOP ${path}:11${NARROW}`,
    ])
  }
})

it('refuses the geometry stand spec any other width and a loop over another or an unknown width', () => {
  const others =
    "import { SCREENS } from '../helpers/screens.js'\n" +
    'await page.setViewportSize({ width: 320, height: 568 })\n' +
    'for (const width of [1440, 1024, 768]) {\n' +
    '  await page.setViewportSize({ width, height: 800 })\n' +
    '}\n' +
    'for (const screen of SCREENS) {\n' +
    '  test.use({ viewport: screen })\n' +
    '}\n'

  for (const path of GEOMETRY_STANDS) {
    expect(checkSource(path, others)).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:2${NARROW}`,
      `E2E-LIGHT-DESKTOP ${path}:4${LOOP}`,
      `E2E-LIGHT-DESKTOP ${path}:7${LOOP}`,
    ])
  }
})

it('refuses dark in the geometry stand spec, where its screens pass', () => {
  for (const path of GEOMETRY_STANDS) {
    expect(checkSource(path, screensLoop('dark'))).toEqual([
      `E2E-LIGHT-DESKTOP ${path}:8${DARK}`,
    ])
  }
})

it('reads the dark the privacy sweep seeds as stored data, and exempts nothing else there', () => {
  const path = 'demo/polls/tests/e2e/tests/privacy-erase.spec.ts'
  const seeded =
    'await page.evaluate(\n' +
    '  ([pick]) => {\n' +
    "    localStorage.setItem(pick, 'dark')\n" +
    '  },\n' +
    '  [THEME_PICK_KEY],\n' +
    ')\n'

  expect(checkSource(path, seeded)).toEqual([])
  expect(
    checkSource(path, `${seeded}await emulateSystemTheme(page, 'dark')\n`),
  ).toEqual([`E2E-LIGHT-DESKTOP ${path}:7${DARK}`])
})

it('scans every demo e2e root and the shared guest-theme scenario, and nothing else', () => {
  const repositoryRoot = mkdtempSync(join(tmpdir(), 'hilos-e2e-display-'))
  const scanned = [
    'demo/binance-btc-tracker/tests/e2e/cluster/live/size.spec.ts',
    'demo/binance-btc-tracker/tests/e2e/playwright.cluster.config.ts',
    'demo/chat/tests/e2e/helpers/screen.ts',
    'demo/chat/tests/e2e/playwright.config.ts',
    'demo/chat/tests/e2e/tests/room.spec.ts',
    'demo/tasks/tests/e2e/global-setup.ts',
    'framework/frontend/e2e/themeGuestScenarios.ts',
  ]
  const excluded = [
    'framework/frontend/e2e/theme.ts',
    'framework/frontend/codestyle/fixtures/badE2eDisplay.ts',
    'demo/chat/tests/e2e/node_modules/library/index.ts',
    'demo/chat/frontend/src/screen.ts',
    'demo/chat/frontend/vue/test/Screen.test.ts',
  ]

  try {
    expect(checkRepository(repositoryRoot)).toEqual([])
    mkdirSync(join(repositoryRoot, 'demo/without-e2e'), { recursive: true })
    // Written backwards, so the order of the report is not the order of creation.
    for (const path of [...scanned, ...excluded].reverse()) {
      const full = join(repositoryRoot, path)
      mkdirSync(dirname(full), { recursive: true })
      writeFileSync(full, 'await page.setViewportSize({ width: 375 })\n')
    }
    expect(checkRepository(repositoryRoot)).toEqual(
      scanned.map((path) => `E2E-LIGHT-DESKTOP ${path}:1${NARROW}`),
    )
  } finally {
    rmSync(repositoryRoot, { recursive: true, force: true })
  }
})
