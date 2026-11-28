// The seeded fixtures prove that NUMBER-FORMAT reads types, not names, through
// the walk and the program of MOMENT-FORMAT, and that the engine is an address,
// not a folder. The real SDK and demo roots are not judged here: they join the
// shared guard in guard.test.ts and its baseline, never a second guard.
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

import {
  NUMBER_FORMAT_RULE_ID,
  checkRepository,
  checkSource,
  checkSourceRoot,
} from './numberFormat.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures/numberFormat'

/** The tail every report line carries. */
const DOC =
  '(see docs/agents/architecture/language-and-region.md#the-formats-a-reader-sees)'

/** Where every reason sends the reader. */
const FORMATTER = 'formatNumber() from @hilos/core'

/**
 * Every case builds a program and loads the standard library into it, a fifth
 * of a second on the line. A hit ceiling is the compiler not finishing, and a
 * green run does not wait the ceiling out.
 */
const COMPILE_TIMEOUT_MS = 60_000

/** The one engine, left out whole. */
const ENGINE = 'framework/frontend/core/src/format/number.ts'

/** Reason of a site that builds a NumberFormat. */
const CONSTRUCTION = `Intl.NumberFormat prints a number past the region's formats; display it with ${FORMATTER}`

/** Reason of a toLocaleString() on a proven number. */
const LOCALE_STRING = `toLocaleString() of a number prints it past the region's formats; display it with ${FORMATTER}`

/** Options of a temporary source root. */
const TEMPORARY_OPTIONS = {
  target: 'ES2022',
  module: 'ESNext',
  moduleResolution: 'Bundler',
  strict: true,
  types: [],
}

/** A number printed past the formatter, in every file the walk must not read. */
const UNREAD_SITE = 'export const shown = (5).toLocaleString()\n'

/**
 * A repository of two SDK roots and a demo under a name no list holds: the
 * engine prints with Intl and is left out, every other root has a site, and
 * every file the walk must not read has one too. A demo with no frontend has
 * no root.
 */
const REPOSITORY: Record<string, string> = {
  'framework/frontend/core/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'test',
  ]),
  'framework/frontend/core/src/format/number.ts': [
    'export function formatNumber(value: number): string {',
    "  return new Intl.NumberFormat('en').format(value)",
    '}',
    '',
  ].join('\n'),
  'framework/frontend/core/src/count.ts': [
    "import { formatNumber } from './format/number.js'",
    '',
    'export const label = (count: number): string => formatNumber(count)',
    'export const raw = (count: number): string => count.toLocaleString()',
    '',
  ].join('\n'),
  'framework/frontend/core/src/dist/count.ts': UNREAD_SITE,
  'framework/frontend/core/test/count.test.ts': UNREAD_SITE,
  'framework/frontend/vue/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
    'test',
  ]),
  'framework/frontend/vue/src/CountCard.vue': [
    '<script setup lang="ts">',
    'const total = 12',
    '</script>',
    '',
    '<template>',
    '  <p>{{ Number(total).toLocaleString() }}</p>',
    '</template>',
    '',
  ].join('\n'),
  'framework/frontend/vue/src/CountCard.test.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/tsconfig.json': tsconfig(TEMPORARY_OPTIONS, [
    'src',
  ]),
  'demo/orchard-ledger/frontend/src/main.ts':
    'export const crates = new Intl.NumberFormat().format(40)\n',
  'demo/orchard-ledger/frontend/src/__fixtures__/count.ts': UNREAD_SITE,
  'demo/orchard-ledger/frontend/vite.config.ts': UNREAD_SITE,
  'demo/orchard-ledger/tests/e2e/count.spec.ts': UNREAD_SITE,
  'demo/backend-only/composer.json': '{}\n',
}

/**
 * @param method A printing method of NumberFormat
 * @returns Reason of a site that calls it on a formatter
 */
function formatCall(method: string): string {
  return (
    `Intl.NumberFormat ${method}() prints a number past the region's formats;` +
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
  return `${NUMBER_FORMAT_RULE_ID} ${path}:${line} — ${reason} ${DOC}`
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
 * @param files Text of the files of a temporary source root, by path from it
 * @param run Check run against that root's absolute path
 */
function inTemporaryRoot(
  files: Record<string, string>,
  run: (root: string) => void,
): void {
  const root = mkdtempSync(join(tmpdir(), 'hilos-number-format-'))
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

it(
  'reports toLocaleString() on typed, literal, converted, bigint, union, enum, branded, generic, optional, indexed and asserted numbers',
  () => {
    const path = `${FIXTURES}/badNumberCalls.ts`

    expect(reportFixture('badNumberCalls.ts')).toEqual([
      report(path, 15, LOCALE_STRING),
      report(path, 19, LOCALE_STRING),
      report(path, 19, LOCALE_STRING),
      report(path, 23, LOCALE_STRING),
      report(path, 23, LOCALE_STRING),
      report(path, 23, LOCALE_STRING),
      report(path, 28, LOCALE_STRING),
      report(path, 28, LOCALE_STRING),
      report(path, 33, LOCALE_STRING),
      report(path, 38, LOCALE_STRING),
      report(path, 43, LOCALE_STRING),
      report(path, 43, LOCALE_STRING),
      report(path, 48, LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports Intl.NumberFormat, one site per chain, and the printing methods of a typed formatter',
  () => {
    const path = `${FIXTURES}/badNumberFormat.ts`

    expect(reportFixture('badNumberFormat.ts')).toEqual([
      report(path, 6, CONSTRUCTION),
      report(path, 9, CONSTRUCTION),
      report(path, 10, CONSTRUCTION),
      report(path, 14, CONSTRUCTION),
      report(path, 21, formatCall('format')),
      report(path, 21, formatCall('formatToParts')),
      report(path, 31, formatCall('format')),
      report(path, 31, formatCall('formatToParts')),
      report(path, 35, CONSTRUCTION),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'reports the range methods where the standard library of the root declares them',
  () => {
    const folder = `${FIXTURES}/range`
    const ranges = `${folder}/ranges.ts`

    expect(
      checkSourceRoot(REPOSITORY_ROOT, `${folder}/tsconfig.json`, [ranges]),
    ).toEqual([
      report(ranges, 10, formatCall('formatRange')),
      report(ranges, 10, formatCall('formatRangeToParts')),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  "passes the formatter, unproven receivers, other formatters, static metadata, a type of one's own and conversions in no locale",
  () => {
    expect(reportFixture('goodNumberCalls.ts')).toEqual([])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'leaves out the engine by its exact path, not its folder or its function names',
  () => {
    const neighbor = 'framework/frontend/core/src/format/neighbor.ts'

    expect(reportFixture('exceptions/number.ts', ENGINE)).toEqual([])
    expect(reportFixture('exceptions/number.ts', neighbor)).toEqual([
      report(neighbor, 6, CONSTRUCTION),
      report(neighbor, 11, LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'resolves an imported type through the owner config, and not in a file judged alone',
  () => {
    const folder = `${FIXTURES}/importAlias`
    const readings = `${folder}/readings.ts`

    expect(
      checkSourceRoot(REPOSITORY_ROOT, `${folder}/tsconfig.json`, [
        readings,
        `${folder}/counts.ts`,
      ]),
    ).toEqual([
      report(readings, 7, LOCALE_STRING),
      report(readings, 12, LOCALE_STRING),
    ])
    expect(reportFixture('importAlias/readings.ts')).toEqual([])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'proves a number in a template by a literal, Number() or BigInt() alone, and judges a script block as TypeScript',
  () => {
    const component = `${FIXTURES}/templates/NumberCard.vue`
    const list = `${FIXTURES}/templates/number-list.html`

    expect(reportFixture('templates/NumberCard.vue')).toEqual([
      report(component, 5, LOCALE_STRING),
      report(component, 11, LOCALE_STRING),
      report(component, 11, LOCALE_STRING),
      report(component, 13, CONSTRUCTION),
    ])
    expect(reportFixture('templates/number-list.html')).toEqual([
      report(list, 2, LOCALE_STRING),
      report(list, 4, LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'walks the SDK roots and every demo found through the walk of MOMENT-FORMAT, and reads nothing beside them',
  () => {
    let lines: string[] = []
    inTemporaryRoot(REPOSITORY, (root) => {
      lines = checkRepository(root)
    })

    expect(lines).toEqual([
      report('demo/orchard-ledger/frontend/src/main.ts', 1, CONSTRUCTION),
      report('framework/frontend/core/src/count.ts', 4, LOCALE_STRING),
      report('framework/frontend/vue/src/CountCard.vue', 6, LOCALE_STRING),
    ])
  },
  COMPILE_TIMEOUT_MS,
)

it(
  'throws under its own id on a root no config owns, a source that does not parse and a standard library with no NumberFormat',
  () => {
    const root = 'demo/stray/frontend'
    const cases: [Record<string, string>, string][] = [
      [
        { [`${root}/src/main.ts`]: 'export const count = 5\n' },
        `${root}/tsconfig.json cannot be read`,
      ],
      [
        {
          [`${root}/tsconfig.json`]: tsconfig(TEMPORARY_OPTIONS, ['src']),
          [`${root}/src/main.ts`]: 'export const count = ;\n',
        },
        `${root}/src/main.ts:1 does not parse`,
      ],
    ]
    for (const [files, message] of cases) {
      inTemporaryRoot(files, (repositoryRoot) => {
        expect(() => checkRepository(repositoryRoot), message).toThrow(
          `${NUMBER_FORMAT_RULE_ID} setup failed: ${message}`,
        )
      })
    }

    // Globals of the project's own and no standard library: no Intl to read
    // a NumberFormat from.
    const stub = [
      'interface Array<T> { length: number; [index: number]: T }',
      'interface Boolean {}',
      'interface CallableFunction {}',
      'interface Function {}',
      'interface IArguments {}',
      'interface NewableFunction {}',
      'interface Number { toLocaleString(): string }',
      'interface Object {}',
      'interface RegExp {}',
      'interface String {}',
    ].join('\n')
    inTemporaryRoot(
      {
        'tsconfig.json': JSON.stringify({
          compilerOptions: { ...TEMPORARY_OPTIONS, noLib: true },
          include: ['a.ts'],
        }),
        'a.ts': 'export const shown = (5).toLocaleString()\n',
        'globals.d.ts': stub,
      },
      (repositoryRoot) => {
        expect(() =>
          checkSourceRoot(repositoryRoot, 'tsconfig.json', [
            'globals.d.ts',
            'a.ts',
          ]),
        ).toThrow(
          `${NUMBER_FORMAT_RULE_ID} setup failed: the standard library declares no Intl.NumberFormat`,
        )
      },
    )
  },
  COMPILE_TIMEOUT_MS,
)
