// The fixture reports prove that STYLE-THEME-PINNED fires. The production tree
// and its existing debt belong to guard.test.ts, the one baseline consumer.
import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './themePinned.js'

/** The repository, three directories above this flat test package. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)
const FIXTURES = 'framework/frontend/codestyle/fixtures'
const DOC = 'docs/agents/frontend/styling-rules.md'
const CHOOSE_THEME = 'choose a class that follows the theme from the mockup'
const COLOR = `a color is written by hand; ${CHOOSE_THEME}`
const THEME = 'only the core writes the theme attribute on <html>'

/**
 * @param path Path addressed from the repository root
 * @param line One-based source line
 * @param what Expected finding body
 * @returns The exact report line
 */
function report(path: string, line: number, what: string): string {
  return `STYLE-THEME-PINNED ${path}:${line} — ${what} (see ${DOC})`
}

/**
 * @param path Path addressed from the repository root
 * @param line One-based source line
 * @param token Pinned class as written
 * @param replacement Replacement named by the rule, if any
 * @returns The exact pinned-class report
 */
function pinned(
  path: string,
  line: number,
  token: string,
  replacement?: string,
): string {
  return report(
    path,
    line,
    `class '${token}' is pinned to one theme; ` +
      (replacement === undefined ? CHOOSE_THEME : `use '${replacement}'`),
  )
}

/**
 * @param name Fixture file name
 * @returns Findings from the complete fixture, with its original line numbers
 */
function reportFixture(name: string): string[] {
  const path = `${FIXTURES}/${name}`
  return checkSource(path, readFileSync(join(REPOSITORY_ROOT, path), 'utf8'))
}

it.each([
  {
    name: 'badThemePinned.vue',
    first: 3,
    svg: 14,
    theme: 15,
    color: 19,
    canvas: 22,
  },
  {
    name: 'badThemePinned.tsx',
    first: 5,
    svg: 17,
    theme: 19,
    color: 24,
    canvas: 27,
  },
  {
    name: 'badThemePinnedAngular.ts',
    first: 6,
    svg: 17,
    theme: 18,
    color: 23,
    canvas: 26,
  },
])('pins the exact three kinds of finding in $name', (fixture) => {
  const path = `${FIXTURES}/${fixture.name}`
  expect(reportFixture(fixture.name)).toEqual([
    pinned(path, fixture.first, 'bg-light', 'bg-body-tertiary'),
    pinned(path, fixture.first + 1, 'text-dark', 'text-body'),
    pinned(
      path,
      fixture.first + 2,
      'text-bg-light',
      'bg-body-tertiary text-body-emphasis',
    ),
    pinned(path, fixture.first + 3, 'btn-black'),
    pinned(path, fixture.first + 4, 'btn-outline-dark'),
    pinned(path, fixture.first + 5, 'border-white'),
    pinned(path, fixture.first + 6, 'link-light'),
    pinned(path, fixture.first + 7, 'table-black'),
    pinned(path, fixture.first + 8, 'dropdown-menu-dark'),
    pinned(path, fixture.first + 9, 'btn-close-white'),
    pinned(path, fixture.first + 10, 'text-black-50'),
    report(path, fixture.svg, COLOR),
    report(path, fixture.svg, COLOR),
    report(path, fixture.theme, THEME),
    report(path, fixture.color, COLOR),
    report(path, fixture.canvas, COLOR),
    report(path, fixture.canvas + 1, COLOR),
    report(path, fixture.canvas + 2, COLOR),
  ])
})

it.each([
  'goodThemePinned.vue',
  'goodThemePinned.tsx',
  'goodThemePinnedAngular.ts',
])('stays silent on the theme-aware and inherited paint in %s', (name) => {
  expect(reportFixture(name)).toEqual([])
})

it('reads all color words and token families, including their opacity forms', () => {
  const tokens = [
    'bg',
    'text',
    'text-bg',
    'btn',
    'btn-outline',
    'border',
    'link',
    'table',
  ]
    .flatMap((prefix) =>
      ['light', 'dark', 'white', 'black'].map((color) => `${prefix}-${color}`),
    )
    .flatMap((token) => [token, `${token}-50`])
  const path = 'demo/new/frontend/src/paint.html'
  const reports = checkSource(path, tokens.join(' '))

  expect(reports).toHaveLength(tokens.length)
  tokens.forEach((token, index) => {
    expect(reports[index]).toContain(`class '${token}' is pinned to one theme;`)
  })
  expect(checkSource(path, 'bg-white text-black text-white')).toEqual([
    pinned(path, 1, 'bg-white', 'bg-body'),
    pinned(path, 1, 'text-black', 'text-body-emphasis'),
    pinned(path, 1, 'text-white', 'text-bg-<color> on a colored fill'),
  ])
})

it('respects token boundaries and permits subtle, emphasis and contextual forms', () => {
  expect(
    checkSource(
      'view.html',
      [
        'bg-dark-subtle text-light-emphasis border-white-subtle',
        'alert-light alert-dark list-group-item-light list-group-item-dark',
        'prefix-bg-light bg-light-suffix bg-light_own own_bg-light',
        'text-black-500 btn-close-white-extra dropdown-menu-dark-custom',
      ].join('\n'),
    ),
  ).toEqual([])
})

it('judges comments and repeated occurrences in their source order', () => {
  expect(
    checkSource('view.html', '<!-- bg-light bg-light -->\n// data-bs-theme\n'),
  ).toEqual([
    pinned('view.html', 1, 'bg-light', 'bg-body-tertiary'),
    pinned('view.html', 1, 'bg-light', 'bg-body-tertiary'),
    report('view.html', 2, THEME),
  ])
})

it.each([
  '#abc',
  '#abcd',
  '#aabbcc',
  '#aabbccdd',
  '#ABCDEF',
  'rgb(1 2 3)',
  'rgba(1, 2, 3, .5)',
  'hsl(0 0% 0%)',
  'hsla(0, 0%, 0%, .5)',
  'hwb(0 0% 0%)',
  'lab(0% 0 0)',
  'lch(0% 0 0)',
  'oklab(0% 0 0)',
  'oklch(0% 0 0)',
  'color(display-p3 0 0 0)',
])('reads the whole quoted color %s with each quote spelling', (color) => {
  for (const quote of ["'", '"', '`']) {
    expect(checkSource('paint.ts', `${quote}${color}${quote}`)).toEqual([
      report('paint.ts', 1, COLOR),
    ])
  }
})

it('leaves partial colors, references and an unrelated color prop alone', () => {
  expect(
    checkSource(
      'paint.tsx',
      [
        "const description = 'paint #abc here'",
        "const fragment = '#abcde'",
        "const fill = 'red'",
        '<Widget color="secondary" />',
        '<path :fill="tone" v-bind:stroke="tone" [attr.fill]="tone" fill={tone} />',
        '<path fill="{{ tone }}" />',
      ].join('\n'),
    ),
  ).toEqual([])
})

it('reads every static SVG paint attribute and permits the inherited values', () => {
  const attributes = [
    'fill',
    'stroke',
    'stop-color',
    'flood-color',
    'lighting-color',
  ]
  expect(
    checkSource(
      'paint.html',
      attributes.map((name) => `<path ${name}="red" />`).join('\n'),
    ),
  ).toEqual(
    attributes.map((_, index) => report('paint.html', index + 1, COLOR)),
  )

  for (const value of [
    'currentColor',
    'none',
    'transparent',
    'inherit',
    'context-fill',
    'context-stroke',
    'url(#paint)',
    "url('#abc')",
  ]) {
    expect(checkSource('paint.html', `<path fill="${value}" />`)).toEqual([])
  }
})

it('reports a color once when its literal overlaps SVG or canvas paint', () => {
  expect(
    checkSource(
      'paint.tsx',
      [
        '<path fill="#fff" stroke={"rgb(0 0 0)"} />',
        'context.fillStyle = "#fff"',
        'context.strokeStyle = ("#000")',
        'context.shadowColor = tone',
        'context.fillStyle == tone',
        'context.strokeStyle === tone',
      ].join('\n'),
    ),
  ).toEqual([
    report('paint.tsx', 1, COLOR),
    report('paint.tsx', 1, COLOR),
    report('paint.tsx', 2, COLOR),
    report('paint.tsx', 3, COLOR),
    report('paint.tsx', 4, COLOR),
  ])
})

it.each([
  'framework/frontend/vue/src/HilosQrCode.vue',
  'framework/frontend/react/src/HilosQrCode.tsx',
  'framework/frontend/angular/src/HilosQrCode.ts',
  'framework/frontend/core/src/profile/photoPick.ts',
])('exempts only colors at the exact sanctioned path %s', (path) => {
  const paint =
    '<path fill="red" />\nconst tone = "#fff"\ncontext.fillStyle = "#fff"'
  expect(checkSource(path, paint)).toEqual([])
  for (const other of [
    `${path}.copy`,
    `demo/new/frontend/src/${path.split('/').at(-1)}`,
  ]) {
    expect(checkSource(other, paint)).toEqual([
      report(other, 1, COLOR),
      report(other, 2, COLOR),
      report(other, 3, COLOR),
    ])
  }
  expect(checkSource(path, 'bg-light')).toEqual([
    pinned(path, 1, 'bg-light', 'bg-body-tertiary'),
  ])
})

it('permits the theme attribute only beneath the exact core source root', () => {
  expect(
    checkSource(
      'framework/frontend/core/src/dom/applyTheme.ts',
      'data-bs-theme',
    ),
  ).toEqual([])
  for (const path of [
    'framework/frontend/core/src-extra/view.ts',
    'framework/frontend/core/test/view.ts',
    'framework/frontend/vue/src/HilosQrCode.vue',
    'framework/frontend/react/src/HilosQrCode.tsx',
    'framework/frontend/angular/src/HilosQrCode.ts',
    'demo/new/frontend/src/view.ts',
  ]) {
    expect(checkSource(path, 'data-bs-theme')).toEqual([report(path, 1, THEME)])
  }
})

it('walks production roots, discovers demos and excludes suites, fixtures and build copies', () => {
  const root = mkdtempSync(join(tmpdir(), 'hilos-theme-pinned-'))
  const sources = [
    'framework/frontend/core/src/paint.ts',
    'framework/frontend/vue/src/paint.vue',
    'framework/frontend/react/src/paint.tsx',
    'framework/frontend/angular/src/paint.html',
    'demo/new/frontend/src/paint.ts',
  ]
  const excluded = [
    'framework/frontend/core/test/paint.ts',
    'framework/frontend/codestyle/fixtures/paint.vue',
    'demo/new/frontend/test/paint.ts',
    'framework/frontend/core/src/paint.css',
    ...['test.ts', 'test.tsx', 'spec.ts'].map(
      (ending) => `framework/frontend/core/src/paint.${ending}`,
    ),
    ...['node_modules', 'dist', 'dist-pack', 'dist-prerender', '.angular'].map(
      (directory) => `demo/new/frontend/src/${directory}/paint.ts`,
    ),
  ]
  try {
    for (const path of [...sources, ...excluded]) {
      const full = join(root, path)
      mkdirSync(dirname(full), { recursive: true })
      writeFileSync(full, 'bg-light')
    }
    expect(checkRepository(root)).toEqual(
      sources
        .sort()
        .map((path) => pinned(path, 1, 'bg-light', 'bg-body-tertiary')),
    )
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
