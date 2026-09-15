// MODAL-FOCUS, the machine half of "where focus lands when a modal opens" in
// docs/agents/frontend/accessibility.md: every HilosModal mount names the landing,
// and the naming is visible at the mount.
//
// A mount is declared when it carries a static initialFocus / initial-focus of a
// known value ("dialog" or "inner"), or at least one data-autofocus anywhere in
// that mount's own subtree in the same file. At least one, never exactly one —
// mutually exclusive branches may each mark a field. Bound forms are reported,
// not honoured: a form the rule does not know is a hole nobody sees.
//
// Blind spot, named here because nothing else will say it: "inner" is trusted.
// The component that draws the body is not checked for a mark.
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
export const MODAL_FOCUS_RULE_ID = 'MODAL-FOCUS'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/accessibility.md'

/** Known static values of initialFocus; anything else written there is a hit. */
const KNOWN_VALUES = ['dialog', 'inner']

/** The mark a field that should receive focus carries. */
const MARK = 'data-autofocus'

/** Vue's PascalCase mount, as the SFC templates write it. */
const VUE_MODAL = 'HilosModal'

/** Angular's element name, as the inline templates and .html files write it. */
const ANGULAR_MODAL = 'hilos-modal'

/** JSX's component name, as the TSX files write it. */
const JSX_MODAL = 'HilosModal'

/** Vue's static spelling of the prop. */
const VUE_STATIC = 'initial-focus'

/** Vue's bound spellings of the prop; reported, not honoured. */
const VUE_BOUND = [':initial-focus', 'v-bind:initial-focus']

/** Angular's static spelling of the prop. */
const ANGULAR_STATIC = 'initialFocus'

/** Angular's bound spelling of the prop; reported, not honoured. */
const ANGULAR_BOUND = ['[initialFocus]']

/** JSX's prop name; a string literal is honoured, a `{…}` is reported. */
const JSX_PROP = 'initialFocus'

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

/** SDK packages whose sources may mount a modal, from the repository root. */
const SDK_ROOTS = [
  'framework/frontend/vue/src',
  'framework/frontend/react/src',
  'framework/frontend/angular/src',
]

/** Where the demos live; each one's frontend source root is scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Source root inside a demo, appended to that demo's own directory. */
const DEMO_SOURCE_ROOT = 'frontend/src'

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

/** What may open a tag: a name starts with a letter, so `</` and `<!` are not tags. */
const TAG_NAME_START = /[a-zA-Z]/

/** What ends a tag name and begins the run of attributes. */
const TAG_NAME_END = /[\s/>]/

/**
 * One attribute inside the body of a tag. Copied from templateAttributes so this
 * walker can name the tag it belongs to; the shared reader is flat and has no
 * names.
 */
const ATTRIBUTE =
  /([^\s"'=<>/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g

/** A comment, blanked out before the scan so nothing inside it is read. */
const COMMENT = /<!--[\s\S]*?-->/g

/** The one character a blanked comment keeps, so every later line number holds. */
const NEWLINE = '\n'

/** Everything a blanked comment gives up, the newline above excepted. */
const NOT_NEWLINE = /[^\n]/g

/** What every undeclared mount is told. */
const UNDECLARED =
  'this modal does not say where focus lands: mark the element focus belongs on' +
  ' with data-autofocus, or declare initial-focus="dialog" when there is nothing' +
  ' to fill'

/** What a bound form is told: the value cannot be read where it is written. */
const EXPRESSION =
  'initial focus is declared by an expression and cannot be read: write it as a' +
  ' literal, "dialog" or "inner"'

/**
 * Reports every undeclared modal mount in this file, in source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending mount
 */
export function checkSource(relativePath: string, source: string): string[] {
  return offendingMounts(relativePath, source).map(
    (mount) =>
      `${MODAL_FOCUS_RULE_ID} ${relativePath}:${mount.line} — ${mount.message} (see ${DOC})`,
  )
}

/**
 * Reports every undeclared modal mount in the SDK and the demos' frontends.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending mount, in path order
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

/** One mount that failed the rule, with the line of its opening tag. */
interface OffendingMount {
  line: number
  message: string
}

/**
 * @param relativePath Path of the file, which decides what shape it is read in
 * @param source Contents of the file
 * @returns Offending mounts in source order
 */
function offendingMounts(
  relativePath: string,
  source: string,
): OffendingMount[] {
  if (relativePath.endsWith(SFC_EXTENSION)) {
    const block = templateBlock(source)

    return block === null
      ? []
      : markupMounts(
          block.text,
          VUE_MODAL,
          VUE_STATIC,
          VUE_BOUND,
          block.lineOffset,
        )
  }
  if (relativePath.endsWith(MARKUP_EXTENSION)) {
    return markupMounts(source, ANGULAR_MODAL, ANGULAR_STATIC, ANGULAR_BOUND, 0)
  }
  if (
    relativePath.endsWith(JSX_EXTENSION) ||
    relativePath.endsWith(TYPESCRIPT_EXTENSION)
  ) {
    return typeScriptMounts(relativePath, source)
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
 * @returns Offending JSX mounts and Angular inline-template mounts
 */
function typeScriptMounts(
  relativePath: string,
  source: string,
): OffendingMount[] {
  const sourceFile = ts.createSourceFile(
    relativePath,
    source,
    ts.ScriptTarget.Latest,
    true,
  )
  const found: OffendingMount[] = []

  const visit = (node: ts.Node): void => {
    if (ts.isJsxSelfClosingElement(node) && jsxTagName(node) === JSX_MODAL) {
      const judged = judgeJsx(node.attributes, node, sourceFile)
      if (judged !== null) {
        found.push(judged)
      }
    } else if (
      ts.isJsxElement(node) &&
      jsxTagName(node.openingElement) === JSX_MODAL
    ) {
      const judged = judgeJsx(node.openingElement.attributes, node, sourceFile)
      if (judged !== null) {
        found.push(judged)
      }
    } else if (ts.isDecorator(node)) {
      found.push(...angularTemplateMounts(sourceFile, node, source))
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
 * @returns Offending mounts of the component's inline template
 */
function angularTemplateMounts(
  sourceFile: ts.SourceFile,
  decorator: ts.Decorator,
  source: string,
): OffendingMount[] {
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

  const mounts: OffendingMount[] = []
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

    mounts.push(
      ...markupMounts(
        text,
        ANGULAR_MODAL,
        ANGULAR_STATIC,
        ANGULAR_BOUND,
        templateLine,
      ),
    )
  }

  return mounts
}

/**
 * @param attributes Attributes of a JSX HilosModal
 * @param node The opening-like element or the whole element, walked for a mark
 * @param sourceFile Parsed file the element belongs to
 * @returns The offence, or null when the mount is declared
 */
function judgeJsx(
  attributes: ts.JsxAttributes,
  node: ts.Node,
  sourceFile: ts.SourceFile,
): OffendingMount | null {
  const message = verdict(jsxDeclaration(attributes), jsxHasMark(node))
  if (message === null) {
    return null
  }

  return {
    line:
      sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line +
      1,
    message,
  }
}

/**
 * @param attributes Attributes of a JSX HilosModal
 * @returns How initialFocus is written on that mount
 */
function jsxDeclaration(attributes: ts.JsxAttributes): Declaration {
  for (const property of attributes.properties) {
    if (
      !ts.isJsxAttribute(property) ||
      !ts.isIdentifier(property.name) ||
      property.name.text !== JSX_PROP
    ) {
      continue
    }

    const value = property.initializer
    if (value === undefined) {
      return { kind: 'static', value: '' }
    }
    if (ts.isStringLiteral(value)) {
      return { kind: 'static', value: value.text }
    }

    return { kind: 'expression', value: '' }
  }

  return { kind: 'none', value: '' }
}

/**
 * @param node A JSX mount, walked for data-autofocus on itself or a descendant
 * @returns Whether the mark is present in this mount's own subtree
 */
function jsxHasMark(node: ts.Node): boolean {
  let found = false
  const visit = (child: ts.Node): void => {
    if (found) {
      return
    }
    if (
      ts.isJsxAttribute(child) &&
      ts.isIdentifier(child.name) &&
      child.name.text === MARK
    ) {
      found = true
      return
    }
    ts.forEachChild(child, visit)
  }
  visit(node)

  return found
}

/**
 * @param tagName Tag name node of a JSX element
 * @returns The identifier text, or null when the tag is not a simple name
 */
function jsxTagName(
  element: ts.JsxOpeningElement | ts.JsxSelfClosingElement,
): string | null {
  return ts.isIdentifier(element.tagName) ? element.tagName.text : null
}

/** How the prop is written on one mount. */
interface Declaration {
  kind: 'none' | 'static' | 'expression'
  value: string
}

/**
 * Reads every HilosModal / hilos-modal mount in a piece of markup, nesting
 * counted, and reports those that do not name where focus lands.
 *
 * @param markup Template text
 * @param modalName Tag name that is a mount in this dialect
 * @param staticName Unprefixed attribute that carries a literal value
 * @param boundNames Prefixed attributes that bind an expression
 * @param lineOffset Lines standing above this markup in the file it came from
 * @returns Offending mounts, in source order
 */
function markupMounts(
  markup: string,
  modalName: string,
  staticName: string,
  boundNames: string[],
  lineOffset: number,
): OffendingMount[] {
  const found: OffendingMount[] = []

  for (const mount of modalElements(markup, modalName)) {
    const message = verdict(
      markupDeclaration(mount.attributes, staticName, boundNames),
      carriesMark(mount.attributes, mount.subtree),
    )
    if (message !== null) {
      found.push({ line: mount.line + lineOffset, message })
    }
  }

  return found
}

/**
 * @param attributes Attributes of the opening tag
 * @param staticName Unprefixed prop spelling
 * @param boundNames Bound prop spellings
 * @returns How initial focus is written on that mount
 */
function markupDeclaration(
  attributes: TemplateAttribute[],
  staticName: string,
  boundNames: string[],
): Declaration {
  if (attributes.some((attribute) => boundNames.includes(attribute.name))) {
    return { kind: 'expression', value: '' }
  }

  const written = attributes.find((attribute) => attribute.name === staticName)
  if (written === undefined) {
    return { kind: 'none', value: '' }
  }

  return { kind: 'static', value: written.value }
}

/**
 * @param attributes Attributes of the opening tag
 * @param subtree Inner markup of the mount, empty when self-closing
 * @returns Whether data-autofocus is written on the mount or inside it
 */
function carriesMark(
  attributes: TemplateAttribute[],
  subtree: string,
): boolean {
  return (
    attributes.some((attribute) => attribute.name === MARK) ||
    templateAttributes(subtree).some((attribute) => attribute.name === MARK)
  )
}

/**
 * @param declaration How the prop is written
 * @param hasMark Whether the subtree carries data-autofocus
 * @returns The report body, or null when the mount is declared
 */
function verdict(declaration: Declaration, hasMark: boolean): string | null {
  if (
    hasMark ||
    (declaration.kind === 'static' && KNOWN_VALUES.includes(declaration.value))
  ) {
    return null
  }
  if (declaration.kind === 'expression') {
    return EXPRESSION
  }
  if (declaration.kind === 'static') {
    return (
      `initial focus "${declaration.value}" is not a value this rule knows:` +
      ' write "dialog" or "inner"'
    )
  }

  return UNDECLARED
}

/** One modal opening in markup, with the line of its opening tag. */
interface MarkupMount {
  line: number
  attributes: TemplateAttribute[]
  subtree: string
}

/**
 * @param markup Template text
 * @param modalName Tag name that is a mount
 * @returns Every mount of that name, outer first, nesting preserved
 */
function modalElements(markup: string, modalName: string): MarkupMount[] {
  const text = withoutComments(markup)
  const starts = lineStarts(text)
  const mounts: MarkupMount[] = []
  const open: {
    name: string
    line: number
    attributes: TemplateAttribute[]
    innerStart: number
  }[] = []

  let index = 0
  while (index < text.length) {
    if (text[index] !== '<') {
      index += 1
      continue
    }

    if (text[index + 1] === '/') {
      let cursor = index + 2
      while (cursor < text.length && !TAG_NAME_END.test(text[cursor])) {
        cursor += 1
      }
      const name = text.slice(index + 2, cursor)
      while (cursor < text.length && text[cursor] !== '>') {
        cursor += 1
      }
      const opened = lastIndex(
        open,
        (tag) => tag.name.toLowerCase() === name.toLowerCase(),
      )
      if (opened >= 0) {
        const tag = open[opened]
        open.length = opened
        if (tag.name === modalName) {
          mounts.push({
            line: tag.line,
            attributes: tag.attributes,
            subtree: text.slice(tag.innerStart, index),
          })
        }
      }
      index = cursor + 1
      continue
    }

    if (!TAG_NAME_START.test(text[index + 1] ?? '')) {
      index += 1
      continue
    }

    let cursor = index + 1
    while (cursor < text.length && !TAG_NAME_END.test(text[cursor])) {
      cursor += 1
    }
    const name = text.slice(index + 1, cursor)
    const attrStart = cursor
    let quote: string | null = null
    while (cursor < text.length) {
      const character = text[cursor]
      if (quote !== null) {
        if (character === quote) {
          quote = null
        }
      } else if (character === '"' || character === "'") {
        quote = character
      } else if (character === '>') {
        break
      }
      cursor += 1
    }

    const body = text.slice(attrStart, cursor)
    const selfClosing = /\/\s*$/.test(body)
    const attributes = tagAttributes(body, starts, attrStart)
    const line = lineAt(starts, index)
    const innerStart = cursor + 1

    if (selfClosing) {
      if (name === modalName) {
        mounts.push({ line, attributes, subtree: '' })
      }
    } else {
      open.push({ name, line, attributes, innerStart })
    }

    index = innerStart
  }

  for (const tag of open) {
    if (tag.name === modalName) {
      mounts.push({
        line: tag.line,
        attributes: tag.attributes,
        subtree: text.slice(tag.innerStart),
      })
    }
  }

  return mounts
}

/**
 * @param body Attribute run of a tag, not including the name
 * @param starts Line-start offsets of the markup
 * @param bodyStart Offset of that run in the markup
 * @returns Attributes as written, with file-local line numbers
 */
function tagAttributes(
  body: string,
  starts: number[],
  bodyStart: number,
): TemplateAttribute[] {
  const attributes: TemplateAttribute[] = []
  ATTRIBUTE.lastIndex = 0
  let match = ATTRIBUTE.exec(body)
  while (match !== null) {
    attributes.push({
      name: match[1],
      value: match[2] ?? match[3] ?? match[4] ?? '',
      line: lineAt(starts, bodyStart + match.index),
    })
    match = ATTRIBUTE.exec(body)
  }

  return attributes
}

/**
 * @param items Stack of open tags
 * @param matches Whether this entry is the close we just read
 * @returns Index of the matching open, or -1
 */
function lastIndex<T>(items: T[], matches: (item: T) => boolean): number {
  for (let index = items.length - 1; index >= 0; index -= 1) {
    if (matches(items[index])) {
      return index
    }
  }

  return -1
}

/**
 * @param markup Template text
 * @returns The same text with every comment blanked out, offsets and lines intact
 */
function withoutComments(markup: string): string {
  return markup.replace(COMMENT, (comment) => comment.replace(NOT_NEWLINE, ' '))
}

/**
 * @param text Text to index
 * @returns Offset of the first character of every line, in order
 */
function lineStarts(text: string): number[] {
  const starts = [0]

  for (let index = 0; index < text.length; index += 1) {
    if (text[index] === NEWLINE) {
      starts.push(index + 1)
    }
  }

  return starts
}

/**
 * @param starts Offsets of the line starts, as {@link lineStarts} builds them
 * @param offset Offset whose line is wanted
 * @returns Its one-based line number
 */
function lineAt(starts: number[], offset: number): number {
  let low = 0
  let high = starts.length - 1

  while (low < high) {
    const middle = Math.ceil((low + high) / 2)
    if (starts[middle] <= offset) {
      low = middle
    } else {
      high = middle - 1
    }
  }

  return low + 1
}

/**
 * @param repositoryRoot Absolute path of the repository root
 * @returns Files this rule reads, relative to it, in path order
 */
function scannedFiles(repositoryRoot: string): string[] {
  return scannedRoots(repositoryRoot)
    .flatMap((root) => readFiles(join(repositoryRoot, root), repositoryRoot))
    .sort()
}

/**
 * A demo is found by the walk rather than listed, so a new one is covered without
 * an activation step to forget. Most demos are backend-only and have no frontend
 * source root at all, which is why a root that does not exist is skipped rather
 * than reported.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns Existing scanned roots relative to the repository root
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
