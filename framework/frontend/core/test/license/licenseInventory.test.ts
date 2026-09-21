import { describe, expect, it } from 'vitest'
import {
  filterLicenseEntries,
  type HilosLicenseEntry,
  licenseCsvFileName,
  licenseFilterOptions,
  licenseLanguageLabel,
  renderLicenseCsv,
} from '../../src/license/licenseInventory.js'

/** A row, spelled out only where the case under test looks at it. */
function entry(fields: Partial<HilosLicenseEntry>): HilosLicenseEntry {
  return {
    name: 'vue',
    version: '3.5.35',
    language: 'js',
    license: 'MIT',
    licenseText: null,
    repository: null,
    ...fields,
  }
}

const inventory: HilosLicenseEntry[] = [
  entry({ name: 'anlide/hilos', version: '2.0.0', language: 'php' }),
  entry({ name: 'bootstrap', version: '5.3.8' }),
  entry({ name: 'rxjs', version: '7.8.2', license: 'Apache-2.0' }),
  entry({ name: 'nesbot/carbon', version: '3.8.0', language: 'php' }),
]

const noFilter = { search: '', license: '', language: '' }

describe('filterLicenseEntries', () => {
  it('admits everything when nothing is asked', () => {
    expect(filterLicenseEntries(inventory, noFilter)).toEqual(inventory)
  })

  it('searches the package name, ignoring case and the blanks around it', () => {
    const found = filterLicenseEntries(inventory, {
      ...noFilter,
      search: '  BootStrap ',
    })

    expect(found.map((one) => one.name)).toEqual(['bootstrap'])
  })

  it('searches the license type as well as the name', () => {
    const found = filterLicenseEntries(inventory, {
      ...noFilter,
      search: 'apache',
    })

    expect(found.map((one) => one.name)).toEqual(['rxjs'])
  })

  it('searches neither the version nor the license text', () => {
    const withText = [entry({ name: 'vue', licenseText: 'Copyright Evan You' })]

    expect(
      filterLicenseEntries(withText, { ...noFilter, search: '3.5' }),
    ).toEqual([])
    expect(
      filterLicenseEntries(withText, { ...noFilter, search: 'evan' }),
    ).toEqual([])
  })

  it('matches a select exactly and ANDs the three conditions', () => {
    const found = filterLicenseEntries(inventory, {
      search: 'carbon',
      license: 'MIT',
      language: 'php',
    })

    expect(found.map((one) => one.name)).toEqual(['nesbot/carbon'])
    // Each condition on its own admits more than the three of them together.
    expect(
      filterLicenseEntries(inventory, { ...noFilter, language: 'php' }),
    ).toHaveLength(2)
  })

  it('admits nothing when the conditions do not meet', () => {
    expect(
      filterLicenseEntries(inventory, {
        ...noFilter,
        license: 'Apache-2.0',
        language: 'php',
      }),
    ).toEqual([])
  })

  it('keeps the order it was given, sorting nothing itself', () => {
    const found = filterLicenseEntries(inventory, {
      ...noFilter,
      language: 'js',
    })

    expect(found.map((one) => one.name)).toEqual(['bootstrap', 'rxjs'])
  })
})

describe('licenseFilterOptions', () => {
  it('offers the distinct values of the snapshot, sorted, each once', () => {
    expect(licenseFilterOptions(inventory)).toEqual({
      licenses: ['Apache-2.0', 'MIT'],
      languages: ['js', 'php'],
    })
  })

  it('offers a license nobody expected, because the list is not fixed', () => {
    const options = licenseFilterOptions([entry({ license: 'UNKNOWN' })])

    expect(options.licenses).toEqual(['UNKNOWN'])
  })

  it('offers nothing at all for an empty snapshot', () => {
    expect(licenseFilterOptions([])).toEqual({ licenses: [], languages: [] })
  })
})

describe('licenseLanguageLabel', () => {
  it('labels the two wire values the generator writes', () => {
    expect(licenseLanguageLabel('php')).toBe('PHP')
    expect(licenseLanguageLabel('js')).toBe('JS')
  })

  it('shows an unknown value rather than dropping it', () => {
    expect(licenseLanguageLabel('rust')).toBe('rust')
  })
})

describe('renderLicenseCsv', () => {
  it('writes the header even when there is nothing under it', () => {
    expect(renderLicenseCsv([])).toBe('package,version,language,license\r\n')
  })

  it('writes one line per entry, with the language label the screen shows', () => {
    expect(renderLicenseCsv([inventory[0], inventory[1]])).toBe(
      'package,version,language,license\r\n' +
        'anlide/hilos,2.0.0,PHP,MIT\r\n' +
        'bootstrap,5.3.8,JS,MIT\r\n',
    )
  })

  it('quotes a value that carries a comma, a quote or a line break', () => {
    const csv = renderLicenseCsv([
      entry({ name: 'odd, name', license: 'MIT OR "BSD"' }),
    ])

    expect(csv).toContain('"odd, name",3.5.35,JS,"MIT OR ""BSD"""\r\n')
  })

  it('never emits the license text', () => {
    const csv = renderLicenseCsv([entry({ licenseText: 'Copyright Evan You' })])

    expect(csv).not.toContain('Copyright')
  })
})

describe('licenseCsvFileName', () => {
  it('builds a hyphenated file name for an ordinary project name', () => {
    expect(licenseCsvFileName('demo-chat')).toBe(
      'demo-chat-license-hilos-framework.csv',
    )
  })

  it('normalizes npm scope characters and uppercase letters to a clean slug', () => {
    expect(licenseCsvFileName('@acme/Shop')).toBe(
      'acme-shop-license-hilos-framework.csv',
    )
  })

  it('falls back to the base file name when nothing remains after sanitization', () => {
    expect(licenseCsvFileName('')).toBe('license-hilos-framework.csv')
    expect(licenseCsvFileName('///')).toBe('license-hilos-framework.csv')
  })

  it('preserves full length for a maximal 214-character npm package name', () => {
    const longName = 'a'.repeat(214)
    expect(licenseCsvFileName(longName)).toBe(
      `${longName}-license-hilos-framework.csv`,
    )
  })
})
