// BROWSER-VALUE-DECLARED, the machine half of "what this browser keeps" in
// docs/agents/frontend/core-and-connection.md: a file that puts a value in the
// visitor's browser declares it, right there, with `browserValue(...)`.
//
// Why a rule and not a list. /privacy offers to erase everything this site keeps
// in the browser, and the erase sweeps a registry. A registry assembled from
// declarations is still a hand-written list if a declaration can be left out —
// and left out silently, which is the failure the ticket behind this rule names
// in as many words. The rule is what removes the word "silently".
//
// FILE-LEVEL, on purpose. It is the granularity of the rule being enforced
// ("declared beside the write"), it needs no cross-file analysis, and it is
// reported once per file, at the first write.
//
// The declaring form is load-bearing: a declaration written any other way is
// invisible here, exactly the trade WIRE-KEY-CASE documents as its own blind
// spot. `.vue` and `.html` are a second one — this rule reads TypeScript.
//
// A `setItem` call is judged whatever it is called on, rather than only on the
// four spellings of the two global stores. The two framework files that keep a
// value today both write through a local variable holding the store, so a rule
// that read receivers would have missed both — and `setItem` is a name the
// browser gave to storage alone.
//
// The rule has no PHP half: nothing outside the frontend writes to a browser.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const BROWSER_VALUE_DECLARED_RULE_ID = 'BROWSER-VALUE-DECLARED'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/core-and-connection.md'

/** The call that declares a value, and the only form this rule can see. */
const DECLARING_CALL = 'browserValue'

/** The method both browser stores are written through. */
const STORAGE_WRITE = 'setItem'

/** The property a cookie is written to, on `document` in any spelling. */
const COOKIE_PROPERTY = 'cookie'

/** Assignment operators that leave a cookie behind; `+=` sets one as surely as `=`. */
const COOKIE_ASSIGNMENTS = [
  ts.SyntaxKind.EqualsToken,
  ts.SyntaxKind.PlusEqualsToken,
]

/** SDK packages whose sources may keep a value in the browser, from the repository root. */
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

/** Extensions this rule reads; `.vue` and `.html` are a documented blind spot. */
const SOURCE_EXTENSIONS = ['.ts', '.tsx']

/**
 * Left out by exact path, the way STYLE-INLINE leaves out the fixtures it breaks
 * on purpose. The one entry here is the sweep itself: deleting a cookie means
 * writing it back with no lifetime left, so the erase cannot help assigning to
 * `document.cookie` — and it declares nothing because it is what SPENDS the
 * declarations. Everything else that touches a cookie is a write site and is
 * judged. Tests and e2e specs need no entry: they live outside every scanned
 * root, which is why the roots are source roots rather than whole packages.
 */
const EXCLUDED_PATHS = ['framework/frontend/core/src/browser/browserValues.ts']

/**
 * Reports this file's first write to the browser when it declares no value.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One report line when the file writes and declares nothing, none otherwise
 */
export function checkSource(relativePath: string, source: string): string[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  let firstWrite: number | undefined
  let declares = false

  const visit = (node: ts.Node): void => {
    if (isDeclaringCall(node)) {
      declares = true
    } else if (isBrowserWrite(node) && firstWrite === undefined) {
      firstWrite =
        sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
          .line + 1
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  if (declares || firstWrite === undefined) {
    return []
  }

  return [
    `${BROWSER_VALUE_DECLARED_RULE_ID} ${relativePath}:${firstWrite} — this file` +
      ' leaves a value in this browser and declares none; declare it with' +
      ` ${DECLARING_CALL}({ store, key, label }) beside the write, so the erase on` +
      ` /privacy can sweep it (see ${DOC})`,
  ]
}

/**
 * Reports every undeclaring write site in the SDK and the demos' frontends.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending file, in path order
 */
export function checkRepository(repositoryRoot: string): string[] {
  const lines: string[] = []

  for (const root of scannedRoots(repositoryRoot)) {
    for (const file of sourceFiles(join(repositoryRoot, root))) {
      const relativePath = relative(repositoryRoot, file).split(sep).join('/')
      if (EXCLUDED_PATHS.includes(relativePath)) {
        continue
      }
      lines.push(...checkSource(relativePath, readFileSync(file, 'utf8')))
    }
  }

  return lines
}

/**
 * @param node Node under the walk
 * @returns Whether it declares a browser value in the form this rule reads
 */
function isDeclaringCall(node: ts.Node): boolean {
  return (
    ts.isCallExpression(node) &&
    ts.isIdentifier(node.expression) &&
    node.expression.text === DECLARING_CALL
  )
}

/**
 * @param node Node under the walk
 * @returns Whether it leaves a value in the browser
 */
function isBrowserWrite(node: ts.Node): boolean {
  return isStorageWrite(node) || isCookieWrite(node)
}

/**
 * @param node Node under the walk
 * @returns Whether it writes to session or local storage
 */
function isStorageWrite(node: ts.Node): boolean {
  return (
    ts.isCallExpression(node) &&
    ts.isPropertyAccessExpression(node.expression) &&
    node.expression.name.text === STORAGE_WRITE
  )
}

/**
 * @param node Node under the walk
 * @returns Whether it writes a cookie of this document
 */
function isCookieWrite(node: ts.Node): boolean {
  return (
    ts.isBinaryExpression(node) &&
    COOKIE_ASSIGNMENTS.includes(node.operatorToken.kind) &&
    ts.isPropertyAccessExpression(node.left) &&
    node.left.name.text === COOKIE_PROPERTY
  )
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
 * @returns Absolute paths of the source files below it, in directory order
 */
function sourceFiles(directory: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const full = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...sourceFiles(full))
    } else if (SOURCE_EXTENSIONS.some((one) => entry.name.endsWith(one))) {
      files.push(full)
    }
  }

  return files
}
