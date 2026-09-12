// DISABLED-TITLE, the machine half of the rule in
// docs/agents/frontend/accessibility.md that a control which can be disabled does
// not explain itself with a title. A disabled element gets no mouse events, so the
// browser never shows its title, and a phone or a screen reader never reaches one
// at all: the reason is hidden exactly when it is wanted. It belongs in visible
// content instead — a word in the row, a live "why" button beside the dark action.
//
// What is judged is not the pair "can be disabled + title" but the gap between the
// title and the element's accessible name. A title saying exactly what its
// aria-label says is the name repeated for the mouse, and legal: the name reaches a
// disabled control through aria-label all the same. A title saying anything else,
// or standing on an element that has no aria-label, is content put where nobody
// reads it. Forbidding the pair would break every icon button that repeats its
// name, and allowing any title would catch nothing.
//
// The comparison is lexical, so it reads the same on the three view frameworks and
// never needs to know what a text means. Both sides are compared as written, runs
// of whitespace collapsed: a static value and a quoted string by their text, any
// other expression by its source.
//
// `loading` darkens an element as surely as `disabled` — LoadingButton turns its
// own button off while it loads — so either one makes the element one that can go
// dark. No element of the tree carries a title beside `loading` alone today; the
// form is closed ahead of time, because a hole in a check is seen by nobody.
//
// The rule has no PHP half: nothing outside the frontend writes a template.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

import {
  templateElements,
  type TemplateAttribute,
} from './templateAttributes.js'

/** Rule id, listed once in automated-checks.md. */
export const DISABLED_TITLE_RULE_ID = 'DISABLED-TITLE'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/accessibility.md'

/** What every offending element is told; only the place in front of it differs. */
const MESSAGE =
  'a control that can be disabled carries a title that is not its accessible' +
  ' name; a disabled element gets no mouse events, so the reason must be visible' +
  ' in the row'

/**
 * Every spelling that can turn an element off in a template: `disabled` itself,
 * and `loading`, which LoadingButton turns into `disabled` on its own button.
 */
const DISABLING_ATTRIBUTES = [
  'disabled',
  ':disabled',
  'v-bind:disabled',
  '[disabled]',
  '[attr.disabled]',
  'loading',
  ':loading',
  'v-bind:loading',
  '[loading]',
]

/** Every spelling of a title in a template. */
const TITLE_ATTRIBUTES = [
  'title',
  ':title',
  'v-bind:title',
  '[title]',
  '[attr.title]',
]

/** Every spelling of an accessible name given as `aria-label` in a template. */
const NAME_ATTRIBUTES = [
  'aria-label',
  ':aria-label',
  'v-bind:aria-label',
  '[attr.aria-label]',
]

/** What opens a bound attribute, whose value is an expression and not text. */
const BINDING_PREFIXES = [':', 'v-bind:', '[']

/** The attributes that turn a JSX element off, which JSX writes by bare name. */
const JSX_DISABLING_ATTRIBUTES = ['disabled', 'loading']

/** A title, as JSX writes it. */
const JSX_TITLE_ATTRIBUTE = 'title'

/** An accessible name, as JSX writes it. */
const JSX_NAME_ATTRIBUTE = 'aria-label'

/** What a written text is marked with, so the text `label` never equals the variable `label`. */
const TEXT_MARK = 'text:'

/** What the source of an expression is marked with, for the same reason. */
const SOURCE_MARK = 'source:'

/** A run of whitespace, which says nothing about what an attribute says. */
const WHITESPACE = /\s+/g

/** The decorator carrying an Angular component's inline template. */
const COMPONENT_DECORATOR = 'Component'

/** The property of that decorator holding the template text. */
const TEMPLATE_PROPERTY = 'template'

/**
 * The template block of a Vue SFC, opening and closing at the start of a line.
 * Nested blocks — a `<template>` handed to a slot — are indented and so are not
 * read as the file's own, which is what makes the anchoring load-bearing.
 */
const SFC_TEMPLATE = { open: /^<template[^>]*>$/m, close: /^<\/template>$/m }

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

/**
 * Reports every element in this file that can be disabled and carries a title
 * other than its name, in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending element
 */
export function checkSource(relativePath: string, source: string): string[] {
  return offendingLines(relativePath, source)
    .sort((first, second) => first - second)
    .map(
      (line) =>
        `${DISABLED_TITLE_RULE_ID} ${relativePath}:${line} — ${MESSAGE} (see ${DOC})`,
    )
}

/**
 * Reports every offending element in the SDK and the demos' frontends.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending element, in path order
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
 * @returns The line of the title of every offending element, in no particular order
 */
function offendingLines(relativePath: string, source: string): number[] {
  if (relativePath.endsWith(SFC_EXTENSION)) {
    const block = templateBlock(source)

    return block === null
      ? []
      : elementLines(templateElements(block.text), block.lineOffset)
  }
  if (relativePath.endsWith(MARKUP_EXTENSION)) {
    return elementLines(templateElements(source), 0)
  }
  if (
    relativePath.endsWith(JSX_EXTENSION) ||
    relativePath.endsWith(TYPESCRIPT_EXTENSION)
  ) {
    return typeScriptLines(relativePath, source)
  }

  return []
}

/**
 * @param source Contents of the single-file component
 * @returns The template block's text and the lines standing above it, or null when absent
 */
function templateBlock(
  source: string,
): { text: string; lineOffset: number } | null {
  const opening = SFC_TEMPLATE.open.exec(source)
  if (opening === null) {
    return null
  }

  const start = opening.index + opening[0].length
  const closing = SFC_TEMPLATE.close.exec(source.slice(start))
  const lineOffset = source.slice(0, start).split('\n').length - 1
  const text =
    closing === null
      ? source.slice(start)
      : source.slice(start, start + closing.index)

  return { text, lineOffset }
}

/**
 * @param relativePath Path the parser names the file by; it decides whether JSX is read
 * @param source TypeScript source to read
 * @returns The line of the title of every offending JSX element and Angular template element
 */
function typeScriptLines(relativePath: string, source: string): number[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const found: number[] = []

  const visit = (node: ts.Node): void => {
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      const title = offendingJsxTitle(node.attributes, sourceFile)
      if (title !== null) {
        found.push(
          sourceFile.getLineAndCharacterOfPosition(title.getStart(sourceFile))
            .line + 1,
        )
      }
    } else if (ts.isDecorator(node)) {
      found.push(...angularTemplateLines(sourceFile, node, source))
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return found
}

/**
 * @param sourceFile Parsed file the decorator belongs to
 * @param decorator Decorator written on a class
 * @param source Raw text of that file, read for the template exactly as written
 * @returns The line of the title of every offending element of the component's inline template
 */
function angularTemplateLines(
  sourceFile: ts.SourceFile,
  decorator: ts.Decorator,
  source: string,
): number[] {
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

  const lines: number[] = []
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

    lines.push(...elementLines(templateElements(text), templateLine))
  }

  return lines
}

/**
 * @param elements The attributes of every element of a piece of markup
 * @param lineOffset Lines standing above that markup in the file it came from
 * @returns The line of the title of every offending element among them
 */
function elementLines(
  elements: TemplateAttribute[][],
  lineOffset: number,
): number[] {
  const lines: number[] = []

  for (const attributes of elements) {
    const title = offendingTitle(attributes)
    if (title !== null) {
      lines.push(title.line + lineOffset)
    }
  }

  return lines
}

/**
 * @param attributes Every attribute of one template element
 * @returns Its title when the element can be disabled and the title is not its name, or null
 */
function offendingTitle(
  attributes: TemplateAttribute[],
): TemplateAttribute | null {
  const title = attributes.find((attribute) =>
    TITLE_ATTRIBUTES.includes(attribute.name),
  )
  if (
    title === undefined ||
    !attributes.some((attribute) =>
      DISABLING_ATTRIBUTES.includes(attribute.name),
    )
  ) {
    return null
  }

  const name = attributes.find((attribute) =>
    NAME_ATTRIBUTES.includes(attribute.name),
  )

  return name !== undefined && templateSays(name) === templateSays(title)
    ? null
    : title
}

/**
 * @param attributes The attributes of one JSX element
 * @param sourceFile Parsed file the element belongs to
 * @returns Its title when the element can be disabled and the title is not its name, or null
 */
function offendingJsxTitle(
  attributes: ts.JsxAttributes,
  sourceFile: ts.SourceFile,
): ts.JsxAttribute | null {
  const named = new Map<string, ts.JsxAttribute>()
  for (const property of attributes.properties) {
    if (ts.isJsxAttribute(property) && ts.isIdentifier(property.name)) {
      named.set(property.name.text, property)
    }
  }

  const title = named.get(JSX_TITLE_ATTRIBUTE)
  if (
    title === undefined ||
    !JSX_DISABLING_ATTRIBUTES.some((name) => named.has(name))
  ) {
    return null
  }

  const name = named.get(JSX_NAME_ATTRIBUTE)

  return name !== undefined &&
    jsxSays(name, sourceFile) === jsxSays(title, sourceFile)
    ? null
    : title
}

/**
 * @param attribute A title or an aria-label as a template writes it
 * @returns What it says, comparable across the spellings of both attributes
 */
function templateSays(attribute: TemplateAttribute): string {
  if (!BINDING_PREFIXES.some((prefix) => attribute.name.startsWith(prefix))) {
    return asText(attribute.value)
  }

  const parsed = ts.createSourceFile(
    'expression.ts',
    `(${attribute.value})`,
    ts.ScriptTarget.Latest,
    true,
  )
  const [statement] = parsed.statements
  if (
    statement === undefined ||
    !ts.isExpressionStatement(statement) ||
    !ts.isParenthesizedExpression(statement.expression)
  ) {
    return asSource(attribute.value)
  }

  return expressionSays(statement.expression.expression, parsed)
}

/**
 * @param attribute A title or an aria-label as JSX writes it
 * @param sourceFile Parsed file the attribute belongs to
 * @returns What it says, comparable with the other attribute of the pair
 */
function jsxSays(
  attribute: ts.JsxAttribute,
  sourceFile: ts.SourceFile,
): string {
  const value = attribute.initializer
  if (value === undefined) {
    return asText('')
  }
  if (ts.isStringLiteral(value)) {
    return asText(value.text)
  }
  if (ts.isJsxExpression(value) && value.expression !== undefined) {
    return expressionSays(value.expression, sourceFile)
  }

  return asSource(value.getText(sourceFile))
}

/**
 * @param expression Expression an attribute binds
 * @param sourceFile Parsed file the expression belongs to
 * @returns A quoted string by its text, anything else by its source
 */
function expressionSays(
  expression: ts.Expression,
  sourceFile: ts.SourceFile,
): string {
  if (
    ts.isStringLiteral(expression) ||
    ts.isNoSubstitutionTemplateLiteral(expression)
  ) {
    return asText(expression.text)
  }

  return asSource(expression.getText(sourceFile))
}

/**
 * @param text A text an attribute says as written
 * @returns The text marked as text, its whitespace collapsed
 */
function asText(text: string): string {
  return TEXT_MARK + text.replace(WHITESPACE, ' ').trim()
}

/**
 * @param source The source of an expression an attribute binds
 * @returns The source marked as source, its whitespace collapsed
 */
function asSource(source: string): string {
  return SOURCE_MARK + source.replace(WHITESPACE, ' ').trim()
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
