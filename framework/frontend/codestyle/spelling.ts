// The TypeScript half of SPELLING, the rule of docs/agents/code-style/spelling.md:
// American English in every piece of English a file carries — a name, a string, a
// comment, a template. The PHP half lives in
// framework/tests/CodeStyle/Rule/SpellingRule.php, carries the same rule id and
// prints the same line — a report reads the same whichever side produced it.
//
// This half reads the source text and not the compiler API. The canon of
// automated-checks.md sends a second half through ts.createSourceFile so that a
// name quoted in a string or written in a comment is not taken for a declaration;
// here the string and the comment ARE the subject, so there is nothing for a
// parser to narrow — and the parser reads neither a .vue template nor an .html
// file, both of which carry the English this rule judges. The canon says so beside
// the sentence this half departs from.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

/** Rule id, shared with the PHP half and listed once in automated-checks.md. */
export const SPELLING_RULE_ID = 'SPELLING'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/code-style/spelling.md'

/**
 * The six pairs of spelling.md, British keyed to American, in the order the table
 * writes them. The table is the source and this constant its reading: a seventh
 * pair is added to the document first.
 */
const AMERICAN_BY_BRITISH: Readonly<Record<string, string>> = {
  licence: 'license',
  colour: 'color',
  behaviour: 'behavior',
  serialise: 'serialize',
  organise: 'organize',
  cancelled: 'canceled',
}

/** The SDK workspace, walked whole: sources, tests and the checkers alike. */
const FRAMEWORK_ROOT = 'framework/frontend'

/** Where the demos live; each one's two English-bearing roots are scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Roots inside a demo, appended to that demo's own directory. */
const DEMO_ROOTS = ['frontend/src', 'tests/e2e']

/** The source forms this rule reads: the two the compiler parses, and the two it does not. */
const READ_EXTENSIONS = ['.ts', '.tsx', '.vue', '.html']

/**
 * Never walked: a dependency tree is not this repository's code, and a build
 * artifact is a copy of code that was already judged at its source. Two names
 * more than the other checkers skip, because this one is the first to read
 * `.html` under an e2e root: Playwright writes its report and its results there
 * on every machine that has run the suite, and a generated report is not
 * authored text.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
  'playwright-report',
  'test-results',
]

/**
 * Left out of a scanned root by exact path rather than by directory name, the
 * way the PHP guard leaves out its own. The checkers' fixtures are broken on
 * purpose and are judged by the fixture tests instead.
 */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/**
 * The files that write the British forms on purpose, each with its reason: this
 * one holds the table, and its test pins the report lines naming both spellings.
 * Judged in {@link checkSource} rather than in the walk, so the exemption is what
 * the fixture test can prove.
 */
const DICTIONARY_FILES = [
  'framework/frontend/codestyle/spelling.ts',
  'framework/frontend/codestyle/spelling.test.ts',
]

/**
 * Where a match opens: a non-letter on the left, or a camelCase hump — a lowercase
 * letter, a digit or an underscore directly before a capital. Case-sensitive on
 * purpose, unlike the word it precedes: the hump IS the case.
 */
const OPEN = '(?:(?<![A-Za-z])|(?<=[a-z0-9_])(?=[A-Z]))'

/** Where a match closes: after the trailing lowercase letters, which take the word form in. */
const CLOSE = '[a-z]*'

/**
 * The matcher, assembled from the table so that the table stays the only list:
 * `open`, then one of the six words in any case, then `close`. The word is spelled
 * out letter by letter in both cases because the flag that would do it (`i`) would
 * also blur the hump, which has to stay case-sensitive.
 */
const BRITISH_FORM = new RegExp(
  `${OPEN}(${Object.keys(AMERICAN_BY_BRITISH).map(anyCase).join('|')})${CLOSE}`,
  'g',
)

/**
 * Reports every British form this file writes, in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per occurrence
 */
export function checkSource(relativePath: string, source: string): string[] {
  if (DICTIONARY_FILES.includes(relativePath)) {
    return []
  }

  const lines: string[] = []
  for (const match of source.matchAll(BRITISH_FORM)) {
    const british = match[0]
    const stem = match[1]
    const line = source.slice(0, match.index).split('\n').length

    lines.push(
      `${SPELLING_RULE_ID} ${relativePath}:${line} — write` +
        ` "${american(stem, british.slice(stem.length))}", not "${british}"` +
        ` (see ${DOC})`,
    )
  }

  return lines
}

/**
 * Reports every British form in the SDK workspace, the demos' frontends and their
 * e2e suites, in path order.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per occurrence
 */
export function checkRepository(repositoryRoot: string): string[] {
  const lines: string[] = []

  for (const relativePath of scannedFiles(repositoryRoot)) {
    lines.push(
      ...checkSource(
        relativePath,
        readFileSync(join(repositoryRoot, relativePath), 'utf8'),
      ),
    )
  }

  return lines
}

/**
 * The American word in the case the British one was written, carrying the same
 * tail: `Behavioural` reads back as `Behavioral`, `CANCELLED` as `CANCELED`,
 * `serialises` as `serializes`. The report says what to type, not what to look up.
 *
 * @param stem The British word as matched, in its own case
 * @param tail The lowercase letters the match took in after the word
 * @returns The American form to write instead
 */
function american(stem: string, tail: string): string {
  const word = AMERICAN_BY_BRITISH[stem.toLowerCase()]

  if (stem === stem.toUpperCase()) {
    return word.toUpperCase() + tail
  }
  if (stem.charAt(0) === stem.charAt(0).toUpperCase()) {
    return word.charAt(0).toUpperCase() + word.slice(1) + tail
  }

  return word + tail
}

/**
 * @param word A lowercase word of the table
 * @returns The same word as a pattern that matches it in any case, one class per letter
 */
function anyCase(word: string): string {
  return [...word]
    .map((letter) => `[${letter.toUpperCase()}${letter}]`)
    .join('')
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Paths of every read file below the scanned roots, relative to it, sorted
 */
function scannedFiles(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .flatMap((entry) =>
          DEMO_ROOTS.map((root) => `${DEMO_DIRECTORY}/${entry.name}/${root}`),
        )
    : []
  const roots = [FRAMEWORK_ROOT, ...demos].filter((root) =>
    existsSync(join(repositoryRoot, root)),
  )

  return roots
    .flatMap((root) => readFiles(join(repositoryRoot, root), repositoryRoot))
    .sort()
}

/**
 * @param directory Absolute path of the directory to walk
 * @param repositoryRoot Absolute path of the repository root, which the paths are made relative to
 * @returns Paths of the read files below the directory, relative to the repository root
 */
function readFiles(directory: string, repositoryRoot: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && SKIPPED_DIRECTORIES.includes(entry.name)) {
      continue
    }
    const full = join(directory, entry.name)
    const relativePath = relative(repositoryRoot, full).split(sep).join('/')
    if (EXCLUDED_PATHS.includes(relativePath)) {
      continue
    }
    if (entry.isDirectory()) {
      files.push(...readFiles(full, repositoryRoot))
    } else if (
      READ_EXTENSIONS.some((extension) => entry.name.endsWith(extension))
    ) {
      files.push(relativePath)
    }
  }

  return files
}
