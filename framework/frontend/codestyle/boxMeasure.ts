// E2E-BOX-MEASURE, the machine half of the geometry rule in
// docs/agents/frontend/testing-strategy.md: a demo spec takes a bookmark from
// the shared toolbox, which owns both the measurement and the pixel of slack.
// A raw box hands the spec a fractional number whose exact equality can turn
// a repaint into a false red, or two absent boxes into a false green.
//
// The whole demo e2e root is judged, helpers included. There is no allowed file:
// the owner of the measurement lives outside these roots, in the shared toolbox.
// Method names reached through an index or an alias are deliberately not read.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const E2E_BOX_MEASURE_RULE_ID = 'E2E-BOX-MEASURE'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/testing-strategy.md'

/** The methods a demo spec or helper must not call directly. */
const FORBIDDEN_METHODS = ['boundingBox', 'getBoundingClientRect']

/** Where the demos live; each one's e2e root is scanned. */
const DEMO_DIRECTORY = 'demo'

/** E2E root inside a demo, appended to that demo's own directory. */
const DEMO_E2E_ROOT = 'tests/e2e'

/** Extension of the files this checker reads. */
const SOURCE_EXTENSION = '.ts'

/** Never walked: a dependency tree is not this repository's code. */
const SKIPPED_DIRECTORY = 'node_modules'

/**
 * Reports every direct box measurement this file makes, in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending call
 */
export function checkSource(relativePath: string, source: string): string[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const lines: string[] = []

  const visit = (node: ts.Node): void => {
    if (
      ts.isCallExpression(node) &&
      ts.isPropertyAccessExpression(node.expression) &&
      FORBIDDEN_METHODS.includes(node.expression.name.text)
    ) {
      lines.push(report(sourceFile, relativePath, node))
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return lines
}

/**
 * Reports every direct box measurement in the demos' e2e roots, in path order.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending call
 */
export function checkRepository(repositoryRoot: string): string[] {
  const lines: string[] = []

  for (const root of scannedRoots(repositoryRoot)) {
    for (const file of typeScriptFiles(join(repositoryRoot, root))) {
      const relativePath = relative(repositoryRoot, file).split(sep).join('/')
      lines.push(...checkSource(relativePath, readFileSync(file, 'utf8')))
    }
  }

  return lines
}

/**
 * @param sourceFile Parsed file the call belongs to
 * @param relativePath Path of the file from the repository root
 * @param call The offending call expression
 * @returns One finished report line
 */
function report(
  sourceFile: ts.SourceFile,
  relativePath: string,
  call: ts.CallExpression,
): string {
  const line =
    sourceFile.getLineAndCharacterOfPosition(call.getStart(sourceFile)).line + 1

  return (
    `${E2E_BOX_MEASURE_RULE_ID} ${relativePath}:${line} — a demo spec measures` +
    ' geometry through the shared toolbox, not through boundingBox()/getBoundingClientRect(),' +
    ` whose exact number turns a repaint into a false red (see ${DOC})`
  )
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Scanned roots, relative to it, in directory order
 */
function scannedRoots(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  if (!existsSync(demoDirectory)) {
    return []
  }

  return readdirSync(demoDirectory, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_E2E_ROOT}`)
    .filter((root) => existsSync(join(repositoryRoot, root)))
}

/**
 * @param directory Absolute path of the directory to walk
 * @returns Absolute paths of the TypeScript files below it, in directory order
 */
function typeScriptFiles(directory: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.name === SKIPPED_DIRECTORY) {
      continue
    }
    const full = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...typeScriptFiles(full))
    } else if (entry.name.endsWith(SOURCE_EXTENSION)) {
      files.push(full)
    }
  }

  return files
}
