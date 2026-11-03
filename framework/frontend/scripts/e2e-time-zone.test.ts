import module from 'node:module'
import process from 'node:process'
import { afterEach, expect, it, vi } from 'vitest'

import { runTimeZoneGenerator } from './e2e-time-zone.mjs'

function harness() {
  const canonical: Record<string, string | null> = {
    UTC: 'UTC',
    'Etc/UTC': 'UTC',
    GMT: 'UTC',
    'Europe/Warsaw': 'Europe/Warsaw',
    Poland: 'Europe/Warsaw',
    'America/New_York': 'America/New_York',
    'US/Eastern': 'America/New_York',
    'Asia/Tokyo': 'Asia/Tokyo',
    'America/Lima': 'America/Lima',
    'Pacific/Auckland': 'Pacific/Auckland',
  }
  const metadata = {
    projectTimeZone: 'Poland',
    timeZones: [...Object.keys(canonical), 'PHP/Only'],
  }
  const catalog = { timeZones: Object.keys(canonical), canonical }
  const page = { evaluate: vi.fn() }
  const context = {
    newPage: vi.fn(async () => page),
    close: vi.fn(async () => {}),
  }
  const browser = {
    newContext: vi.fn(async () => context),
    close: vi.fn(async () => {}),
  }
  const options = {
    readInput: vi.fn(async () => JSON.stringify(metadata)),
    launchBrowser: vi.fn(async () => browser),
    readCatalog: vi.fn(async () => catalog),
    randomInt: vi.fn<(max: number) => number>(() => 0),
    stdout: vi.fn(),
    stderr: vi.fn(),
  }
  return { metadata, catalog, page, context, browser, options }
}

afterEach(() => vi.restoreAllMocks())

it('defaults to one zone and accepts only the declared strict arguments', async () => {
  const { options } = harness()
  expect(await runTimeZoneGenerator([], options)).toBe(0)
  expect(options.stdout).toHaveBeenCalledExactlyOnceWith('America/New_York\n')
  expect(options.stderr).not.toHaveBeenCalled()

  for (const argv of [
    ['--unknown'],
    ['--count'],
    ['--count', '0'],
    ['--count', '-1'],
    ['--count', '1.5'],
    ['--count', '1e2'],
    ['--count', ' 2'],
    ['--count', '1\n'],
    ['--count', '9007199254740992'],
    ['--count', '1', '--count', '2'],
    ['--exclude'],
    ['--exclude', '+01:00'],
    ['--exclude', 'Asia/Tokyo\n'],
    ['--help', '--unknown'],
  ]) {
    const refused = harness()
    expect(await runTimeZoneGenerator(argv, refused.options)).toBe(1)
    expect(refused.options.stdout).not.toHaveBeenCalled()
    expect(refused.options.launchBrowser).not.toHaveBeenCalled()
  }
  const help = harness()
  expect(await runTimeZoneGenerator(['--help'], help.options)).toBe(0)
  expect(help.options.stderr).toHaveBeenCalledWith(
    expect.stringContaining('--count'),
  )
  expect(help.options.stdout).not.toHaveBeenCalled()
  expect(help.options.launchBrowser).not.toHaveBeenCalled()
})

it('rejects malformed metadata before launch, including an upstream failure with help', async () => {
  for (const input of [
    '',
    '{broken metadata containing private input',
    'null',
    '[]',
    '{}',
    JSON.stringify({ projectTimeZone: 'UTC', timeZones: [] }),
    JSON.stringify({ projectTimeZone: 'UTC', timeZones: ['Asia/Tokyo'] }),
    JSON.stringify({ projectTimeZone: 'UTC', timeZones: ['UTC', null] }),
    JSON.stringify({ projectTimeZone: 'UTC', timeZones: ['UTC', 'bad zone'] }),
    JSON.stringify({ projectTimeZone: 'UTC', timeZones: ['UTC'], env: {} }),
  ]) {
    for (const argv of [[], ['--help']]) {
      const { options } = harness()
      options.readInput.mockResolvedValue(input)
      expect(await runTimeZoneGenerator(argv, options)).toBe(1)
      expect(options.stdout).not.toHaveBeenCalled()
      expect(options.launchBrowser).not.toHaveBeenCalled()
      expect(options.stderr.mock.calls[0][0]).not.toContain('private input')
    }
  }
  const unsupported = harness()
  unsupported.catalog.canonical.Poland = null
  expect(await runTimeZoneGenerator([], unsupported.options)).toBe(1)
  expect(unsupported.options.stdout).not.toHaveBeenCalled()
  expect(unsupported.context.close).toHaveBeenCalledOnce()
  expect(unsupported.browser.close).toHaveBeenCalledOnce()
})

it('intersects exact PHP names with the browser catalog, omitting unsupported names', async () => {
  const { catalog, metadata, options } = harness()
  catalog.timeZones.push('Browser/Only')
  catalog.canonical['Browser/Only'] = 'Browser/Only'
  metadata.timeZones = ['Poland', 'Asia/Tokyo', 'PHP/Only']
  expect(await runTimeZoneGenerator([], options)).toBe(0)
  expect(options.stdout).toHaveBeenCalledExactlyOnceWith('Asia/Tokyo\n')

  // Exercise the page callback too, with a deterministic browser catalog.
  const actual = harness()
  vi.spyOn(Intl, 'supportedValuesOf').mockReturnValue([
    'Asia/Tokyo',
    'America/Lima',
  ])
  actual.page.evaluate.mockImplementation((read, zones) => read(zones))
  expect(
    await runTimeZoneGenerator(['--exclude', 'America/Lima'], {
      ...actual.options,
      readCatalog: undefined,
    }),
  ).toBe(0)
  expect(actual.options.stdout).toHaveBeenCalledExactlyOnceWith('Asia/Tokyo\n')
})

it('excludes UTC, the project and saved roles by canonical identity including aliases', async () => {
  const { options } = harness()
  expect(
    await runTimeZoneGenerator(
      [
        '--count',
        '3',
        '--exclude',
        'US/Eastern',
        '--exclude',
        'America/New_York',
        '--exclude',
        'US/Eastern',
      ],
      options,
    ),
  ).toBe(0)
  expect(options.stdout).toHaveBeenCalledExactlyOnceWith(
    'Asia/Tokyo\nAmerica/Lima\nPacific/Auckland\n',
  )

  const invalid = harness()
  // The real page callback rejects an unknown IANA name before any output.
  invalid.page.evaluate.mockImplementation((read, zones) => read(zones))
  expect(
    await runTimeZoneGenerator(['--exclude', 'Invalid/Zone'], {
      ...invalid.options,
      readCatalog: undefined,
    }),
  ).toBe(1)
  expect(invalid.options.stderr).toHaveBeenLastCalledWith(
    expect.stringContaining('unsupported by Chromium'),
  )
  expect(invalid.options.stdout).not.toHaveBeenCalled()
})

it('draws N distinct canonical zones without replacement', async () => {
  const { options } = harness()
  options.randomInt.mockImplementation((max: number) => max - 1)
  expect(await runTimeZoneGenerator(['--count', '4'], options)).toBe(0)
  expect(options.stdout).toHaveBeenCalledExactlyOnceWith(
    'Pacific/Auckland\nAmerica/Lima\nAsia/Tokyo\nAmerica/New_York\n',
  )
  expect(options.randomInt.mock.calls).toEqual([[4], [3], [2], [1]])
})

it('refuses exhaustion and browser failures without partial output and closes resources', async () => {
  const insufficient = harness()
  expect(
    await runTimeZoneGenerator(['--count', '5'], insufficient.options),
  ).toBe(1)
  expect(insufficient.options.randomInt).not.toHaveBeenCalled()
  expect(insufficient.options.stdout).not.toHaveBeenCalled()
  expect(insufficient.options.stderr).toHaveBeenCalledWith(
    expect.stringContaining('only 4 different candidates'),
  )
  expect(insufficient.context.close).toHaveBeenCalledOnce()
  expect(insufficient.browser.close).toHaveBeenCalledOnce()

  for (const boundary of [
    'launchBrowser',
    'newContext',
    'newPage',
    'readCatalog',
    'close',
  ] as const) {
    const failed = harness()
    const operation = {
      launchBrowser: failed.options.launchBrowser,
      newContext: failed.browser.newContext,
      newPage: failed.context.newPage,
      readCatalog: failed.options.readCatalog,
      close: failed.context.close,
    }[boundary]
    operation.mockRejectedValue(new Error('Chromium unavailable'))
    expect(await runTimeZoneGenerator([], failed.options)).toBe(1)
    expect(failed.options.stdout).not.toHaveBeenCalled()
    expect(failed.options.stderr).toHaveBeenCalledWith(
      expect.stringContaining('Chromium unavailable'),
    )
    if (boundary !== 'launchBrowser')
      expect(failed.browser.close).toHaveBeenCalledOnce()
    if (!['launchBrowser', 'newContext'].includes(boundary)) {
      expect(failed.context.close).toHaveBeenCalledOnce()
    }
  }
})

it('imports without loading Playwright, reading stdin or changing the environment', async () => {
  vi.resetModules()
  const require = vi.spyOn(module, 'createRequire')
  const read = vi.spyOn(process.stdin, Symbol.asyncIterator)
  const encoding = vi.spyOn(process.stdin, 'setEncoding')
  const environment = JSON.stringify(process.env)
  const exitCode = process.exitCode
  await expect(import('./e2e-time-zone.mjs')).resolves.toHaveProperty(
    'runTimeZoneGenerator',
  )
  expect(require).not.toHaveBeenCalled()
  expect(read).not.toHaveBeenCalled()
  expect(encoding).not.toHaveBeenCalled()
  expect(JSON.stringify(process.env) === environment).toBe(true)
  expect(process.exitCode).toBe(exitCode)
})
