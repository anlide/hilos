// ICON-NAME-EXISTS, the rule of docs/agents/frontend/styling-rules.md:
// every icon class name drawn from Bootstrap Icons exists in the installed
// icon package, and the dependency declaration matches that installed floor.
//
// Two facts under one rule id:
//  (1) Unknown icon name: every bi-* name in source text must exist in the
//      installed font/bootstrap-icons.json map.
//  (2) Declared version below installed: the declared floor in package.json
//      must match the version installed on disk (1.13.1), keeping the range
//      from silently falling back to a version missing needed glyphs.
import { existsSync, readdirSync, readFileSync, type Dirent } from 'node:fs'
import { join, relative, sep } from 'node:path'

/** Rule id, listed once in automated-checks.md. */
export const ICON_NAME_EXISTS_RULE_ID = 'ICON-NAME-EXISTS'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/styling-rules.md'

/** Relative path to the icon names dictionary within the frontend workspace. */
const ICONS_JSON_PATH =
  'framework/frontend/node_modules/bootstrap-icons/font/bootstrap-icons.json'

/** Relative path to the package.json of the icon set within the frontend workspace. */
const PACKAGE_JSON_PATH =
  'framework/frontend/node_modules/bootstrap-icons/package.json'

/** The extensions of source files this rule reads. */
const SCANNED_EXTENSIONS = ['.ts', '.tsx', '.vue', '.html', '.php']

/**
 * Regex matching Bootstrap icon class names in source text.
 * Matched as text literals rather than AST traversal.
 */
const ICON_NAME_PATTERN = /\bbi-[a-z0-9-]+\b/g

/**
 * Regex matching bootstrap-icons dependency declarations in package.json.
 */
const BOOTSTRAP_ICONS_DECLARATION = /"bootstrap-icons"\s*:\s*"([^"]+)"/

/**
 * Never walked: dependency trees, build outputs, and test artifacts.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'vendor',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
  'playwright-report',
  'test-results',
]

/**
 * Excluded paths: fixtures of codestyle checks and this rule's own test.
 */
const EXCLUDED_PATHS = [
  'framework/frontend/codestyle/fixtures',
  'framework/frontend/codestyle/iconNameExists.test.ts',
]

/**
 * Reports every unknown icon name and mismatched dependency floor in a tree.
 *
 * @param root Absolute path of the tree to judge
 * @param iconNames Allowed icon names (with or without 'bi-' prefix)
 * @param installedVersion Version of bootstrap-icons installed on disk
 * @returns One finished report line per violation, in path order
 */
export function checkTree(
  root: string,
  iconNames: string[],
  installedVersion: string,
): string[] {
  const files = filesUnder(root, root, [])
  return judge(root, files, iconNames, installedVersion)
}

/**
 * Reports every unknown icon name and mismatched dependency floor in the repository.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per violation, in path order
 */
export function checkRepository(repositoryRoot: string): string[] {
  const iconsPath = join(repositoryRoot, ICONS_JSON_PATH)
  if (!existsSync(iconsPath)) {
    return [reportMissingSet(ICONS_JSON_PATH)]
  }

  let iconNames: string[]
  try {
    const raw = readFileSync(iconsPath, 'utf8')
    const parsed = JSON.parse(raw) as Record<string, unknown>
    iconNames = Object.keys(parsed)
  } catch {
    return [reportMissingSet(ICONS_JSON_PATH)]
  }

  let installedVersion = 'unknown'
  const pkgPath = join(repositoryRoot, PACKAGE_JSON_PATH)
  if (existsSync(pkgPath)) {
    try {
      const pkg = JSON.parse(readFileSync(pkgPath, 'utf8')) as {
        version?: string
      }
      if (typeof pkg.version === 'string') {
        installedVersion = pkg.version
      }
    } catch {
      // Keep 'unknown' on parse error.
    }
  }

  const files = scannedRoots(repositoryRoot).flatMap((root) =>
    filesUnder(join(repositoryRoot, root), repositoryRoot, EXCLUDED_PATHS),
  )

  return judge(repositoryRoot, files, iconNames, installedVersion)
}

/**
 * Judges files against the allowed icon names and installed dependency version.
 *
 * @param base Absolute path the file list is addressed from
 * @param files Files to judge, addressed from that base
 * @param iconNames Allowed icon names
 * @param installedVersion Installed package version
 * @returns One finished report line per violation, in path order
 */
function judge(
  base: string,
  files: string[],
  iconNames: string[],
  installedVersion: string,
): string[] {
  const validSet = new Set(
    iconNames.map((name) => (name.startsWith('bi-') ? name : `bi-${name}`)),
  )

  const lines: string[] = []

  for (const file of [...files].sort()) {
    if (file === 'package.json' || file.endsWith('/package.json')) {
      lines.push(...checkPackageJson(base, file, installedVersion))
      continue
    }

    if (SCANNED_EXTENSIONS.some((ext) => file.endsWith(ext))) {
      lines.push(...checkSourceFile(base, file, validSet, installedVersion))
    }
  }

  return lines
}

/**
 * Checks a package.json file for bootstrap-icons floor mismatches.
 *
 * @param base Absolute path the file is addressed from
 * @param relativePath Path of the file as it appears in reports
 * @param installedVersion Installed package version
 * @returns Report lines for declared floor mismatches
 */
function checkPackageJson(
  base: string,
  relativePath: string,
  installedVersion: string,
): string[] {
  let content: string
  try {
    content = readFileSync(join(base, relativePath), 'utf8')
  } catch {
    return []
  }

  const lines: string[] = []
  const fileLines = content.split('\n')
  for (let index = 0; index < fileLines.length; index++) {
    const match = BOOTSTRAP_ICONS_DECLARATION.exec(fileLines[index])
    if (match !== null) {
      const declared = match[1]
      const floor = declared.replace(/^[\^~>= ]+/, '')
      if (floor !== installedVersion) {
        lines.push(
          reportFloorMismatch(
            relativePath,
            index + 1,
            declared,
            installedVersion,
          ),
        )
      }
    }
  }

  return lines
}

/**
 * Checks source files for unknown Bootstrap Icon names.
 *
 * @param base Absolute path the file is addressed from
 * @param relativePath Path of the file as it appears in reports
 * @param validSet Set of recognized bi-* icon names
 * @param installedVersion Installed package version
 * @returns Report lines for unknown icon names
 */
function checkSourceFile(
  base: string,
  relativePath: string,
  validSet: Set<string>,
  installedVersion: string,
): string[] {
  let content: string
  try {
    content = readFileSync(join(base, relativePath), 'utf8')
  } catch {
    return []
  }

  const lines: string[] = []
  for (const match of content.matchAll(ICON_NAME_PATTERN)) {
    const iconName = match[0]
    if (!validSet.has(iconName)) {
      const line = content.slice(0, match.index).split('\n').length
      lines.push(
        reportUnknownIcon(relativePath, line, iconName, installedVersion),
      )
    }
  }

  return lines
}

/**
 * Formats a report for an unknown icon name.
 *
 * @param relativePath Path of the offending file
 * @param line Line number of the violation
 * @param iconName Unknown icon name found
 * @param installedVersion Installed version of the icon set
 * @returns Finished report line
 */
function reportUnknownIcon(
  relativePath: string,
  line: number,
  iconName: string,
  installedVersion: string,
): string {
  return (
    `${ICON_NAME_EXISTS_RULE_ID} ${relativePath}:${line} — ${iconName} is not in the installed Bootstrap Icons set (${installedVersion});` +
    ` use a name the set has (see ${DOC})`
  )
}

/**
 * Formats a report for a declared dependency floor mismatch.
 *
 * @param relativePath Path of the package.json file
 * @param line Line number of the declaration
 * @param declared Declared semver range
 * @param installedVersion Installed version on disk
 * @returns Finished report line
 */
function reportFloorMismatch(
  relativePath: string,
  line: number,
  declared: string,
  installedVersion: string,
): string {
  return (
    `${ICON_NAME_EXISTS_RULE_ID} ${relativePath}:${line} — bootstrap-icons is declared ${declared} while ${installedVersion} is installed;` +
    ` the declared floor is the version the tree stands on (see ${DOC})`
  )
}

/**
 * Formats a report when the icon set cannot be read.
 *
 * @param missingPath Relative path to the missing font JSON file
 * @returns Finished report line
 */
function reportMissingSet(missingPath: string): string {
  return `${ICON_NAME_EXISTS_RULE_ID} ${missingPath}:1 — the icon set is not installed, so no name could be checked (see ${DOC})`
}

/**
 * Resolves scanned root directories for the repository.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns List of existing scanned root paths relative to repositoryRoot
 */
function scannedRoots(repositoryRoot: string): string[] {
  const roots: string[] = [
    'framework/frontend',
    'framework/backend',
    'framework/tests',
  ]

  const demoDirectory = join(repositoryRoot, 'demo')
  if (existsSync(demoDirectory)) {
    for (const entry of readdirSync(demoDirectory, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        for (const sub of ['frontend', 'backend', 'tests']) {
          const rootPath = `demo/${entry.name}/${sub}`
          if (existsSync(join(repositoryRoot, rootPath))) {
            roots.push(rootPath)
          }
        }
      }
    }
  }

  return roots
}

/**
 * Recursively walks directory to find all files, honoring skip and exclusion lists.
 *
 * @param directory Absolute path of the directory to walk
 * @param base Absolute path from which returned paths are relative
 * @param excluded Paths excluded from scanning
 * @returns List of relative file paths
 */
function filesUnder(
  directory: string,
  base: string,
  excluded: string[],
): string[] {
  const files: string[] = []

  let entries: Dirent[]
  try {
    entries = readdirSync(directory, { withFileTypes: true })
  } catch {
    return []
  }

  for (const entry of entries) {
    if (entry.isDirectory() && SKIPPED_DIRECTORIES.includes(entry.name)) {
      continue
    }
    const full = join(directory, entry.name)
    const relativePath = relative(base, full).split(sep).join('/')
    if (excluded.includes(relativePath)) {
      continue
    }
    if (entry.isDirectory()) {
      files.push(...filesUnder(full, base, excluded))
    } else {
      files.push(relativePath)
    }
  }

  return files
}
