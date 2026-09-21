// The license inventory's headless half: the shape of the build-time snapshot,
// the filtering the /license page does over it, and the one rendering both export
// destinations receive. No DOM and no framework, so the three view layers stay
// thin and the same filter is not written three times (multiframework-core.md).
//
// The snapshot itself is produced at build time by
// framework/frontend/scripts/generate-license-inventory.mjs, which reads the
// project's own two lockfiles; nothing here reads a file or asks the backend.
// Row order is the generator's and is never re-sorted here: two builds of one
// tree must produce the same page, and sorting in three view layers would
// diverge on the machine's locale.

/** One third-party package the build actually contains. */
export interface HilosLicenseEntry {
  /** The package name as its manifest writes it — `vue`, `anlide/hilos`. */
  readonly name: string
  /** The version copied from the lockfile verbatim, `v` prefix and all. */
  readonly version: string
  /** The wire value `'php'` or `'js'`; {@link licenseLanguageLabel} labels it. */
  readonly language: string
  /** The SPDX string the manifest declares, or `'UNKNOWN'` when it declares none. */
  readonly license: string
  /** The package's own license text, or null when it ships no license file. */
  readonly licenseText: string | null
  /** The package's repository address, normalized to https, or null. */
  readonly repository: string | null
}

/** The whole snapshot, as the page receives it in one prop. */
export interface HilosLicenseInventory {
  /** The name of the project this snapshot was taken of, as its own lockfile writes it. */
  readonly project: string
  readonly entries: readonly HilosLicenseEntry[]
}

/** What the filter bar currently asks of the list. */
export interface HilosLicenseFilter {
  /** The search box; `''` (or blanks only) means no search. */
  readonly search: string
  /** The license select; `''` means every license. */
  readonly license: string
  /** The language select; `''` means every language. */
  readonly language: string
}

/** The CSV header, and with it the column order of the export. */
const CSV_HEADER = 'package,version,language,license'

/** RFC 4180 line ending — between lines and after the last one. */
const CSV_LINE_END = '\r\n'

/**
 * The entries the filter bar currently admits, in the snapshot's own order.
 *
 * The search matches the package name OR the license type (a package is looked
 * for by either), the two selects match exactly, and the three conditions are
 * ANDed — the order they are applied in cannot change the result.
 *
 * @param entries The whole snapshot.
 * @param filter What the bar asks; every empty string means "no condition".
 * @returns The admitted entries, in the order they were given in.
 */
export function filterLicenseEntries(
  entries: readonly HilosLicenseEntry[],
  filter: HilosLicenseFilter,
): HilosLicenseEntry[] {
  const search = filter.search.trim().toLowerCase()

  return entries.filter((entry) => {
    if (filter.license !== '' && entry.license !== filter.license) return false
    if (filter.language !== '' && entry.language !== filter.language) {
      return false
    }
    if (search === '') return true

    return (
      entry.name.toLowerCase().includes(search) ||
      entry.license.toLowerCase().includes(search)
    )
  })
}

/**
 * The distinct values the two selects offer, sorted.
 *
 * Built from the snapshot, never from a fixed list: a hard-coded set would
 * silently hide a license nobody expected, which is the one thing this page
 * exists to surface. The `all` option is the view's own first entry (value
 * `''`) and is not part of these lists.
 *
 * @param entries The whole snapshot.
 * @returns The distinct licenses and languages, each sorted for the 'en' locale.
 */
export function licenseFilterOptions(entries: readonly HilosLicenseEntry[]): {
  readonly licenses: string[]
  readonly languages: string[]
} {
  const licenses = new Set<string>()
  const languages = new Set<string>()
  for (const entry of entries) {
    licenses.add(entry.license)
    languages.add(entry.language)
  }
  const byName = (left: string, right: string): number =>
    left.localeCompare(right, 'en')

  return {
    licenses: [...licenses].sort(byName),
    languages: [...languages].sort(byName),
  }
}

/**
 * The label a language wire value is shown as.
 *
 * The one place the two wire values turn into text. An unknown value is returned
 * unchanged rather than dropped, so a language added later shows itself instead
 * of vanishing from the page. The generator writes the wire values and names this
 * function as their contract in a comment: a build script cannot import a core
 * module, and a silent second definition would be worse than a named one.
 *
 * @param language The wire value — `'php'`, `'js'`, or something newer.
 * @returns The label for the badge and for the CSV's language column.
 */
export function licenseLanguageLabel(language: string): string {
  if (language === 'php') return 'PHP'
  if (language === 'js') return 'JS'

  return language
}

/**
 * One CSV field, quoted only where RFC 4180 requires it.
 *
 * @param value The field's text.
 * @returns The field as it is written into the file.
 */
function csvField(value: string): string {
  if (!/[",\r\n]/.test(value)) return value

  return `"${value.replaceAll('"', '""')}"`
}

/**
 * The export's one rendering — the same text for the clipboard and for the file.
 *
 * CSV because the reader is a lawyer, a client or a security review, and they
 * open the list in a spreadsheet. License TEXTS are never emitted: they would
 * turn the file into a hundred pages, and they are read one at a time on the
 * page.
 *
 * @param entries The entries to hand over — the filtered ones, in the order
 *   shown, because the filter is the question that was asked.
 * @returns The CSV, header row included, ending with a line break.
 */
export function renderLicenseCsv(
  entries: readonly HilosLicenseEntry[],
): string {
  const lines = [CSV_HEADER]
  for (const entry of entries) {
    lines.push(
      [
        csvField(entry.name),
        csvField(entry.version),
        csvField(licenseLanguageLabel(entry.language)),
        csvField(entry.license),
      ].join(','),
    )
  }

  return `${lines.join(CSV_LINE_END)}${CSV_LINE_END}`
}

/** The tail every export carries, so a file in a downloads folder says what built it. */
const CSV_FILE_NAME_TAIL = 'license-hilos-framework.csv'

/**
 * Build the download file name for a project's license inventory export.
 *
 * @param project The name of the project, typically from the snapshot's project field.
 * @returns `<slug>-license-hilos-framework.csv`, or `'license-hilos-framework.csv'` when empty.
 */
export function licenseCsvFileName(project: string): string {
  const slug = project
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

  return slug === '' ? CSV_FILE_NAME_TAIL : `${slug}-${CSV_FILE_NAME_TAIL}`
}
