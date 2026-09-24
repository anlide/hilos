// E2E-PAGE-GOTO, the machine half of the navigation rule in
// docs/agents/frontend/testing-strategy.md: an e2e spec opens a page through the
// `gotoPage` wrapper, never through Playwright's own `goto`.
//
// `goto` waits for the document and nothing else. The page behind it is a live
// subscription whose answer — the payload or a refusal — arrives one round trip
// later, so a spec that navigated and asserted straight away was racing that
// round trip. It passed while the DOM query outran the answer and failed when it
// did not, which reads as a flaky element rather than as the timing it is. The
// wrappers wait on the routed outlet's own state instead, so there is nothing
// left to guess.
//
// One address is let through: a screen of a stand resident, which a spec opens
// in the provider's place — an OAuth consent screen (HIL-923). The gateway serves
// it, no subscription stands behind it, and a wrapper would wait for an answer
// that never comes. The exemption is read off the address, not off the file: the
// argument of the call has to be written from the `STAND_GATEWAY_URL` the file
// imports from framework/frontend/scripts/standGateway.mjs, so the same spec
// opening a product page is still reported.
//
// The rule has no PHP half: the specs it governs are TypeScript only.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, posix, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const E2E_PAGE_GOTO_RULE_ID = 'E2E-PAGE-GOTO'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/testing-strategy.md'

/** The method a spec must not call directly. */
const FORBIDDEN_METHOD = 'goto'

/** Where the demos live; each one's e2e root is scanned. */
const DEMO_DIRECTORY = 'demo'

/** E2E root inside a demo, appended to that demo's own directory. */
const DEMO_E2E_ROOT = 'tests/e2e'

/**
 * The file that owns the wrappers, and so the one place `goto` is called. Held
 * as a path suffix because each demo carries its own copy of the helper.
 */
const WRAPPER_OWNER = 'helpers/page.ts'

/** The export holding the stand gateway's base address. */
const STAND_BASE_EXPORT = 'STAND_GATEWAY_URL'

/**
 * Repository path of the shared module that exports the stand's base, without
 * an extension.
 */
const STAND_BASE_OWNER = 'framework/frontend/scripts/standGateway'

/** Extension of the files this checker reads. */
const SOURCE_EXTENSION = '.ts'

/** Never walked: a dependency tree is not this repository's code. */
const SKIPPED_DIRECTORY = 'node_modules'

/**
 * Reports every direct `goto` call this file makes, in source order, except one
 * whose address is written from the stand's base.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending call
 */
export function checkSource(relativePath: string, source: string): string[] {
  if (relativePath.endsWith(WRAPPER_OWNER)) {
    return []
  }

  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const standBase = standBaseName(sourceFile, relativePath)
  const lines: string[] = []

  const visit = (node: ts.Node): void => {
    if (
      ts.isCallExpression(node) &&
      ts.isPropertyAccessExpression(node.expression) &&
      node.expression.name.text === FORBIDDEN_METHOD &&
      !isWrittenFrom(node.arguments[0], standBase)
    ) {
      lines.push(report(sourceFile, relativePath, node))
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return lines
}

/**
 * Reports every direct `goto` call in the demos' e2e roots, in path order.
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
 * @param sourceFile Parsed file whose imports are read
 * @param relativePath Path of the file from the repository root, which its relative imports resolve against
 * @returns The local name the file gives the stand's base, or null when it does not import it
 */
function standBaseName(
  sourceFile: ts.SourceFile,
  relativePath: string,
): string | null {
  for (const statement of sourceFile.statements) {
    if (
      !ts.isImportDeclaration(statement) ||
      !ts.isStringLiteral(statement.moduleSpecifier) ||
      !isStandBaseOwner(relativePath, statement.moduleSpecifier.text)
    ) {
      continue
    }
    const bindings = statement.importClause?.namedBindings
    if (bindings === undefined || !ts.isNamedImports(bindings)) {
      continue
    }
    for (const element of bindings.elements) {
      if ((element.propertyName ?? element.name).text === STAND_BASE_EXPORT) {
        return element.name.text
      }
    }
  }

  return null
}

/**
 * @param relativePath Path of the importing file from the repository root
 * @param specifier The module an import names, as written
 * @returns Whether the specifier resolves to the shared stand gateway module
 */
function isStandBaseOwner(relativePath: string, specifier: string): boolean {
  if (!specifier.startsWith('.')) {
    return false
  }
  const target = posix.parse(posix.join(posix.dirname(relativePath), specifier))

  return posix.join(target.dir, target.name) === STAND_BASE_OWNER
}

/**
 * Whether an address opens with the base: the base itself, a template whose
 * first piece it is, or a concatenation whose leftmost term it is. Nothing else
 * counts — neither the base further into the address nor one behind a call.
 *
 * @param address The expression a `goto` was handed, if any
 * @param base Local name of the stand's base, or null when the file has none
 * @returns Whether the address is written from the base
 */
function isWrittenFrom(
  address: ts.Expression | undefined,
  base: string | null,
): boolean {
  if (address === undefined || base === null) {
    return false
  }
  if (ts.isTemplateExpression(address)) {
    return (
      address.head.text === '' &&
      isWrittenFrom(address.templateSpans[0].expression, base)
    )
  }
  if (
    ts.isBinaryExpression(address) &&
    address.operatorToken.kind === ts.SyntaxKind.PlusToken
  ) {
    return isWrittenFrom(address.left, base)
  }

  return ts.isIdentifier(address) && address.text === base
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
    `${E2E_PAGE_GOTO_RULE_ID} ${relativePath}:${line} — a spec opens a page` +
    ' through gotoPage(), not through goto(), which waits' +
    ` for the document and not for the subscription's answer (see ${DOC})`
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
