// STYLE-INLINE, the machine half of the inline-style ban in
// docs/agents/frontend/styling-rules.md: an element carries no hand-authored
// declaration.
//
// What is judged is the NAME of every property a site sets, not static versus
// dynamic. A site is legal only when every one of those names is a CSS custom
// property (`--*`) — the one channel a computed value has, with the rule that
// consumes it living in the Sass layer beside its WHY comment. One criterion
// covers all three view frameworks, and no spelling launders a normal property
// through a variable: a set of names that cannot be read where it is written is
// a violation of its own, because passing it would make the ban one indirection
// deep.
//
// Every spelling of every framework is read, and the imperative write with them.
// A form the rule does not know is a hole nobody sees, and the imperative one
// costs nothing today — the tree has none — while closing the way around a
// checker that reads templates only.
//
// The rule has no PHP half: nothing outside the frontend writes a template.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

import {
  templateAttributes,
  type TemplateAttribute,
} from './templateAttributes.js'

/** Rule id, listed once in automated-checks.md. */
export const STYLE_INLINE_RULE_ID = 'STYLE-INLINE'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/styling-rules.md'

/** The one legal prefix: a custom property, and nothing else, may be set inline. */
const CUSTOM_PROPERTY = '--'

/** Attributes whose value is plain CSS text: `style="max-width: 18rem"`. */
const CSS_TEXT_ATTRIBUTES = ['style']

/**
 * Attributes whose value is an expression that must evaluate to a map of
 * declarations — Vue's two bindings, Angular's three. `ngStyle` without brackets
 * is read the same way: it names the same directive, and a form left unread is a
 * hole.
 */
const EXPRESSION_ATTRIBUTES = [
  ':style',
  'v-bind:style',
  '[style]',
  '[ngStyle]',
  'ngStyle',
]

/** Angular's per-property binding, which names the property in the attribute itself. */
const PROPERTY_ATTRIBUTE = /^\[style\.([^.\]]+)(?:\.[^\]]+)?\]$/

/** The JSX attribute that carries a style, whose value is always an expression. */
const JSX_STYLE_ATTRIBUTE = 'style'

/** The property an element's declarations hang off, in the imperative form. */
const STYLE_PROPERTY = 'style'

/** The imperative call that sets one declaration by name. */
const SET_PROPERTY = 'setProperty'

/** The decorator carrying an Angular component's inline template. */
const COMPONENT_DECORATOR = 'Component'

/** The property of that decorator holding the template text. */
const TEMPLATE_PROPERTY = 'template'

/**
 * A top-level block of a Vue SFC, opening and closing at the start of a line.
 * Nested blocks — a `<template>` handed to a slot — are indented and so are not
 * read as the file's own, which is what makes the anchoring load-bearing.
 */
const SFC_TEMPLATE = { open: /^<template[^>]*>$/m, close: /^<\/template>$/m }

/** The other block, read for the imperative write rather than for attributes. */
const SFC_SCRIPT = { open: /^<script[^>]*>$/m, close: /^<\/script>$/m }

/** Said of a site that sets nothing forbidden, so no line is written about it. */
const LEGAL = ''

/** The SDK's own root; everything below it is frontend code. */
const FRAMEWORK_ROOT = 'framework/frontend'

/** Where the demos live; each one's frontend root is scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Frontend root inside a demo, appended to that demo's own directory. */
const DEMO_ROOT = 'frontend'

/** The four shapes a template can arrive in. */
const SFC_EXTENSION = '.vue'
const JSX_EXTENSION = '.tsx'
const TYPESCRIPT_EXTENSION = '.ts'
const MARKUP_EXTENSION = '.html'

/**
 * Never walked: a dependency tree is not this repository's code, and a build
 * artifact is a copy of code that was already judged at its source.
 */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
]

/**
 * Left out of a scanned root by exact path rather than by directory name, the
 * way the PHP guard leaves out its own. The checkers' fixtures are broken on
 * purpose and are judged by the fixture tests instead.
 */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/** One found site, before it is turned into a line, so a file can be sorted. */
interface Site {
  line: number
  what: string
}

/**
 * Reports every element in this file that carries a hand-authored declaration,
 * in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending site
 */
export function checkSource(relativePath: string, source: string): string[] {
  return sites(relativePath, source)
    .sort((first, second) => first.line - second.line)
    .map((site) => report(relativePath, site))
}

/**
 * Reports every hand-authored declaration in the SDK and the demos' frontends.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending site, in path order
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
 * @param relativePath Path of the file, which decides what shape it is read in
 * @param source Contents of the file
 * @returns Every offending site of the file, in no particular order
 */
function sites(relativePath: string, source: string): Site[] {
  if (relativePath.endsWith(SFC_EXTENSION)) {
    return [...singleFileComponent(source), ...componentScript(source)]
  }
  if (relativePath.endsWith(MARKUP_EXTENSION)) {
    return attributeSites(templateAttributes(source), 0)
  }
  if (
    relativePath.endsWith(JSX_EXTENSION) ||
    relativePath.endsWith(TYPESCRIPT_EXTENSION)
  ) {
    return typeScriptSites(relativePath, source, 0)
  }

  return []
}

/**
 * @param source Contents of the single-file component
 * @returns Offending sites of its template block
 */
function singleFileComponent(source: string): Site[] {
  const block = topLevelBlock(source, SFC_TEMPLATE)
  if (block === null) {
    return []
  }

  return attributeSites(templateAttributes(block.text), block.lineOffset)
}

/**
 * The script block of an SFC holds ordinary TypeScript, and the imperative write
 * lands there as readily as in a `.ts` file.
 *
 * @param source Contents of the single-file component
 * @returns Offending sites of its script block
 */
function componentScript(source: string): Site[] {
  const block = topLevelBlock(source, SFC_SCRIPT)
  if (block === null) {
    return []
  }

  return typeScriptSites('sfc.ts', block.text, block.lineOffset)
}

/**
 * @param source Contents of the file
 * @param block Patterns matching the lines that open and close the block
 * @returns The block's text and the lines standing above it, or null when absent
 */
function topLevelBlock(
  source: string,
  block: { open: RegExp; close: RegExp },
): { text: string; lineOffset: number } | null {
  const opening = block.open.exec(source)
  if (opening === null) {
    return null
  }

  const start = opening.index + opening[0].length
  const closing = block.close.exec(source.slice(start))
  const lineOffset = source.slice(0, start).split('\n').length - 1
  const text =
    closing === null
      ? source.slice(start)
      : source.slice(start, start + closing.index)

  return { text, lineOffset }
}

/**
 * @param relativePath Path the parser names the file by; only diagnostics see it
 * @param source TypeScript source to read
 * @param lineOffset Lines standing above this source in the file it came from
 * @returns Offending sites: JSX attributes, Angular templates, imperative writes
 */
function typeScriptSites(
  relativePath: string,
  source: string,
  lineOffset: number,
): Site[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const found: Site[] = []
  const lineOf = (node: ts.Node): number =>
    sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line +
    1 +
    lineOffset

  const visit = (node: ts.Node): void => {
    if (
      ts.isJsxAttribute(node) &&
      jsxAttributeName(node) === JSX_STYLE_ATTRIBUTE
    ) {
      found.push({ line: lineOf(node), what: verdict(jsxProperties(node)) })
    } else if (ts.isDecorator(node)) {
      found.push(...angularTemplate(sourceFile, node, source, lineOffset))
    } else if (isStyleWrite(node)) {
      found.push({
        line: lineOf(node),
        what: verdict([node.left.name.text]),
      })
    } else if (isSetPropertyCall(node)) {
      found.push({ line: lineOf(node), what: verdict(setPropertyName(node)) })
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return found.filter((site) => site.what !== LEGAL)
}

/**
 * @param sourceFile Parsed file the decorator belongs to
 * @param decorator Decorator written on a class
 * @param source Raw text of that file, read for the template exactly as written
 * @param lineOffset Lines standing above this source in the file it came from
 * @returns Offending sites of the component's inline template
 */
function angularTemplate(
  sourceFile: ts.SourceFile,
  decorator: ts.Decorator,
  source: string,
  lineOffset: number,
): Site[] {
  const call = decorator.expression
  if (
    !ts.isCallExpression(call) ||
    !ts.isIdentifier(call.expression) ||
    call.expression.text !== COMPONENT_DECORATOR
  ) {
    return []
  }

  const [argument] = call.arguments
  if (argument === undefined || !ts.isObjectLiteralExpression(argument)) {
    return []
  }

  const sites: Site[] = []
  for (const property of argument.properties) {
    if (
      !ts.isPropertyAssignment(property) ||
      !ts.isIdentifier(property.name) ||
      property.name.text !== TEMPLATE_PROPERTY
    ) {
      continue
    }

    // The template is read from the raw source, quotes stripped, rather than from
    // the node's cooked text: an escape or a `${…}` would shift every offset
    // after it, and with it every line the report names.
    const start = property.initializer.getStart(sourceFile) + 1
    const text = source.slice(start, property.initializer.getEnd() - 1)
    const templateLine = source.slice(0, start).split('\n').length - 1

    sites.push(
      ...attributeSites(templateAttributes(text), templateLine + lineOffset),
    )
  }

  return sites
}

/**
 * @param attributes Attributes the markup carries
 * @param lineOffset Lines standing above that markup in the file it came from
 * @returns Offending sites among them
 */
function attributeSites(
  attributes: TemplateAttribute[],
  lineOffset: number,
): Site[] {
  const sites: Site[] = []

  for (const attribute of attributes) {
    const what = attributeVerdict(attribute)
    if (what !== LEGAL) {
      sites.push({ line: attribute.line + lineOffset, what })
    }
  }

  return sites
}

/**
 * @param attribute One attribute of one element
 * @returns What is wrong with it, or {@link LEGAL} when it is not a style at all
 */
function attributeVerdict(attribute: TemplateAttribute): string {
  if (CSS_TEXT_ATTRIBUTES.includes(attribute.name)) {
    return verdict(declarationNames(attribute.value))
  }
  if (EXPRESSION_ATTRIBUTES.includes(attribute.name)) {
    return verdict(expressionProperties(attribute.value))
  }

  const bound = PROPERTY_ATTRIBUTE.exec(attribute.name)

  return bound === null ? LEGAL : verdict([bound[1]])
}

/**
 * @param css Value of a plain `style` attribute
 * @returns The property name of every declaration in it, in written order
 */
function declarationNames(css: string): string[] {
  return css
    .split(';')
    .map((declaration) => declaration.split(':')[0].trim())
    .filter((name) => name !== '')
}

/**
 * @param attribute A JSX `style` attribute
 * @returns The names it sets, or null when they cannot be read where they stand
 */
function jsxProperties(attribute: ts.JsxAttribute): string[] | null {
  const value = attribute.initializer
  if (
    value === undefined ||
    !ts.isJsxExpression(value) ||
    value.expression === undefined
  ) {
    return null
  }

  return objectProperties(value.expression)
}

/**
 * @param expression Text of a binding's expression, as the attribute writes it
 * @returns The names it sets, or null when they cannot be read where they stand
 */
function expressionProperties(expression: string): string[] | null {
  const parsed = ts.createSourceFile(
    'expression.ts',
    `(${expression})`,
    ts.ScriptTarget.Latest,
    true,
  )
  const [statement] = parsed.statements
  if (statement === undefined || !ts.isExpressionStatement(statement)) {
    return null
  }

  return objectProperties(
    ts.isParenthesizedExpression(statement.expression)
      ? statement.expression.expression
      : statement.expression,
  )
}

/**
 * A binding may hand over one map of declarations or an array of them, and both
 * are read the same way: every name of every map, or nothing at all.
 *
 * @param expression Expression the binding evaluates
 * @returns The names it sets, or null when they cannot be read where they stand
 */
function objectProperties(expression: ts.Expression): string[] | null {
  if (ts.isArrayLiteralExpression(expression)) {
    const names: string[] = []
    for (const element of expression.elements) {
      const own = objectProperties(element)
      if (own === null) {
        return null
      }
      names.push(...own)
    }

    return names
  }

  if (!ts.isObjectLiteralExpression(expression)) {
    return null
  }

  const names: string[] = []
  for (const property of expression.properties) {
    if (property.name === undefined) {
      return null
    }
    if (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)) {
      names.push(property.name.text)
      continue
    }

    return null
  }

  return names
}

/**
 * @param node Node under the walk
 * @returns Whether it writes one declaration onto an element's style
 */
function isStyleWrite(
  node: ts.Node,
): node is ts.BinaryExpression & { left: ts.PropertyAccessExpression } {
  return (
    ts.isBinaryExpression(node) &&
    node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
    ts.isPropertyAccessExpression(node.left) &&
    isStyleAccess(node.left.expression)
  )
}

/**
 * @param node Node under the walk
 * @returns Whether it sets one declaration by name through the DOM API
 */
function isSetPropertyCall(node: ts.Node): node is ts.CallExpression {
  return (
    ts.isCallExpression(node) &&
    ts.isPropertyAccessExpression(node.expression) &&
    node.expression.name.text === SET_PROPERTY &&
    isStyleAccess(node.expression.expression)
  )
}

/**
 * @param expression Receiver of a write or a call
 * @returns Whether it is an element's `style`
 */
function isStyleAccess(expression: ts.Expression): boolean {
  return (
    ts.isPropertyAccessExpression(expression) &&
    expression.name.text === STYLE_PROPERTY
  )
}

/**
 * @param call A `setProperty` call on an element's style
 * @returns The name it sets, or null when the argument is not written out
 */
function setPropertyName(call: ts.CallExpression): string[] | null {
  const [name] = call.arguments

  return name !== undefined && ts.isStringLiteralLike(name) ? [name.text] : null
}

/**
 * @param attribute A JSX attribute
 * @returns Its name, or the empty string for a spread, which carries none
 */
function jsxAttributeName(attribute: ts.JsxAttribute): string {
  return ts.isIdentifier(attribute.name) ? attribute.name.text : ''
}

/**
 * @param properties The names a site sets, or null when they cannot be read
 * @returns What is wrong with the site, or {@link LEGAL} when nothing is
 */
function verdict(properties: string[] | null): string {
  if (properties === null) {
    return (
      'the properties this style sets are not visible here; write the object' +
      ' literal in the template so the rule can read it'
    )
  }

  const forbidden = properties.find((name) => !name.startsWith(CUSTOM_PROPERTY))

  return forbidden === undefined
    ? LEGAL
    : `an element sets '${forbidden}' inline; styling is Bootstrap classes, and` +
        ' only a CSS custom property (--*) may carry a computed value'
}

/**
 * @param relativePath Path of the file as it appears in the report
 * @param site One offending site of that file
 * @returns One finished report line
 */
function report(relativePath: string, site: Site): string {
  return `${STYLE_INLINE_RULE_ID} ${relativePath}:${site.line} — ${site.what} (see ${DOC})`
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Files this rule reads, relative to it, in path order
 */
function scannedFiles(repositoryRoot: string): string[] {
  const demoDirectory = join(repositoryRoot, DEMO_DIRECTORY)
  const demos = existsSync(demoDirectory)
    ? readdirSync(demoDirectory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${DEMO_DIRECTORY}/${entry.name}/${DEMO_ROOT}`)
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
 * @param repositoryRoot Absolute path the returned paths are addressed from
 * @returns Readable files below it, addressed from the repository root
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
    } else if (isRead(entry.name)) {
      files.push(relativePath)
    }
  }

  return files
}

/**
 * @param name File name
 * @returns Whether the rule reads a file of that shape
 */
function isRead(name: string): boolean {
  return [
    SFC_EXTENSION,
    JSX_EXTENSION,
    TYPESCRIPT_EXTENSION,
    MARKUP_EXTENSION,
  ].some((extension) => name.endsWith(extension))
}
