// The seeded fixtures prove that MOMENT-FORMAT reads types, not names, and that
// each exception is an address, not a folder. Every case goes through the
// compiler's own standard library, the way a source root is read. The real SDK
// and demo roots are not judged here: they join the shared guard in
// guard.test.ts and its baseline, never a second guard.
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

import { Baseline, type ReportedViolations } from './baseline.js'
import {
  MOMENT_FORMAT_RULE_ID,
  checkRepository,
  checkSource,
  checkSourceRoot,
} from './momentFormat.js'
import { templateSource } from './momentTemplateExpressions.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures/momentFormat'

/** The tail every report line carries. */
const DOC = '(see docs/agents/architecture/time-zones.md)'

/** Where every reason sends the reader. */
const FORMATTER = 'formatMoment() from @hilos/core'

/**
 * Every case builds a program and loads the standard library into it, a fifth
 * of a second on the line. A hit ceiling is the compiler not finishing, and a
 * green run does not wait the ceiling out.
 */
const COMPILE_TIMEOUT_MS = 60_000

/** The one engine, left out whole. */
const ENGINE = 'framework/frontend/core/src/format/moment.ts'

/** The zone-name validation. */
const TIME_ZONE_RULE = 'framework/frontend/core/src/timeZone/timeZoneRule.ts'

/** The numeric calendar boundaries. */
const CALENDAR_PERIODS =
  'framework/frontend/core/src/timeZone/calendarPeriods.ts'

/** The legal calendar date. */
const LEGAL_AGREEMENTS = 'framework/frontend/core/src/legal/legalAgreements.ts'

/** The browser-zone read, whose chain passes by its form alone. */
const TIME_ZONE_BROWSER =
  'framework/frontend/core/src/timeZone/timeZoneBrowser.ts'

/** Reason of a site that builds a DateTimeFormat. */
const CONSTRUCTION = `Intl.DateTimeFormat prints a moment past the core formatter; display it with ${FORMATTER}`

/** Reason of a toLocaleString() whose receiver is not proven numeric. */
const LOCALE_STRING =
  'toLocaleString() is allowed on a receiver typed number or bigint only, and this' +
  ` one is not proven numeric; display a moment with ${FORMATTER}`

/** Reason of a toString() on a Date. */
const DATE_TO_STRING = `toString() of a Date prints a moment past the core formatter; display it with ${FORMATTER}`

/** Reason of a text built from Date getters. */
const HAND_BUILT_TEXT = `text built from Date getters prints a moment past the core formatter; display it with ${FORMATTER}`

/** Reason of a toLocaleString() in a template whose receiver is not proven numeric. */
const TEMPLATE_LOCALE_STRING =
  'toLocaleString() in a template is allowed on a literal, Number() or BigInt() only:' +
  ' a name the template reads proves no type; print a count through a typed' +
  ` TypeScript helper, and a moment with ${FORMATTER}`

/** Reason of Angular's date pipe. */
const DATE_PIPE = `the date pipe prints a moment past the core formatter; display it with ${FORMATTER}`

/** A source root of templates with its own tsconfig. */
const TEMPLATES = `${FIXTURES}/templates`

/** A single-file component with two script blocks around its template. */
const COMPONENT = `${TEMPLATES}/MomentCard.vue`

/** An Angular component's external template. */
const EXTERNAL_TEMPLATE = `${TEMPLATES}/moment-list.html`

/** Angular components with inline templates, one of them not a literal. */
const INLINE_TEMPLATES = `${TEMPLATES}/inlineMoments.ts`

/** The neighbor the component imports a moment type and a typed helper from. */
const MOMENTS = `${TEMPLATES}/moments.ts`

/** Options of a temporary source root that the setup cases break one by one. */
const TEMPORARY_OPTIONS = {
  target: 'ES2022',
  module: 'ESNext',
  moduleResolution: 'Bundler',
  strict: true,
  types: [],
}

/** A moment printed past the formatter, in every file the walk must not read. */
const UNREAD_SITE = 'export const stamp = new Date(0).toUTCString()\n'

/** The same in a template. */
const UNREAD_TEMPLATE_SITE = '<p>{{ at | date }}</p>\n'

/**
 * A repository of the five SDK roots and two demos under names no list holds:
 * one owns its root through the tsconfig.json beside it, the other through the
 * app config its solution config references. Every root has a site and a
 * formatter call or wrapper that is none, and every file the walk must not read
 * has a site. A demo with no frontend has no root.
 */
const REPOSITORY: Record<string, string> = {
  'framework/frontend/core/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'test',
  ]),
  // The engine, left out whole.
  'framework/frontend/core/src/format/moment.ts': [
    'export function formatMoment(at: Date): string {',
    "  return new Intl.DateTimeFormat('en', { timeZone: 'UTC' }).format(at)",
    '}',
    '',
  ].join('\n'),
  'framework/frontend/core/src/stamp.ts': [
    "import { formatMoment } from './format/moment.js'",
    '',
    'export const label = (at: Date): string => formatMoment(at)',
    'export const stamp = (at: Date): string => at.toUTCString()',
    '',
  ].join('\n'),
  'framework/frontend/core/src/dist/stamp.ts': UNREAD_SITE,
  'framework/frontend/core/test/stamp.test.ts': UNREAD_SITE,
  'framework/frontend/vue/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'test',
  ]),
  'framework/frontend/vue/src/index.ts': [
    'declare function formatMoment(at: Date): string',
    '',
    'export const shown = (at: Date): string => formatMoment(at)',
    '',
  ].join('\n'),
  'framework/frontend/vue/src/MomentCard.vue': [
    '<script setup lang="ts">',
    'const created = new Date()',
    'const caption = created.toUTCString()',
    '</script>',
    '',
    '<template>',
    '  <p :title="caption">{{ created.toLocaleDateString() }}</p>',
    '</template>',
    '',
  ].join('\n'),
  'framework/frontend/vue/src/MomentCard.test.ts': UNREAD_SITE,
  'framework/frontend/react/tsconfig.json': tsconfig(
    { ...TEMPORARY_OPTIONS, jsx: 'react-jsx' },
    ['src', 'test'],
  ),
  'framework/frontend/react/src/Stamp.tsx': [
    'export function Stamp({ at }: { at: Date }) {',
    '  return <time>{at.toLocaleTimeString()}</time>',
    '}',
    '',
  ].join('\n'),
  'framework/frontend/react/src/css.d.ts': UNREAD_SITE,
  'framework/frontend/angular/tsconfig.json': tsconfig(
    { ...TEMPORARY_OPTIONS, experimentalDecorators: true },
    ['src', 'test'],
  ),
  'framework/frontend/angular/src/stamp.ts': [
    'declare function Component(definition: object): ClassDecorator',
    '',
    '@Component({',
    "  selector: 'hilos-stamp',",
    "  template: '<time>{{ at | date }}</time>',",
    '})',
    'export class Stamp {',
    '  at = new Date()',
    '}',
    '',
  ].join('\n'),
  'framework/frontend/angular/src/stamp-list.html': [
    "<p>{{ at | date: 'short' }}</p>",
    '<p>{{ shown(at) }}</p>',
    '',
  ].join('\n'),
  'framework/frontend/angular/src/.angular/cache/stamp.ts': UNREAD_SITE,
  'framework/frontend/prerender/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'test',
  ]),
  'framework/frontend/prerender/src/page.ts':
    'export const rendered = new Date().toUTCString()\n',
  'framework/frontend/prerender/src/dist-prerender/page.ts': UNREAD_SITE,
  'framework/frontend/e2e/stamp.ts': UNREAD_SITE,
  'framework/frontend/codestyle/fixtures/momentFormat/stamp.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'env.d.ts',
  ]),
  'demo/orchard-ledger/frontend/src/main.ts':
    'export const opened = new Date().toUTCString()\n',
  'demo/orchard-ledger/frontend/src/format.ts': [
    'declare function formatMoment(at: Date): string',
    '',
    'export const shown = (at: Date): string => formatMoment(at)',
    '',
  ].join('\n'),
  'demo/orchard-ledger/frontend/src/App.vue': [
    '<script setup lang="ts">',
    "import { shown } from './format.js'",
    '',
    'const opened = new Date()',
    '</script>',
    '',
    '<template>',
    '  <p>{{ shown(opened) }}</p>',
    '  <p>{{ opened.toLocaleString() }}</p>',
    '</template>',
    '',
  ].join('\n'),
  'demo/orchard-ledger/frontend/src/App.test.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/src/stamp.spec.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/src/__tests__/stamp.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/src/__fixtures__/stamp.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/src/node_modules/clock/index.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/src/dist-pack/stamp.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/vite.config.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/index.html': UNREAD_TEMPLATE_SITE,
  'demo/orchard-ledger/tests/e2e/stamp.spec.ts': UNREAD_SITE,
  'demo/tide-tables/frontend/tsconfig.json': JSON.stringify({
    compilerOptions: { ...TEMPORARY_OPTIONS, experimentalDecorators: true },
    files: [],
    references: [{ path: './tsconfig.app.json' }],
  }),
  'demo/tide-tables/frontend/tsconfig.app.json': JSON.stringify({
    extends: './tsconfig.json',
    include: ['src/**/*.ts'],
  }),
  'demo/tide-tables/frontend/src/main.ts': [
    'declare function Component(definition: object): ClassDecorator',
    '',
    '@Component({',
    "  selector: 'tide-root',",
    '  template: `',
    '    <h1>{{ title }}</h1>',
    '    <p>{{ high.getHours() }}:{{ high.getMinutes() }}</p>',
    '  `,',
    '})',
    'export class TideRoot {',
    "  title = 'Tides'",
    '  high = new Date()',
    '}',
    '',
  ].join('\n'),
  'demo/tide-tables/frontend/src/index.html': [
    '<!doctype html>',
    '<html lang="en">',
    '  <body>',
    '    <tide-root></tide-root>',
    '  </body>',
    '</html>',
    '',
  ].join('\n'),
  'demo/tide-tables/frontend/src/app/tide-list.html': [
    '<ul>',
    '  <li>{{ shown(tide.at) }}</li>',
    '  <li>{{ tide.at.toUTCString() }}</li>',
    '</ul>',
    '',
  ].join('\n'),
  'demo/tide-tables/frontend/src/app/tide.spec.ts': UNREAD_SITE,
  'demo/backend-only/composer.json': '{}\n',
}

/**
 * @param method A method that exists on Date alone
 * @returns Reason of a site that calls it
 */
function dateOnly(method: string): string {
  return `${method}() prints a moment past the core formatter; display it with ${FORMATTER}`
}

/**
 * @param method A printing method of DateTimeFormat
 * @returns Reason of a site that calls it on a formatter
 */
function formatCall(method: string): string {
  return (
    `Intl.DateTimeFormat ${method}() prints a moment past the core formatter;` +
    ` display it with ${FORMATTER}`
  )
}

/**
 * @param path Path the line names
 * @param line One-based line of the site
 * @param reason Middle of the line
 * @returns The finished report line
 */
function report(path: string, line: number, reason: string): string {
  return `${MOMENT_FORMAT_RULE_ID} ${path}:${line} — ${reason} ${DOC}`
}

/**
 * Runs the checker over a fixture, judged alone.
 *
 * @param name File name inside the fixture directory
 * @param path Path it is judged under; its own place when omitted
 * @returns Reported lines, in source order
 */
function reportFixture(
  name: string,
  path: string = `${FIXTURES}/${name}`,
): string[] {
  return checkSource(
    path,
    readFileSync(join(REPOSITORY_ROOT, FIXTURES, name), 'utf8'),
  )
}

/**
 * @param path A file
 * @returns A file beside it in the same folder
 */
function neighborOf(path: string): string {
  return path.replace(/[^/]+$/, 'neighbor.ts')
}

/**
 * @param files Text of the files of a temporary source root, by path from it
 * @param run Check run against that root's absolute path
 */
function inTemporaryRoot(
  files: Record<string, string>,
  run: (root: string) => void,
): void {
  const root = mkdtempSync(join(tmpdir(), 'hilos-moment-format-'))
  try {
    for (const [name, text] of Object.entries(files)) {
      mkdirSync(dirname(join(root, name)), { recursive: true })
      writeFileSync(join(root, name), text)
    }
    run(root)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
}

/**
 * @param options Compiler options
 * @param include What the config compiles, from its folder
 * @returns Text of a tsconfig
 */
function tsconfig(options: object, include: string[]): string {
  return JSON.stringify({ compilerOptions: options, include })
}

/**
 * The temporary repository of the walk, judged whole.
 *
 * @returns Its report
 */
function repositoryReport(): string[] {
  let lines: string[] = []
  inTemporaryRoot(REPOSITORY, (root) => {
    lines = checkRepository(root)
  })

  return lines
}

/**
 * Keys a report the way the guard does, by rule and file.
 *
 * @param lines Finished report lines
 * @returns The lines keyed by `<rule id> <path>`
 */
function keyed(lines: readonly string[]): ReportedViolations {
  const reported: ReportedViolations = {}
  for (const line of lines) {
    const key = line.slice(0, line.indexOf(':'))
    reported[key] = [...(reported[key] ?? []), line]
  }

  return reported
}

it(
  'reports Date prints on typed, aliased, union, any, unknown, generic, optional and indexed receivers',
  () => {
    const path = `${FIXTURES}/badDateCalls.ts`

    expect(reportFixture('badDateCalls.ts')).toEqual([
      report(path, 14, LOCALE_STRING),
      report(path, 19, dateOnly('toLocaleDateString')),
      report(path, 19, dateOnly('toLocaleTimeString')),
      report(path, 24, LOCALE_STRING),
      report(path, 29, LOCALE_STRING),
      report(path, 34, LOCALE_STRING),
      report(path, 39, LOCALE_STRING),
      report(path, 44, LOCALE_STRING),
      report(path, 49, LOCALE_STRING),
      report(path, 56, LOCALE_STRING),
      report(path, 61, LOCALE_STRING),
      report(path, 66, dateOnly('toLocaleDateString')),
      report(path, 71, dateOnly('toDateString')),
      report(path, 71, dateOnly('toTimeString')),
      report(path, 71, dateOnly('toUTCString')),
      report(path, 71, DATE_TO_STRING),
      report(path, 76, DATE_TO_STRING),
      report(path, 76, LOCALE_STRING),
      report(path, 83, dateOnly('toLocaleDateString')),
      report(path, 89, dateOnly('toUTCString')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports a moment printed as a JSX child and attribute',
  () => {
    const path = `${FIXTURES}/badDateCalls.tsx`

    expect(reportFixture('badDateCalls.tsx')).toEqual([
      report(path, 6, LOCALE_STRING),
      report(path, 11, dateOnly('toUTCString')),
      report(path, 11, dateOnly('toLocaleDateString')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports Intl.DateTimeFormat, one site per chain, and the printing methods of a typed formatter',
  () => {
    const path = `${FIXTURES}/badDateTimeFormat.ts`

    expect(reportFixture('badDateTimeFormat.ts')).toEqual([
      report(path, 8, CONSTRUCTION),
      report(path, 15, CONSTRUCTION),
      report(path, 20, CONSTRUCTION),
      report(path, 25, CONSTRUCTION),
      report(path, 32, CONSTRUCTION),
      report(path, 33, formatCall('format')),
      report(path, 42, formatCall('formatToParts')),
      report(path, 52, formatCall('format')),
      report(path, 52, formatCall('format')),
      report(path, 61, formatCall('formatRange')),
      report(path, 66, CONSTRUCTION),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports text built from Date getters once per text, through simple local const aliases',
  () => {
    const path = `${FIXTURES}/badDateAssembly.ts`

    expect(reportFixture('badDateAssembly.ts')).toEqual([
      report(path, 12, HAND_BUILT_TEXT),
      report(path, 17, HAND_BUILT_TEXT),
      report(path, 22, HAND_BUILT_TEXT),
      report(path, 27, HAND_BUILT_TEXT),
      report(path, 32, HAND_BUILT_TEXT),
      report(path, 37, HAND_BUILT_TEXT),
      report(path, 45, HAND_BUILT_TEXT),
      report(path, 51, HAND_BUILT_TEXT),
      report(path, 56, HAND_BUILT_TEXT),
      report(path, 62, HAND_BUILT_TEXT),
      report(path, 73, HAND_BUILT_TEXT),
      report(path, 78, HAND_BUILT_TEXT),
      report(path, 83, HAND_BUILT_TEXT),
      report(path, 89, HAND_BUILT_TEXT),
      report(path, 89, HAND_BUILT_TEXT),
      report(path, 94, HAND_BUILT_TEXT),
      report(path, 100, HAND_BUILT_TEXT),
      report(path, 102, HAND_BUILT_TEXT),
      report(path, 107, HAND_BUILT_TEXT),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports the children of one JSX element as one text, and not an attribute',
  () => {
    const path = `${FIXTURES}/badDateAssembly.tsx`

    expect(reportFixture('badDateAssembly.tsx')).toEqual([
      report(path, 8, HAND_BUILT_TEXT),
      report(path, 14, HAND_BUILT_TEXT),
      report(path, 19, HAND_BUILT_TEXT),
      report(path, 25, HAND_BUILT_TEXT),
      report(path, 35, HAND_BUILT_TEXT),
      report(path, 35, HAND_BUILT_TEXT),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'passes proven numbers, the five numeric shapes of the SDK, serialization, metadata, declarations, text, counted getters and durations',
  () => {
    expect(reportFixture('goodDateCalls.ts')).toEqual([])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'leaves out the engine by its exact path, not its folder or its function names',
  () => {
    const neighbor = 'framework/frontend/core/src/format/relative.ts'

    expect(reportFixture('exceptions/moment.ts', ENGINE)).toEqual([])
    expect(reportFixture('exceptions/moment.ts', neighbor)).toEqual([
      report(neighbor, 16, CONSTRUCTION),
      report(neighbor, 24, CONSTRUCTION),
      report(neighbor, 25, formatCall('format')),
      report(neighbor, 25, dateOnly('toUTCString')),
      report(neighbor, 39, HAND_BUILT_TEXT),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'lets the bare zone validation through in usableTimeZone alone, once',
  () => {
    const neighbor = neighborOf(TIME_ZONE_RULE)

    expect(reportFixture('exceptions/timeZoneRule.ts', TIME_ZONE_RULE)).toEqual(
      [
        report(TIME_ZONE_RULE, 21, CONSTRUCTION),
        report(TIME_ZONE_RULE, 27, CONSTRUCTION),
      ],
    )
    expect(reportFixture('exceptions/timeZoneRule.ts', neighbor)).toEqual([
      report(neighbor, 11, CONSTRUCTION),
      report(neighbor, 21, CONSTRUCTION),
      report(neighbor, 27, CONSTRUCTION),
    ])

    const widened = [
      'export function usableTimeZone(value: string): string {',
      '  new Intl.DateTimeFormat()',
      '  new Intl.DateTimeFormat(undefined, { timeZone: value })',
      '  new Intl.DateTimeFormat(undefined, { timeZone: value })',
      '  return new Intl.DateTimeFormat(undefined, { timeZone: value }).format(0)',
      '}',
    ].join('\n')
    expect(checkSource(TIME_ZONE_RULE, widened)).toEqual([
      report(TIME_ZONE_RULE, 2, CONSTRUCTION),
      report(TIME_ZONE_RULE, 4, CONSTRUCTION),
      report(TIME_ZONE_RULE, 5, CONSTRUCTION),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'lets the calendar field reader and its parts through in their two functions alone, and no text of its fields',
  () => {
    const neighbor = neighborOf(CALENDAR_PERIODS)

    expect(
      reportFixture('exceptions/calendarPeriods.ts', CALENDAR_PERIODS),
    ).toEqual([
      report(CALENDAR_PERIODS, 56, formatCall('format')),
      report(CALENDAR_PERIODS, 61, CONSTRUCTION),
      report(CALENDAR_PERIODS, 69, HAND_BUILT_TEXT),
    ])
    expect(reportFixture('exceptions/calendarPeriods.ts', neighbor)).toEqual([
      report(neighbor, 9, formatCall('formatToParts')),
      report(neighbor, 27, CONSTRUCTION),
      report(neighbor, 56, formatCall('format')),
      report(neighbor, 61, CONSTRUCTION),
      report(neighbor, 69, HAND_BUILT_TEXT),
    ])

    const widened = [
      'export function calendarFormatter(moment: number): string {',
      "  return new Intl.DateTimeFormat('en-US').format(moment)",
      '}',
      'export function localCoordinate(formatter: Intl.DateTimeFormat, moment: number): number {',
      '  formatter.formatToParts(moment)',
      '  formatter.formatToParts(moment)',
      '  return formatter.format(moment).length',
      '}',
    ].join('\n')
    expect(checkSource(CALENDAR_PERIODS, widened)).toEqual([
      report(CALENDAR_PERIODS, 2, CONSTRUCTION),
      report(CALENDAR_PERIODS, 6, formatCall('formatToParts')),
      report(CALENDAR_PERIODS, 7, formatCall('format')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'lets one explicit-UTC calendar date through in formatHilosLegalDate, and not its neighbor',
  () => {
    const neighbor = neighborOf(LEGAL_AGREEMENTS)

    expect(
      reportFixture('exceptions/legalAgreements.ts', LEGAL_AGREEMENTS),
    ).toEqual([report(LEGAL_AGREEMENTS, 18, CONSTRUCTION)])
    expect(reportFixture('exceptions/legalAgreements.ts', neighbor)).toEqual([
      report(neighbor, 8, CONSTRUCTION),
      report(neighbor, 18, CONSTRUCTION),
    ])

    const widened = [
      'export function formatHilosLegalDate(date: Date, zone: string): string {',
      "  const utc = { timeZone: 'UTC' }",
      '  return [',
      "    new Intl.DateTimeFormat('en-GB', { timeZone: zone }).format(date),",
      "    new Intl.DateTimeFormat('en-GB', utc).format(date),",
      "    new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC' }).formatToParts(date)[0].value,",
      "    new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC' }).format(date),",
      "    new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC' }).format(date),",
      "  ].join(' ')",
      '}',
    ].join('\n')
    expect(checkSource(LEGAL_AGREEMENTS, widened)).toEqual([
      report(LEGAL_AGREEMENTS, 4, CONSTRUCTION),
      report(LEGAL_AGREEMENTS, 5, CONSTRUCTION),
      report(LEGAL_AGREEMENTS, 6, CONSTRUCTION),
      report(LEGAL_AGREEMENTS, 8, CONSTRUCTION),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'lets the zone-name metadata chain through by its form, at its path and at a new one',
  () => {
    const elsewhere = `${FIXTURES}/exceptions/timeZoneBrowser.ts`

    expect(
      reportFixture('exceptions/timeZoneBrowser.ts', TIME_ZONE_BROWSER),
    ).toEqual([
      report(TIME_ZONE_BROWSER, 15, CONSTRUCTION),
      report(TIME_ZONE_BROWSER, 21, CONSTRUCTION),
    ])
    expect(reportFixture('exceptions/timeZoneBrowser.ts')).toEqual([
      report(elsewhere, 15, CONSTRUCTION),
      report(elsewhere, 21, CONSTRUCTION),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'resolves an imported type through the owner config, in path order, and not in a file judged alone',
  () => {
    const folder = `${FIXTURES}/importAlias`
    const readings = `${folder}/readings.ts`
    const stamps = `${folder}/stamps.ts`

    expect(
      checkSourceRoot(REPOSITORY_ROOT, `${folder}/tsconfig.json`, [
        stamps,
        readings,
      ]),
    ).toEqual([
      report(readings, 7, LOCALE_STRING),
      report(readings, 17, LOCALE_STRING),
      report(stamps, 18, dateOnly('toUTCString')),
    ])
    expect(reportFixture('importAlias/readings.ts')).toEqual([
      report(readings, 7, LOCALE_STRING),
      report(readings, 12, LOCALE_STRING),
      report(readings, 17, LOCALE_STRING),
      report(readings, 17, LOCALE_STRING),
      report(readings, 22, LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'judges a root of templates in one program, in path and then file order, script blocks and template mixed',
  () => {
    expect(
      checkSourceRoot(REPOSITORY_ROOT, `${TEMPLATES}/tsconfig.json`, [
        MOMENTS,
        EXTERNAL_TEMPLATE,
        INLINE_TEMPLATES,
        COMPONENT,
      ]),
    ).toEqual([
      // The first script block, above the template: the imported Stamp
      // resolves to a Date, so its toString() is proven one.
      report(COMPONENT, 12, DATE_TO_STRING),
      report(COMPONENT, 17, dateOnly('toLocaleDateString')),
      report(COMPONENT, 20, TEMPLATE_LOCALE_STRING),
      report(COMPONENT, 23, dateOnly('toDateString')),
      report(COMPONENT, 23, dateOnly('toTimeString')),
      report(COMPONENT, 24, dateOnly('toUTCString')),
      report(COMPONENT, 25, HAND_BUILT_TEXT),
      report(COMPONENT, 27, HAND_BUILT_TEXT),
      // The second script block, below the template, at the file's own line.
      report(COMPONENT, 46, dateOnly('toLocaleTimeString')),
      report(INLINE_TEMPLATES, 14, DATE_PIPE),
      report(INLINE_TEMPLATES, 15, dateOnly('toLocaleDateString')),
      report(INLINE_TEMPLATES, 16, HAND_BUILT_TEXT),
      report(INLINE_TEMPLATES, 25, dateOnly('toLocaleTimeString')),
      report(INLINE_TEMPLATES, 31, dateOnly('toLocaleDateString')),
      report(INLINE_TEMPLATES, 41, dateOnly('toUTCString')),
      report(EXTERNAL_TEMPLATE, 3, DATE_PIPE),
      report(EXTERNAL_TEMPLATE, 4, DATE_PIPE),
      report(EXTERNAL_TEMPLATE, 5, TEMPLATE_LOCALE_STRING),
      report(EXTERNAL_TEMPLATE, 6, HAND_BUILT_TEXT),
      report(EXTERNAL_TEMPLATE, 9, HAND_BUILT_TEXT),
      report(EXTERNAL_TEMPLATE, 11, dateOnly('toLocaleTimeString')),
      report(EXTERNAL_TEMPLATE, 15, DATE_PIPE),
      report(EXTERNAL_TEMPLATE, 15, TEMPLATE_LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reads a single-file component judged alone: an unresolved import proves no Date, the template is read the same',
  () => {
    const source = readFileSync(join(REPOSITORY_ROOT, COMPONENT), 'utf8')

    expect(checkSource(COMPONENT, source)).toEqual([
      report(COMPONENT, 17, dateOnly('toLocaleDateString')),
      report(COMPONENT, 20, TEMPLATE_LOCALE_STRING),
      report(COMPONENT, 23, dateOnly('toDateString')),
      report(COMPONENT, 23, dateOnly('toTimeString')),
      report(COMPONENT, 24, dateOnly('toUTCString')),
      report(COMPONENT, 25, HAND_BUILT_TEXT),
      report(COMPONENT, 27, HAND_BUILT_TEXT),
      report(COMPONENT, 46, dateOnly('toLocaleTimeString')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'proves a number in a template by a literal, Number() or BigInt() alone, never by a name or a global of that type',
  () => {
    const path = `${FIXTURES}/numbers.html`
    const source = [
      '<p>{{ (5).toLocaleString() }} {{ Number(total).toLocaleString() }}</p>',
      '<p>{{ length.toLocaleString() }} {{ innerWidth.toLocaleString() }}</p>',
      '<p [title]="count.toLocaleString()">{{ formatCount(count) }}</p>',
    ].join('\n')

    expect(checkSource(path, source)).toEqual([
      report(path, 2, TEMPLATE_LOCALE_STRING),
      report(path, 2, TEMPLATE_LOCALE_STRING),
      report(path, 3, TEMPLATE_LOCALE_STRING),
    ])
    expect(checkSource(path, '<p>{{ formatCount(count) }} date</p>')).toEqual(
      [],
    )
    expect(checkSource(path, '<p>No code at all</p>')).toEqual([])

    // A member may be named by a reserved word; it is not a name the template reads.
    expect(
      checkSource(
        path,
        '<p [title]="pick({ default: at, new: 1 })">{{ range.default.toLocaleString() }}</p>',
      ),
    ).toEqual([report(path, 1, TEMPLATE_LOCALE_STRING)])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reads no inline template that is not a literal and reports nothing of it, while the TypeScript of its file is judged',
  () => {
    const source = readFileSync(join(REPOSITORY_ROOT, INLINE_TEMPLATES), 'utf8')

    // The limit is named by the reader, and the call it would run is a string.
    expect(
      templateSource(INLINE_TEMPLATES, source).limits.map((one) => one.line),
    ).toEqual([35])
    const reported = checkSource(INLINE_TEMPLATES, source)
    expect(reported).toContain(
      report(INLINE_TEMPLATES, 41, dateOnly('toUTCString')),
    )
    for (const line of [9, 35]) {
      expect(reported.join('\n')).not.toContain(`${INLINE_TEMPLATES}:${line} `)
    }
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'throws on a template or a script block that does not parse, at the line of the file, instead of returning an empty list',
  () => {
    const cases: [string, string, string][] = [
      [
        `${FIXTURES}/brokenBinding.vue`,
        '<template>\n  <p :title="created.toLocaleString(">x</p>\n</template>\n',
        `${FIXTURES}/brokenBinding.vue:2 cannot be read: the expression of :title does not parse as TypeScript`,
      ],
      [
        `${FIXTURES}/brokenScript.vue`,
        '<template>\n  <p />\n</template>\n\n<script setup lang="ts">\nconst a = ;\n</script>\n',
        `${FIXTURES}/brokenScript.vue:6 does not parse`,
      ],
      [
        `${FIXTURES}/brokenInterpolation.html`,
        '<p>\n\n{{ created.toLocaleString()\n',
        `${FIXTURES}/brokenInterpolation.html:3 cannot be read: an interpolation does not close`,
      ],
    ]

    for (const [path, source, message] of cases) {
      expect(() => checkSource(path, source), path).toThrow(
        `${MOMENT_FORMAT_RULE_ID} setup failed: ${message}`,
      )
    }
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'throws on a source that does not parse instead of returning an empty list',
  () => {
    expect(() =>
      checkSource(
        `${FIXTURES}/broken.ts`,
        'export const at = new Date(0)\nexport const label = ;\n',
      ),
    ).toThrow(
      `${MOMENT_FORMAT_RULE_ID} setup failed: ${FIXTURES}/broken.ts:2 does not parse`,
    )
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'throws on an owner config, a judged file or a standard library it cannot read instead of returning an empty list',
  () => {
    const source = 'export const label = new Date(0).toUTCString()\n'
    const config = (options: object): string =>
      JSON.stringify({ compilerOptions: options, include: ['a.ts'] })
    // A Date of the project's own and no standard library: every receiver would
    // be whatever this stub says, so it is no setup to judge a moment by.
    const stub = [
      'interface Array<T> { length: number; [index: number]: T }',
      'interface Boolean {}',
      'interface CallableFunction {}',
      'interface Function {}',
      'interface IArguments {}',
      'interface NewableFunction {}',
      'interface Number {}',
      'interface Object {}',
      'interface RegExp {}',
      'interface String {}',
      'interface Date { toUTCString(): string }',
      'declare var Date: { new (value: number): Date }',
    ].join('\n')

    inTemporaryRoot(
      { 'tsconfig.json': config(TEMPORARY_OPTIONS), 'a.ts': source },
      (root) => {
        expect(checkSourceRoot(root, 'tsconfig.json', ['a.ts'])).toEqual([
          report('a.ts', 1, dateOnly('toUTCString')),
        ])
        expect(() => checkSourceRoot(root, 'missing.json', ['a.ts'])).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: missing.json cannot be read`,
        )
        expect(() => checkSourceRoot(root, 'tsconfig.json', ['b.ts'])).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: b.ts cannot be read`,
        )
      },
    )
    inTemporaryRoot(
      {
        'tsconfig.json': config({ ...TEMPORARY_OPTIONS, unknownOption: true }),
        'a.ts': source,
      },
      (root) => {
        expect(() => checkSourceRoot(root, 'tsconfig.json', ['a.ts'])).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: tsconfig.json cannot be used`,
        )
      },
    )
    inTemporaryRoot(
      {
        'tsconfig.json': config({ ...TEMPORARY_OPTIONS, noLib: true }),
        'a.ts': source,
        'globals.d.ts': stub,
      },
      (root) => {
        expect(() => checkSourceRoot(root, 'tsconfig.json', ['a.ts'])).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: the compiler options or the standard library are broken`,
        )
        expect(() =>
          checkSourceRoot(root, 'tsconfig.json', ['globals.d.ts', 'a.ts']),
        ).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: the standard library declares no Date`,
        )
      },
    )
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'walks the SDK roots and every demo found, each root under the config that owns it, and reads nothing beside them',
  () => {
    const core = 'framework/frontend/core/src/stamp.ts'
    const component = 'framework/frontend/vue/src/MomentCard.vue'
    const app = 'demo/orchard-ledger/frontend/src/App.vue'
    const tides = 'demo/tide-tables/frontend/src'

    expect(repositoryReport()).toEqual([
      report(app, 9, TEMPLATE_LOCALE_STRING),
      report(
        'demo/orchard-ledger/frontend/src/main.ts',
        1,
        dateOnly('toUTCString'),
      ),
      report(`${tides}/app/tide-list.html`, 3, dateOnly('toUTCString')),
      report(`${tides}/main.ts`, 7, HAND_BUILT_TEXT),
      report('framework/frontend/angular/src/stamp-list.html', 1, DATE_PIPE),
      report('framework/frontend/angular/src/stamp.ts', 5, DATE_PIPE),
      report(core, 4, dateOnly('toUTCString')),
      report(
        'framework/frontend/prerender/src/page.ts',
        1,
        dateOnly('toUTCString'),
      ),
      report(
        'framework/frontend/react/src/Stamp.tsx',
        2,
        dateOnly('toLocaleTimeString'),
      ),
      report(component, 3, dateOnly('toUTCString')),
      report(component, 7, dateOnly('toLocaleDateString')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'throws on a root no config owns, a config it cannot read and a source that does not parse instead of returning an empty list',
  () => {
    const root = 'demo/stray/frontend'
    const options = tsconfig(TEMPORARY_OPTIONS, ['src'])
    const cases: [Record<string, string>, string][] = [
      [
        { [`${root}/src/main.ts`]: 'export const at = new Date(0)\n' },
        `${root}/tsconfig.json cannot be read`,
      ],
      [
        {
          [`${root}/tsconfig.json`]: JSON.stringify({
            compilerOptions: TEMPORARY_OPTIONS,
            files: [],
            references: [{ path: './tsconfig.e2e.json' }],
          }),
          [`${root}/tsconfig.e2e.json`]: tsconfig(TEMPORARY_OPTIONS, ['e2e']),
          [`${root}/e2e/flow.ts`]: 'export const at = new Date(0)\n',
          [`${root}/src/main.ts`]: 'export const at = new Date(0)\n',
        },
        `${root}/tsconfig.json and the configs it references compile no file of ${root}/src`,
      ],
      [
        {
          [`${root}/tsconfig.json`]: options,
          [`${root}/src/main.ts`]: 'export const at = ;\n',
        },
        `${root}/src/main.ts:1 does not parse`,
      ],
    ]

    for (const [files, message] of cases) {
      inTemporaryRoot(files, (repositoryRoot) => {
        expect(() => checkRepository(repositoryRoot), message).toThrow(
          `${MOMENT_FORMAT_RULE_ID} setup failed: ${message}`,
        )
      })
    }
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'holds the report of a repository in the shared baseline by rule and file: a new file or a raised count is red, a lower one waits for the shrinking update',
  () => {
    const reported = keyed(repositoryReport())
    const component = 'framework/frontend/vue/src/MomentCard.vue'
    const stamp = 'framework/frontend/react/src/Stamp.tsx'
    const record = (path: string, count: number): string =>
      `${MOMENT_FORMAT_RULE_ID} ${path} ${count} # HIL-1594`
    const others = [
      record('demo/orchard-ledger/frontend/src/App.vue', 1),
      record('demo/orchard-ledger/frontend/src/main.ts', 1),
      record('demo/tide-tables/frontend/src/app/tide-list.html', 1),
      record('demo/tide-tables/frontend/src/main.ts', 1),
      record('framework/frontend/angular/src/stamp-list.html', 1),
      record('framework/frontend/angular/src/stamp.ts', 1),
      record('framework/frontend/core/src/stamp.ts', 1),
      record('framework/frontend/prerender/src/page.ts', 1),
    ]
    const baseline = (...records: string[]): Baseline =>
      Baseline.fromText(`${[...others, ...records].join('\n')}\n`)
    const lowered = baseline(record(component, 3), record(stamp, 1))

    expect(
      baseline(record(component, 2), record(stamp, 1)).reconcile(reported),
    ).toEqual([])
    expect(baseline(record(component, 2)).reconcile(reported)).toEqual([
      report(stamp, 2, dateOnly('toLocaleTimeString')),
    ])
    expect(
      baseline(record(component, 1), record(stamp, 1)).reconcile(reported),
    ).toEqual([report(component, 7, dateOnly('toLocaleDateString'))])
    expect(lowered.reconcile(reported)).toEqual([
      `baseline record "${MOMENT_FORMAT_RULE_ID} ${component}" allows 3, only 2 left — lower the count`,
    ])
    expect(lowered.update(reported).text()).toContain(
      `${record(component, 2)}\n`,
    )
  },
  COMPILE_TIMEOUT_MS,
)
