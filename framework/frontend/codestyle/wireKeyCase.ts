// The TypeScript half of WIRE-KEY-CASE, the rule of
// docs/agents/code-style/cross-layer-field-names.md: a field key crossing
// PHP → wire → TS is spelled camelCase, so one word survives every boundary. The
// PHP half lives in framework/tests/CodeStyle/Rule/WireKeyCaseRule.php, carries
// the same rule id and prints the same line — a report reads the same whichever
// side of the boundary produced it.
//
// A key is recognized by the form wire-key-ownership.md prescribes: a constant
// named `<NAME>_FIELD`, or an entry of an `as const` map named `*RowKey`. That
// makes the form load-bearing — a key declared outside it is invisible here — and
// automated-checks.md says so among the rule's blind spots.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, shared with the PHP half and listed once in automated-checks.md. */
export const WIRE_KEY_CASE_RULE_ID = 'WIRE-KEY-CASE'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/code-style/cross-layer-field-names.md'

/** How a flat field key declares itself: an UPPER_SNAKE name ending in `_FIELD`. */
const FIELD_KEY_NAME = /^[A-Z][A-Z0-9_]*_FIELD$/

/** The other declaring form: an `as const` map of the keys of one row shape. */
const ROW_KEY_MAP_NAME = /RowKey$/

/**
 * How the key itself has to be spelled to survive the crossing unchanged. Kept
 * apart from the name patterns above: those answer what a key is, this one
 * answers what a key may look like, and either could move without the other.
 */
const WIRE_KEY_VALUE = /^[a-z][a-zA-Z0-9]*$/

/** SDK packages whose sources declare row-payload keys, relative to the repository root. */
const SDK_ROOTS = [
  'framework/frontend/core/src',
  'framework/frontend/vue/src',
  'framework/frontend/react/src',
  'framework/frontend/angular/src',
  'framework/frontend/prerender/src',
]

/** Where the demos live; each one's frontend source root is scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Source root inside a demo, appended to that demo's own directory. */
const DEMO_SOURCE_ROOT = 'frontend/src'

/** Extension of the files this checker reads; a `.vue` SFC is a documented blind spot. */
const SOURCE_EXTENSION = '.ts'

/**
 * Reports every field key this file declares in another case, in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending key
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
    if (ts.isVariableDeclaration(node)) {
      lines.push(...checkDeclaration(sourceFile, relativePath, node))
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return lines
}

/**
 * Reports every field key declared in the scanned roots, in path order.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending key
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
 * A declaration is judged by its name: `_FIELD` names one key, a `*RowKey` map
 * names the keys of a whole row shape, and anything else — a signal type, an
 * action name, a table or slot name — is a different contract the rule does not
 * own.
 *
 * @param sourceFile Parsed file the declaration belongs to
 * @param relativePath Path of the file from the repository root
 * @param declaration Variable declaration to judge
 * @returns One finished report line per offending key of this declaration
 */
function checkDeclaration(
  sourceFile: ts.SourceFile,
  relativePath: string,
  declaration: ts.VariableDeclaration,
): string[] {
  const initializer = declaration.initializer
  if (!ts.isIdentifier(declaration.name) || initializer === undefined) {
    return []
  }

  const value = spelledOutValue(initializer)

  if (FIELD_KEY_NAME.test(declaration.name.text)) {
    return ts.isStringLiteral(value)
      ? judge(sourceFile, relativePath, value)
      : []
  }

  if (!ROW_KEY_MAP_NAME.test(declaration.name.text)) {
    return []
  }

  return rowKeyMapEntries(value).flatMap((entry) =>
    judge(sourceFile, relativePath, entry),
  )
}

/**
 * `as const` is how a map of keys is frozen, and it reads just as naturally on a
 * single key. Unwrapping it in one place keeps the two declaring forms judged by
 * the same eye — a hole opened by unwrapping it for only one of them would be
 * invisible, because the guard would go on passing.
 *
 * @param initializer Right-hand side of a declaration
 * @returns The expression the assertion wraps, or the initializer itself
 */
function spelledOutValue(initializer: ts.Expression): ts.Expression {
  return ts.isAsExpression(initializer) ? initializer.expression : initializer
}

/**
 * The spelled-out values of a row-key map, in source order. An entry whose value
 * is a reference to a `_FIELD` constant is left out: that key is judged where it
 * is spelled out, so one key is reported once.
 *
 * @param value Right-hand side of the map declaration, past any `as const`
 * @returns Literals the map spells out itself
 */
function rowKeyMapEntries(value: ts.Expression): ts.StringLiteral[] {
  if (!ts.isObjectLiteralExpression(value)) {
    return []
  }

  const literals: ts.StringLiteral[] = []
  for (const property of value.properties) {
    if (
      ts.isPropertyAssignment(property) &&
      ts.isStringLiteral(property.initializer)
    ) {
      literals.push(property.initializer)
    }
  }

  return literals
}

/**
 * @param sourceFile Parsed file the literal belongs to
 * @param relativePath Path of the file from the repository root
 * @param literal Spelled-out key
 * @returns The report line, or nothing when the key is already camelCase
 */
function judge(
  sourceFile: ts.SourceFile,
  relativePath: string,
  literal: ts.StringLiteral,
): string[] {
  if (WIRE_KEY_VALUE.test(literal.text)) {
    return []
  }

  const position = sourceFile.getLineAndCharacterOfPosition(
    literal.getStart(sourceFile),
  )

  return [
    `${WIRE_KEY_CASE_RULE_ID} ${relativePath}:${position.line + 1} — field key` +
      ` '${literal.text}' is not camelCase; one spelling has to serve PHP, the wire` +
      ` and TS (see ${DOC})`,
  ]
}

/**
 * A demo is found by the walk rather than listed, so a new one is covered without
 * an activation step to forget. Most demos are backend-only and have no frontend
 * source root at all, which is why a root that does not exist is skipped rather
 * than reported.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns Existing scanned roots relative to the repository root, in scan order
 */
function scannedRoots(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_SOURCE_ROOT}`)
    : []

  return [...SDK_ROOTS, ...demos].filter((root) =>
    existsSync(join(repositoryRoot, root)),
  )
}

/**
 * @param directory Absolute path of the directory to walk
 * @returns Absolute paths of the TypeScript files below it, in directory order
 */
function typeScriptFiles(directory: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const full = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...typeScriptFiles(full))
    } else if (entry.name.endsWith(SOURCE_EXTENSION)) {
      files.push(full)
    }
  }

  return files
}
