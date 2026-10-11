// STYLE-THEME-PINNED, the machine half of the painting rule in
// docs/agents/frontend/styling-rules.md. The whole source text is judged,
// comments included: a pinned class copied from a comment is still pinned.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

/** Rule id, listed once in automated-checks.md. */
export const STYLE_THEME_PINNED_RULE_ID = 'STYLE-THEME-PINNED'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/styling-rules.md'

/** A complete pinned token, leaving the subtle and emphasis forms alone. */
const PINNED_CLASS =
  /(?<![\w-])(?:(?:bg|text|text-bg|btn|btn-outline|border|link|table)-(?:light|dark|white|black)(?:-50)?|dropdown-menu-dark|btn-close-white)(?![\w-])/g

/** Replacements whose light appearance the styling rule names explicitly. */
const REPLACEMENTS: Record<string, string> = {
  'bg-light': 'bg-body-tertiary',
  'text-bg-light': 'bg-body-tertiary text-body-emphasis',
  'bg-white': 'bg-body',
  'text-dark': 'text-body',
  'text-black': 'text-body-emphasis',
  'text-white': 'text-bg-<color> on a colored fill',
}

/** What the rule asks when the mockup must choose the replacement. */
const CHOOSE_THEME = 'choose a class that follows the theme from the mockup'

/** A whole quoted color, in markup, a script, or an inline template. */
const COLOR_LITERAL =
  /(['"`])(?:#(?:[\da-f]{8}|[\da-f]{6}|[\da-f]{4}|[\da-f]{3})|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^'"`]*\))\1/gi

/** Opening markup tags, keeping a greater-than inside a quoted value inside it. */
const OPEN_TAG = /<[a-zA-Z][\w:.-]*(?:[^<>"']|"[^"]*"|'[^']*')*>/g

/** Static SVG paint values, including JSX's expression around a literal. */
const SVG_COLOR_ATTRIBUTE =
  /(?<![\w.:[-])(fill|stroke|stop-color|flood-color|lighting-color)\s*=\s*("[^"]*"|'[^']*'|\{\s*(?:"[^"]*"|'[^']*'|`[^`]*`)\s*\}|[^\s"'`=<>}{]+)/g

/** Paint inherited from the surface or its context rather than pinned here. */
const INHERITED_COLOR =
  /^(?:currentColor|none|transparent|inherit|context-fill|context-stroke|url\([\s\S]*\))$/i

/** Canvas writes; comparisons are reads, and a quoted RHS belongs to this site. */
const CANVAS_COLOR =
  /\.(?:fillStyle|strokeStyle|shadowColor)\s*=(?!=)\s*(?:\(\s*)*(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`)?/g

/** The theme attribute belongs to the core alone, including its head script. */
const THEME_ATTRIBUTE = /data-bs-theme/g
const CORE_ROOT = 'framework/frontend/core/src'

/**
 * These colors are the content being drawn. Exact paths exempt only the color
 * finding; classes and the theme attribute are judged as everywhere else.
 */
const SANCTIONED_COLOR_SITES: Record<string, string> = {
  'framework/frontend/vue/src/HilosQrCode.vue':
    'a scanner refuses an inverted code',
  'framework/frontend/react/src/HilosQrCode.tsx':
    'a scanner refuses an inverted code',
  'framework/frontend/angular/src/HilosQrCode.ts':
    'a scanner refuses an inverted code',
  'framework/frontend/core/src/profile/photoPick.ts':
    'the pixels of the picture: the preview is flattened on white',
}

/** Production SDK roots; test packages and build copies are outside them. */
const SDK_ROOTS = [
  CORE_ROOT,
  'framework/frontend/vue/src',
  'framework/frontend/react/src',
  'framework/frontend/angular/src',
]

/** A new demo is covered without an activation step. */
const DEMO_DIRECTORY = 'demo'
const DEMO_SOURCE_ROOT = 'frontend/src'

/** The source shapes this lexical rule reads. */
const SCANNED_EXTENSIONS = ['.vue', '.ts', '.tsx', '.html']
const TEST_FILE = /\.(?:test\.tsx?|spec\.ts)$/

/** Dependencies and build outputs are copies rather than production sources. */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
]

/** Broken samples are judged by fixture tests rather than the repository guard. */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/** One occurrence, whose range lets overlapping color scanners share a finding. */
interface Site {
  offset: number
  end: number
  what: string
}

/**
 * @param relativePath Path from the repository root, as it appears in the report
 * @param source Complete source text, including comments
 * @returns One finished report line per occurrence, in source order
 */
export function checkSource(relativePath: string, source: string): string[] {
  const sites: Site[] = []

  for (const match of source.matchAll(PINNED_CLASS)) {
    const replacement = REPLACEMENTS[match[0]]
    sites.push({
      offset: match.index,
      end: match.index + match[0].length,
      what:
        `class '${match[0]}' is pinned to one theme; ` +
        (replacement === undefined ? CHOOSE_THEME : `use '${replacement}'`),
    })
  }

  if (!Object.hasOwn(SANCTIONED_COLOR_SITES, relativePath)) {
    sites.push(...colorSites(source))
  }

  if (!relativePath.startsWith(`${CORE_ROOT}/`)) {
    for (const match of source.matchAll(THEME_ATTRIBUTE)) {
      sites.push({
        offset: match.index,
        end: match.index + match[0].length,
        what: 'only the core writes the theme attribute on <html>',
      })
    }
  }

  return sites
    .sort((first, second) => first.offset - second.offset)
    .map((site) => {
      const line = source.slice(0, site.offset).split('\n').length
      return `${STYLE_THEME_PINNED_RULE_ID} ${relativePath}:${line} — ${site.what} (see ${DOC})`
    })
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns One report per occurrence in production sources, in path order
 */
export function checkRepository(repositoryRoot: string): string[] {
  return scannedFiles(repositoryRoot).flatMap((path) =>
    checkSource(path, readFileSync(join(repositoryRoot, path), 'utf8')),
  )
}

/**
 * A hex inside an SVG attribute or a canvas assignment is one occurrence, even
 * though both the literal scanner and its more specific scanner recognize it.
 *
 * @param source Complete source text
 * @returns Color occurrences with overlapping ranges merged
 */
function colorSites(source: string): Site[] {
  const sites: Site[] = []
  const inherited: { offset: number; end: number }[] = []
  const what = `a color is written by hand; ${CHOOSE_THEME}`

  for (const pattern of [COLOR_LITERAL, CANVAS_COLOR]) {
    for (const match of source.matchAll(pattern)) {
      sites.push({
        offset: match.index,
        end: match.index + match[0].length,
        what,
      })
    }
  }

  for (const tag of source.matchAll(OPEN_TAG)) {
    for (const attribute of tag[0].matchAll(SVG_COLOR_ATTRIBUTE)) {
      const value = attributeValue(attribute[2])
      const offset = tag.index + attribute.index
      const end = offset + attribute[0].length
      if (INHERITED_COLOR.test(value)) {
        inherited.push({ offset, end })
      } else if (!value.includes('{{') && !value.includes('${')) {
        sites.push({ offset, end, what })
      }
    }
  }

  const merged: Site[] = []
  sites.sort(
    (first, second) => first.offset - second.offset || second.end - first.end,
  )
  for (const site of sites) {
    // A quoted paint-server fragment is a reference, even when its id happens
    // to look like a hex color to the literal scanner.
    if (
      inherited.some(
        (attribute) =>
          site.offset >= attribute.offset && site.end <= attribute.end,
      )
    ) {
      continue
    }
    const previous = merged.at(-1)
    if (previous !== undefined && site.offset < previous.end) {
      previous.end = Math.max(previous.end, site.end)
    } else {
      merged.push(site)
    }
  }

  return merged
}

/**
 * @param written A static attribute value, possibly a JSX expression
 * @returns Its text without expression braces or surrounding quotes
 */
function attributeValue(written: string): string {
  const value = written.startsWith('{') ? written.slice(1, -1).trim() : written

  return /^["'`]/.test(value) ? value.slice(1, -1) : value
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Production files relative to the repository root, in path order
 */
function scannedFiles(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_SOURCE_ROOT}`)
    : []

  return [...SDK_ROOTS, ...demos]
    .filter((root) => existsSync(join(repositoryRoot, root)))
    .flatMap((root) => readFiles(join(repositoryRoot, root), repositoryRoot))
    .sort()
}

/**
 * @param directory Absolute path of the directory to walk
 * @param repositoryRoot Root the returned paths are addressed from
 * @returns Production sources under the directory, addressed from the root
 */
function readFiles(directory: string, repositoryRoot: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && SKIPPED_DIRECTORIES.includes(entry.name)) {
      continue
    }
    const full = join(directory, entry.name)
    const path = relative(repositoryRoot, full).split(sep).join('/')
    if (EXCLUDED_PATHS.includes(path)) {
      continue
    }
    if (entry.isDirectory()) {
      files.push(...readFiles(full, repositoryRoot))
    } else if (
      !TEST_FILE.test(entry.name) &&
      SCANNED_EXTENSIONS.some((extension) => entry.name.endsWith(extension))
    ) {
      files.push(path)
    }
  }

  return files
}
