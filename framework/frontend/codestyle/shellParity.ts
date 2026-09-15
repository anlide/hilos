// SHELL-PARITY, the machine half of the SDK primitive parity rule in
// docs/agents/frontend/multiframework-core.md: a primitive represented in Vue
// is represented in React and Angular too.
//
// This is deliberately a lexical sieve, not a conformance proof. It cannot see
// a data-id assembled wholly from a variable or call, a surface first added
// outside Vue, or a behavioral difference behind equal handles (HIL-902 is the
// known example). Angular templateUrl is not followed either: the tree carries
// no external Angular template today, and following one would make the reader
// a second traversal rather than the single walk this rule promises.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const SHELL_PARITY_RULE_ID = 'SHELL-PARITY'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/frontend/multiframework-core.md'

/** View layers whose SDK surfaces are compared, in report order. */
const SHELLS = ['vue', 'react', 'angular'] as const

/** The view layer whose surfaces define what the other two must carry. */
const REFERENCE_SHELL: Shell = 'vue'

/** Root of one view layer, below the tree handed to {@link checkTree}. */
const shellRoot = (shell: Shell): string => `framework/frontend/${shell}/src`

/** The source forms this lexical rule reads. */
const READ_EXTENSIONS = ['.vue', '.ts', '.tsx', '.html']

/** Test sources describe handles without rendering surfaces and stay out. */
const TEST_FILE_MARKERS = ['.test.', '.spec.']

/** Dependency and build trees contain copies rather than authored surfaces. */
const SKIPPED_DIRECTORIES = [
  'node_modules',
  'dist',
  'dist-pack',
  'dist-prerender',
  '.angular',
]

/** Broken checker fixtures are judged only by their own focused tests. */
const EXCLUDED_PATHS = ['framework/frontend/codestyle/fixtures']

/** Every spelling in which the three view layers author a data-id. */
const DATA_ID_ATTRIBUTE =
  /(^|\s)(data-id|:data-id|v-bind:data-id|\[attr\.data-id\])\s*=\s*/g

/** A public re-export block in a package's root index. */
const EXPORT_BLOCK =
  /\bexport\s+(type\s+)?\{([\s\S]*?)\}\s+from\s+['"][^'"]+['"]/g

/** A component name, as distinct from a hook, key, or other adapter idiom. */
const COMPONENT_NAME = /^[A-Z][A-Za-z0-9]*$/

/** The unindented block delimiters of a Vue single-file component. */
const SFC_TEMPLATE = { open: /^<template[^>]*>$/m, close: /^<\/template>$/m }

/** One of the three view layers compared by the rule. */
type Shell = (typeof SHELLS)[number]

/** One readable surface occurrence in a source file. */
interface SurfaceSite {
  name: string
  path: string
  line: number
}

/** One public component export in a package's root index. */
interface ExportSite {
  name: string
  path: string
  line: number
}

/** Everything collected for one shell during its one directory traversal. */
interface ShellInventory {
  surfaces: SurfaceSite[]
  exports: ExportSite[]
}

/** A report body before path and line ordering is applied. */
interface Finding {
  path: string
  line: number
  message: string
}

/** A lexical attribute value and the offset immediately after it. */
interface AttributeValue {
  text: string
  expression: boolean
  end: number
}

/**
 * Reports every Vue SDK surface or component export missing from React or
 * Angular, using paths relative to the handed tree.
 *
 * @param root Absolute root containing framework/frontend/{vue,react,angular}/src
 * @returns One finished report line per unmatched Vue site, in path and line order
 */
export function checkTree(root: string): string[] {
  const inventory = new Map<Shell, ShellInventory>()

  for (const shell of SHELLS) {
    inventory.set(shell, readShell(root, shell))
  }

  return findings(inventory)
    .sort(compareFindings)
    .map(
      (finding) =>
        `${SHELL_PARITY_RULE_ID} ${finding.path}:${finding.line} — ${finding.message}` +
        ` (see ${DOC})`,
    )
}

/**
 * Reports shell-parity drift in the repository SDK roots.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per unmatched Vue site, in path and line order
 */
export function checkRepository(repositoryRoot: string): string[] {
  return checkTree(repositoryRoot)
}

/**
 * @param root Root containing the three framework view packages
 * @param shell View layer to read
 * @returns Surfaces and exports collected during one traversal of that shell
 */
function readShell(root: string, shell: Shell): ShellInventory {
  const relativeRoot = shellRoot(shell)
  const directory = join(root, relativeRoot)
  const inventory: ShellInventory = { surfaces: [], exports: [] }

  if (!existsSync(directory)) {
    return inventory
  }

  for (const path of filesUnder(directory, root)) {
    const source = readFileSync(join(root, path), 'utf8')
    inventory.surfaces.push(...surfaceSites(path, source))
    if (path === `${relativeRoot}/index.ts`) {
      inventory.exports.push(...exportSites(path, source))
    }
  }

  return inventory
}

/**
 * @param inventory Surfaces and exports of all three view layers
 * @returns Every mismatch carried by the Vue reference layer
 */
function findings(inventory: Map<Shell, ShellInventory>): Finding[] {
  const reference = inventory.get(REFERENCE_SHELL) ?? {
    surfaces: [],
    exports: [],
  }
  const surfaceNames = namesByShell(inventory, 'surfaces')
  const exportNames = namesByShell(inventory, 'exports')
  const found: Finding[] = []

  for (const site of reference.surfaces) {
    const missing = missingShells(site.name, surfaceNames)
    if (missing.length > 0) {
      found.push({
        path: site.path,
        line: site.line,
        message: `the surface '${site.name}' has no counterpart in ${missing.join(', ')}`,
      })
    }
  }

  for (const site of reference.exports) {
    const missing = missingShells(site.name, exportNames)
    if (missing.length > 0) {
      found.push({
        path: site.path,
        line: site.line,
        message: `the component '${site.name}' is exported by vue and not by ${missing.join(', ')}`,
      })
    }
  }

  return found
}

/**
 * @param inventory Surfaces and exports of all three view layers
 * @param key Inventory half whose names are wanted
 * @returns Names present in each shell
 */
function namesByShell(
  inventory: Map<Shell, ShellInventory>,
  key: keyof ShellInventory,
): Map<Shell, Set<string>> {
  return new Map(
    SHELLS.map((shell) => [
      shell,
      new Set((inventory.get(shell)?.[key] ?? []).map((site) => site.name)),
    ]),
  )
}

/**
 * @param name Surface or export name carried by Vue
 * @param names Names present in each shell
 * @returns Non-reference shells missing that name, in report order
 */
function missingShells(name: string, names: Map<Shell, Set<string>>): Shell[] {
  return SHELLS.filter(
    (shell) => shell !== REFERENCE_SHELL && !names.get(shell)?.has(name),
  )
}

/**
 * @param relativePath Source path relative to the checked tree
 * @param source Source text
 * @returns Readable data-id sites in source order
 */
function surfaceSites(relativePath: string, source: string): SurfaceSite[] {
  const block = markupBlock(relativePath, source)
  if (block === null) {
    return []
  }

  const text = withoutComments(relativePath, block.text)
  const starts = lineStarts(source)
  const sites: SurfaceSite[] = []

  for (const tag of tagBodies(text)) {
    DATA_ID_ATTRIBUTE.lastIndex = 0
    let match = DATA_ID_ATTRIBUTE.exec(tag.text)
    while (match !== null) {
      const valueStart = match.index + match[0].length
      const value = attributeValue(tag.text, valueStart)
      const name = normalizeDataId(match[2], value)
      if (name !== null) {
        sites.push({
          name,
          path: relativePath,
          line: lineAt(
            starts,
            block.start + tag.start + match.index + match[1].length,
          ),
        })
      }
      DATA_ID_ATTRIBUTE.lastIndex = Math.max(
        value.end,
        DATA_ID_ATTRIBUTE.lastIndex,
      )
      match = DATA_ID_ATTRIBUTE.exec(tag.text)
    }
  }

  return sites
}

/**
 * @param relativePath Path deciding whether the source is a Vue SFC
 * @param source Source text
 * @returns Markup to scan and its source offset, or null without an SFC template
 */
function markupBlock(
  relativePath: string,
  source: string,
): { text: string; start: number } | null {
  if (!relativePath.endsWith('.vue')) {
    return { text: source, start: 0 }
  }

  const opening = SFC_TEMPLATE.open.exec(source)
  if (opening === null) {
    return null
  }

  const start = opening.index + opening[0].length
  const closing = SFC_TEMPLATE.close.exec(source.slice(start))

  return {
    text:
      closing === null
        ? source.slice(start)
        : source.slice(start, start + closing.index),
    start,
  }
}

/**
 * @param relativePath Root index path relative to the checked tree
 * @param source Source text
 * @returns UpperCamelCase value exports in source order
 */
function exportSites(relativePath: string, source: string): ExportSite[] {
  const text = withoutComments(relativePath, source)
  const starts = lineStarts(text)
  const sites: ExportSite[] = []
  EXPORT_BLOCK.lastIndex = 0

  let block = EXPORT_BLOCK.exec(text)
  while (block !== null) {
    if (block[1] === undefined) {
      const contents = block[2]
      const contentsStart = block.index + block[0].indexOf(contents)
      for (const entry of commaSeparated(contents)) {
        const name = exportedName(entry.text)
        if (name !== null && COMPONENT_NAME.test(name)) {
          const nameOffset = entry.text.lastIndexOf(name)
          sites.push({
            name,
            path: relativePath,
            line: lineAt(starts, contentsStart + entry.start + nameOffset),
          })
        }
      }
    }
    block = EXPORT_BLOCK.exec(text)
  }

  return sites
}

/**
 * @param text Comma-separated export entries
 * @returns Entries with their offsets in the handed text
 */
function commaSeparated(text: string): { text: string; start: number }[] {
  const entries: { text: string; start: number }[] = []
  let start = 0

  for (let index = 0; index <= text.length; index += 1) {
    if (index === text.length || text[index] === ',') {
      entries.push({ text: text.slice(start, index), start })
      start = index + 1
    }
  }

  return entries
}

/**
 * @param entry One entry inside an export block
 * @returns Its public value name, or null for a type or unreadable entry
 */
function exportedName(entry: string): string | null {
  const trimmed = entry.trim()
  if (trimmed === '' || trimmed.startsWith('type ')) {
    return null
  }

  const alias = /\bas\s+([A-Za-z_$][\w$]*)$/.exec(trimmed)
  if (alias !== null) {
    return alias[1]
  }

  const direct = /^([A-Za-z_$][\w$]*)$/.exec(trimmed)

  return direct?.[1] ?? null
}

/**
 * @param attribute Attribute spelling as authored by its view layer
 * @param value Lexical value after its equals sign
 * @returns Literal handle or readable dynamic prefix, null when opaque
 */
function normalizeDataId(
  attribute: string,
  value: AttributeValue,
): string | null {
  if (attribute === 'data-id' && !value.expression) {
    return value.text
  }

  const expression = value.text.trim()
  const literal = /^(['"])([\s\S]*)\1$/.exec(expression)
  if (literal !== null) {
    return literal[2]
  }

  if (expression.startsWith('`') && expression.endsWith('`')) {
    const body = expression.slice(1, -1)
    const interpolation = body.indexOf('${')
    if (interpolation < 0) {
      return body
    }

    const prefix = body.slice(0, interpolation)

    return prefix === '' ? null : `${prefix}*`
  }

  const concatenation = /^(['"])(.*?)\1\s*\+/.exec(expression)
  if (concatenation !== null && concatenation[2] !== '') {
    return `${concatenation[2]}*`
  }

  return null
}

/**
 * @param text Attribute run of one tag
 * @param start Offset immediately after the equals sign
 * @returns The value and where scanning may safely resume
 */
function attributeValue(text: string, start: number): AttributeValue {
  const opening = text[start]
  if (opening === '"' || opening === "'") {
    const end = quotedEnd(text, start, opening)

    return {
      text: text.slice(start + 1, end),
      expression: false,
      end: Math.min(end + 1, text.length),
    }
  }
  if (opening === '{') {
    const end = bracedEnd(text, start)

    return {
      text: text.slice(start + 1, end),
      expression: true,
      end: Math.min(end + 1, text.length),
    }
  }

  let end = start
  while (end < text.length && !/\s/.test(text[end])) {
    end += 1
  }

  return { text: text.slice(start, end), expression: true, end }
}

/**
 * @param text Text containing a quoted value
 * @param start Offset of the opening quote
 * @param quote Quote character
 * @returns Offset of its closing quote, or the text end when unterminated
 */
function quotedEnd(text: string, start: number, quote: string): number {
  for (let index = start + 1; index < text.length; index += 1) {
    if (text[index] === quote && text[index - 1] !== '\\') {
      return index
    }
  }

  return text.length
}

/**
 * @param text Text containing a JSX expression container
 * @param start Offset of its opening brace
 * @returns Offset of the matching closing brace, or the text end
 */
function bracedEnd(text: string, start: number): number {
  let depth = 1
  let quote: string | null = null

  for (let index = start + 1; index < text.length; index += 1) {
    const character = text[index]
    if (quote !== null) {
      if (character === quote && text[index - 1] !== '\\') {
        quote = null
      }
      continue
    }
    if (character === '"' || character === "'" || character === '`') {
      quote = character
    } else if (character === '{') {
      depth += 1
    } else if (character === '}') {
      depth -= 1
      if (depth === 0) {
        return index
      }
    }
  }

  return text.length
}

/**
 * @param source Source text with comments already blanked
 * @returns Opening-tag bodies and their offsets, in source order
 */
function tagBodies(source: string): { text: string; start: number }[] {
  const bodies: { text: string; start: number }[] = []
  let index = 0

  while (index < source.length) {
    if (source[index] !== '<' || !/[A-Za-z]/.test(source[index + 1] ?? '')) {
      index += 1
      continue
    }

    let cursor = index + 1
    while (cursor < source.length && !/[\s/>]/.test(source[cursor])) {
      cursor += 1
    }
    const start = cursor
    let quote: string | null = null
    let braces = 0
    while (cursor < source.length) {
      const character = source[cursor]
      if (quote !== null) {
        if (character === quote && source[cursor - 1] !== '\\') {
          quote = null
        }
      } else if (character === '"' || character === "'" || character === '`') {
        quote = character
      } else if (character === '{') {
        braces += 1
      } else if (character === '}') {
        braces = Math.max(0, braces - 1)
      } else if (character === '>' && braces === 0) {
        break
      }
      cursor += 1
    }

    bodies.push({ text: source.slice(start, cursor), start })
    index = cursor + 1
  }

  return bodies
}

/**
 * @param relativePath Path deciding whether TypeScript comments are meaningful
 * @param source Source text
 * @returns Text with comments blanked while every offset and line stays fixed
 */
function withoutComments(relativePath: string, source: string): string {
  let text = source.replace(/<!--[\s\S]*?-->/g, blank)
  if (!relativePath.endsWith('.ts') && !relativePath.endsWith('.tsx')) {
    return text
  }

  const scanner = ts.createScanner(
    ts.ScriptTarget.Latest,
    false,
    relativePath.endsWith('.tsx')
      ? ts.LanguageVariant.JSX
      : ts.LanguageVariant.Standard,
    text,
  )
  const comments: { start: number; end: number }[] = []
  let token = scanner.scan()
  while (token !== ts.SyntaxKind.EndOfFileToken) {
    if (
      token === ts.SyntaxKind.SingleLineCommentTrivia ||
      token === ts.SyntaxKind.MultiLineCommentTrivia
    ) {
      comments.push({ start: scanner.getTokenPos(), end: scanner.getTextPos() })
    }
    token = scanner.scan()
  }

  for (const comment of comments.reverse()) {
    text =
      text.slice(0, comment.start) +
      blank(text.slice(comment.start, comment.end)) +
      text.slice(comment.end)
  }

  return text
}

/**
 * @param text Comment text
 * @returns Whitespace with the same newlines and length
 */
function blank(text: string): string {
  return text.replace(/[^\n]/g, ' ')
}

/**
 * @param directory Absolute directory to walk
 * @param root Absolute root the returned paths are addressed from
 * @returns Readable files under the directory, in directory order
 */
function filesUnder(directory: string, root: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && SKIPPED_DIRECTORIES.includes(entry.name)) {
      continue
    }
    const full = join(directory, entry.name)
    const path = relative(root, full).split(sep).join('/')
    if (EXCLUDED_PATHS.includes(path)) {
      continue
    }
    if (entry.isDirectory()) {
      files.push(...filesUnder(full, root))
    } else if (isRead(entry.name)) {
      files.push(path)
    }
  }

  return files.sort()
}

/**
 * @param name File name
 * @returns Whether the checker reads that source file
 */
function isRead(name: string): boolean {
  return (
    READ_EXTENSIONS.some((extension) => name.endsWith(extension)) &&
    TEST_FILE_MARKERS.every((marker) => !name.includes(marker))
  )
}

/**
 * @param text Text to index
 * @returns Offset of the first character of every line, in order
 */
function lineStarts(text: string): number[] {
  const starts = [0]

  for (let index = 0; index < text.length; index += 1) {
    if (text[index] === '\n') {
      starts.push(index + 1)
    }
  }

  return starts
}

/**
 * @param starts Offsets of line starts, as {@link lineStarts} builds them
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
 * @param first First finding
 * @param second Second finding
 * @returns Sort order by path, line, then report body
 */
function compareFindings(first: Finding, second: Finding): number {
  if (first.path !== second.path) {
    return first.path < second.path ? -1 : 1
  }
  if (first.line !== second.line) {
    return first.line - second.line
  }

  return first.message.localeCompare(second.message)
}
