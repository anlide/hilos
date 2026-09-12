// VUE-TEMPLATE-REF, the machine half of the template-ref rule in
// docs/agents/code-style/vue-template-refs.md: inside a Vue SFC template, a
// field of a ref-bearing value is read through `.value` and never bare.
//
// Why a rule at all. Vue unwraps a ref that is a top-level binding of
// `<script setup>`, and it unwraps nothing that sits one level down — a ref
// held as a FIELD of a plain object stays a ref in a template expression, and a
// ref object is always truthy. `v-if="action.error"` therefore draws its block
// forever, and nothing in the toolchain says so: the type is a legal thing to
// test for truthiness, the frontend ESLint carries no type-aware rule, and a
// component with no test of its own is judged by nobody. The defect this rule
// exists for lived to a manual acceptance (HIL-887).
//
// What is ref-bearing is named, not inferred: a value assigned from one of the
// composables listed here, and a prop declared with one of the types listed
// here. Widening the rule is a line in one of those two lists. Reading it wider
// than that would take the type of every template expression, which is a
// type-aware pass and not this project.
//
// The whole of the template is judged, interpolations included, even though Vue
// unwraps a ref inside `{{ }}` by itself. The exception would be real and it
// would still be wrong to allow: "bare in the moustaches, `.value` in an
// attribute" is a rule nobody can hold in their head while editing a template,
// and the cost of the uniform one is a `.value` Vue would have managed without.
//
// A method call is not a read: `action.run(handle)` names a function on the
// object, and a function is not a ref.
//
// The rule has no PHP half, and no half in the other view layers: React hands
// over values and Angular hands over signals, which are called.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const VUE_TEMPLATE_REF_RULE_ID = 'VUE-TEMPLATE-REF'

/** The document that owns the rule; every report line ends with it. */
const DOC = 'docs/agents/code-style/vue-template-refs.md'

/**
 * Composables whose return value holds refs as its fields. A value assigned
 * from one of these in `<script setup>` is ref-bearing; widening the rule to
 * another composable is one line here.
 */
const REF_BEARING_COMPOSABLES = ['useTrackedAction']

/**
 * Types whose fields are refs. A prop declared with one of these is
 * ref-bearing; widening the rule to another type is one line here.
 */
const REF_BEARING_TYPES = ['TrackedAction']

/** The macro a component declares its props with. */
const DEFINE_PROPS = 'defineProps'

/** The field every ref hands its value over through. */
const VALUE = 'value'

/**
 * A top-level block of a Vue SFC, opening and closing at the start of a line.
 * Nested blocks — a `<template>` handed to a slot — are indented and so are not
 * read as the file's own, which is what makes the anchoring load-bearing.
 */
const SFC_TEMPLATE = { open: /^<template[^>]*>$/m, close: /^<\/template>$/m }

/** The block the ref-bearing names are read from. */
const SFC_SCRIPT = { open: /^<script[^>]*>$/m, close: /^<\/script>$/m }

/** The only shape this rule reads: unwrapping is a Vue template question. */
const SFC_EXTENSION = '.vue'

/** The SDK's own root; everything below it is frontend code. */
const FRAMEWORK_ROOT = 'framework/frontend'

/** Where the demos live; each one's frontend root is scanned as well. */
const DEMO_DIRECTORY = 'demo'

/** Frontend root inside a demo, appended to that demo's own directory. */
const DEMO_ROOT = 'frontend'

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

/** One found read, before it is turned into a line, so a file can be sorted. */
interface Site {
  line: number
  what: string
}

/**
 * Reports every bare read of a ref-bearing field in this file's template, in
 * source order.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns One finished report line per offending read
 */
export function checkSource(relativePath: string, source: string): string[] {
  if (!relativePath.endsWith(SFC_EXTENSION)) {
    return []
  }

  return sites(source)
    .sort((first, second) => first.line - second.line)
    .map((site) => report(relativePath, site))
}

/**
 * Reports every bare read of a ref-bearing field in the SDK's and the demos'
 * single-file components.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns One finished report line per offending read, in path order
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
 * @param source Contents of the single-file component
 * @returns Every offending read of the file, in no particular order
 */
function sites(source: string): Site[] {
  const script = topLevelBlock(source, SFC_SCRIPT)
  const template = topLevelBlock(source, SFC_TEMPLATE)
  if (script === null || template === null) {
    return []
  }

  const names = refBearingNames(script.text)
  if (names.length === 0) {
    return []
  }

  return names.flatMap((name) =>
    bareReads(name, template.text, template.lineOffset),
  )
}

/**
 * The two declarations a ref-bearing value arrives through, and no third: a
 * value that came from somewhere else is the blind spot the document names.
 *
 * @param script Contents of the component's script block
 * @returns Names the template may hold a ref-bearing value under
 */
function refBearingNames(script: string): string[] {
  const sourceFile = ts.createSourceFile(
    'sfc.ts',
    script,
    ts.ScriptTarget.Latest,
    true,
  )
  const names: string[] = []

  const visit = (node: ts.Node): void => {
    if (isRefBearingAssignment(node)) {
      names.push(node.name.text)
    } else if (ts.isCallExpression(node)) {
      names.push(...refBearingProps(node))
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)

  return names
}

/**
 * @param node Node under the walk
 * @returns Whether it names a value assigned from a ref-bearing composable
 */
function isRefBearingAssignment(
  node: ts.Node,
): node is ts.VariableDeclaration & { name: ts.Identifier } {
  return (
    ts.isVariableDeclaration(node) &&
    ts.isIdentifier(node.name) &&
    node.initializer !== undefined &&
    ts.isCallExpression(node.initializer) &&
    ts.isIdentifier(node.initializer.expression) &&
    REF_BEARING_COMPOSABLES.includes(node.initializer.expression.text)
  )
}

/**
 * @param call Call under the walk, which may be the props macro
 * @returns Props of that call declared with a ref-bearing type
 */
function refBearingProps(call: ts.CallExpression): string[] {
  if (
    !ts.isIdentifier(call.expression) ||
    call.expression.text !== DEFINE_PROPS
  ) {
    return []
  }

  const [declared] = call.typeArguments ?? []
  if (declared === undefined || !ts.isTypeLiteralNode(declared)) {
    return []
  }

  const names: string[] = []
  for (const member of declared.members) {
    if (
      ts.isPropertySignature(member) &&
      ts.isIdentifier(member.name) &&
      member.type !== undefined &&
      ts.isTypeReferenceNode(member.type) &&
      ts.isIdentifier(member.type.typeName) &&
      REF_BEARING_TYPES.includes(member.type.typeName.text)
    ) {
      names.push(member.name.text)
    }
  }

  return names
}

/**
 * @param name Name the template holds a ref-bearing value under
 * @param template Contents of the component's template block
 * @param lineOffset Lines standing above that template in the file
 * @returns Every read of a field of that value which does not go through `.value`
 */
function bareReads(name: string, template: string, lineOffset: number): Site[] {
  const found: Site[] = []
  const reads = new RegExp(`${name}\\.([A-Za-z_$][\\w$]*)`, 'g')

  for (const read of template.matchAll(reads)) {
    const index = read.index
    if (isPartOfLongerName(template, index)) {
      continue
    }
    const rest = template.slice(index + read[0].length)
    if (rest.startsWith('(') || unwrapsHere(rest)) {
      continue
    }
    found.push({
      line: lineOffset + template.slice(0, index).split('\n').length,
      what: verdict(name, read[1]),
    })
  }

  return found
}

/**
 * @param template Contents of the template block
 * @param index Where the match starts
 * @returns Whether the match is the tail of a longer identifier or a longer path
 */
function isPartOfLongerName(template: string, index: number): boolean {
  const before = index === 0 ? '' : template[index - 1]

  return before === '.' || /[\w$]/.test(before)
}

/**
 * @param rest Template text standing right after the read
 * @returns Whether the read hands its value over through `.value`
 */
function unwrapsHere(rest: string): boolean {
  return (
    rest.startsWith(`.${VALUE}`) && !/[\w$]/.test(rest.charAt(VALUE.length + 1))
  )
}

/**
 * @param name Name the ref-bearing value is held under
 * @param field Field read off it
 * @returns What is wrong with the read
 */
function verdict(name: string, field: string): string {
  return (
    `'${name}.${field}' reads a ref held as a field, and a template expression` +
    ` unwraps no such ref — so the value is an object and the test on it is` +
    ` always true; read '${name}.${field}.${VALUE}', or unwrap it with a` +
    ' computed in <script setup>'
  )
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
 * @param relativePath Path of the file as it appears in the report
 * @param site One offending read of that file
 * @returns One finished report line
 */
function report(relativePath: string, site: Site): string {
  return `${VUE_TEMPLATE_REF_RULE_ID} ${relativePath}:${site.line} — ${site.what} (see ${DOC})`
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
    } else if (entry.name.endsWith(SFC_EXTENSION)) {
      files.push(relativePath)
    }
  }

  return files
}
