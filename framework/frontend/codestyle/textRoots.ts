import { existsSync, readdirSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

/** The SDK workspace, walked whole: sources, tests and the checkers alike. */
const FRAMEWORK_ROOT = 'framework/frontend'

/** Each demo contributes its frontend sources and e2e suite. */
const DEMO_DIRECTORY = 'demo'

/** Roots inside a demo, appended to that demo's own directory. */
const DEMO_ROOTS = ['frontend/src', 'tests/e2e']

/** Source extensions shared by the frontend text checks. */
const READ_EXTENSIONS = ['.ts', '.mts', '.tsx', '.vue', '.html']

/**
 * Playwright writes reports and results under the e2e roots. A demo build
 * writes third-party license texts under src/generated. Skip those copies
 * along with dependencies and build output when walking the filesystem.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
  'playwright-report',
  'test-results',
  'generated',
]

/**
 * Left out of a scanned root by exact path rather than by directory name, the
 * way the PHP guard leaves out its own. The checkers' fixtures are broken on
 * purpose and are judged by the fixture tests instead.
 */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Paths of every read file below the scanned roots, relative to it, sorted
 */
export function scannedFiles(repositoryRoot: string): string[] {
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
