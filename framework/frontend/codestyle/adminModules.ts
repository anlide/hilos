// Source-only module resolution shared by the admin inventory and parity graph.
// Loaders are inspected as syntax; no SDK module or loader is ever executed.
import { existsSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, sep } from 'node:path'
import ts from 'typescript'

/** A literal module and the single value export selected from it. */
export interface ModuleReference {
  specifier: string
  name: string
}

/** The source declaration behind an exported value. */
export interface ExportTarget {
  path: string
  name: string
  node?: ts.Node
}

/** Resolve relative source imports and the SDK's public admin section paths. */
export function resolveModule(
  root: string,
  path: string,
  specifier: string,
): string | undefined {
  const section =
    /^@hilos\/(vue|react|angular)\/admin\/([a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*)$/.exec(
      specifier,
    )
  let base: string
  if (section) {
    const directory =
      section[2] === 'change-log'
        ? 'changeLog'
        : section[2] === 'mcp-skills'
          ? 'mcpSkills'
          : section[2]
    base = `framework/frontend/${section[1]}/src/admin/${directory}/index.ts`
  } else if (specifier.startsWith('.')) {
    base = join(dirname(path), specifier).split(sep).join('/')
  } else {
    return undefined
  }
  const stem = base.replace(/\.js$/, '')
  return [
    base,
    `${stem}.ts`,
    `${stem}.tsx`,
    `${stem}.vue`,
    `${base}/index.ts`,
    `${base}/index.tsx`,
  ].find(
    (candidate) =>
      existsSync(join(root, candidate)) &&
      statSync(join(root, candidate)).isFile(),
  )
}

/** Parse Vue script blocks with their original offsets, or a TypeScript file. */
export function moduleSource(root: string, path: string): ts.SourceFile {
  const text = readFileSync(join(root, path), 'utf8')
  let script = text
  if (path.endsWith('.vue')) {
    const spans = [
      ...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g),
    ].map((match) => ({
      start: match.index + match[0].indexOf('>') + 1,
      text: match[1],
    }))
    script = text.replace(/[^\n]/g, ' ')
    for (const span of spans) {
      script =
        script.slice(0, span.start) +
        span.text +
        script.slice(span.start + span.text.length)
    }
  }
  return ts.createSourceFile(path, script, ts.ScriptTarget.Latest, true)
}

/** Read exactly import('literal').then(module => module.NamedExport). */
export function namedDynamicImport(node: ts.Node): ModuleReference | undefined {
  if (
    !ts.isCallExpression(node) ||
    node.arguments.length !== 1 ||
    !ts.isPropertyAccessExpression(node.expression) ||
    node.expression.name.text !== 'then'
  )
    return undefined
  const load = node.expression.expression
  const select = node.arguments[0]
  if (
    !ts.isCallExpression(load) ||
    load.expression.kind !== ts.SyntaxKind.ImportKeyword ||
    load.arguments.length !== 1 ||
    !ts.isStringLiteral(load.arguments[0]) ||
    !ts.isArrowFunction(select) ||
    select.parameters.length !== 1 ||
    !ts.isIdentifier(select.parameters[0].name) ||
    select.parameters[0].initializer ||
    select.parameters[0].dotDotDotToken ||
    !ts.isPropertyAccessExpression(select.body) ||
    !ts.isIdentifier(select.body.expression) ||
    select.body.expression.text !== select.parameters[0].name.text
  )
    return undefined
  return { specifier: load.arguments[0].text, name: select.body.name.text }
}

/** The imported value represented by a local import binding. */
export function importedValue(
  file: ts.SourceFile,
  name: string,
): ModuleReference | undefined {
  for (const statement of file.statements) {
    if (
      !ts.isImportDeclaration(statement) ||
      !ts.isStringLiteral(statement.moduleSpecifier) ||
      statement.importClause?.isTypeOnly
    )
      continue
    const clause = statement.importClause
    if (clause?.name?.text === name)
      return { specifier: statement.moduleSpecifier.text, name: 'default' }
    const bindings = clause?.namedBindings
    if (!bindings || !ts.isNamedImports(bindings)) continue
    const binding = bindings.elements.find(
      (element) => !element.isTypeOnly && element.name.text === name,
    )
    if (binding)
      return {
        specifier: statement.moduleSpecifier.text,
        name: (binding.propertyName ?? binding.name).text,
      }
  }
  return undefined
}

/** Follow value re-exports to their declaration; missing, type-only and cycles fail closed. */
export function resolveExport(
  root: string,
  path: string,
  name: string,
  seen = new Set<string>(),
): ExportTarget | undefined {
  const key = `${path}:${name}`
  if (seen.has(key)) return undefined
  const next = new Set([...seen, key])
  if (path.endsWith('.vue'))
    return name === 'default' ? { path, name } : undefined
  const file = moduleSource(root, path)
  const follow = (reference: ModuleReference): ExportTarget | undefined => {
    const target = resolveModule(root, path, reference.specifier)
    return target
      ? resolveExport(root, target, reference.name, next)
      : undefined
  }
  const local = (localName: string): ExportTarget | undefined => {
    const imported = importedValue(file, localName)
    if (imported) return follow(imported)
    for (const statement of file.statements) {
      if (
        (ts.isFunctionDeclaration(statement) ||
          ts.isClassDeclaration(statement)) &&
        statement.name?.text === localName
      )
        return { path, name: localName, node: statement }
      if (ts.isVariableStatement(statement)) {
        const declaration = statement.declarationList.declarations.find(
          (item) => ts.isIdentifier(item.name) && item.name.text === localName,
        )
        if (declaration) return { path, name: localName, node: declaration }
      }
    }
    return undefined
  }
  for (const statement of file.statements) {
    if (ts.isExportDeclaration(statement) && !statement.isTypeOnly) {
      const specifier =
        statement.moduleSpecifier &&
        ts.isStringLiteral(statement.moduleSpecifier)
          ? statement.moduleSpecifier.text
          : undefined
      if (statement.exportClause && ts.isNamedExports(statement.exportClause)) {
        const exported = statement.exportClause.elements.find(
          (element) => !element.isTypeOnly && element.name.text === name,
        )
        if (exported) {
          const original = (exported.propertyName ?? exported.name).text
          return specifier
            ? follow({ specifier, name: original })
            : local(original)
        }
      }
    }
    if (
      ts.isExportAssignment(statement) &&
      !statement.isExportEquals &&
      name === 'default' &&
      ts.isIdentifier(statement.expression)
    )
      return local(statement.expression.text)
    if (!ts.canHaveModifiers(statement)) continue
    const modifiers = ts.getModifiers(statement) ?? []
    if (
      !modifiers.some(
        (modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword,
      )
    )
      continue
    const isDefault = modifiers.some(
      (modifier) => modifier.kind === ts.SyntaxKind.DefaultKeyword,
    )
    if (
      ts.isFunctionDeclaration(statement) ||
      ts.isClassDeclaration(statement)
    ) {
      if (isDefault ? name === 'default' : statement.name?.text === name)
        return { path, name, node: statement }
    }
    if (ts.isVariableStatement(statement) && !isDefault) {
      const declaration = statement.declarationList.declarations.find(
        (item) => ts.isIdentifier(item.name) && item.name.text === name,
      )
      if (declaration) return { path, name, node: declaration }
    }
  }
  let selected: ExportTarget | undefined
  if (name === 'default') return undefined
  for (const statement of file.statements) {
    if (
      !ts.isExportDeclaration(statement) ||
      statement.isTypeOnly ||
      statement.exportClause ||
      !statement.moduleSpecifier ||
      !ts.isStringLiteral(statement.moduleSpecifier)
    )
      continue
    const target = follow({ specifier: statement.moduleSpecifier.text, name })
    if (!target) continue
    if (
      selected &&
      (selected.path !== target.path || selected.name !== target.name)
    )
      return undefined
    selected = target
  }
  return selected
}

/** Resolve one selected import without spreading ownership to sibling exports. */
export function resolveReference(
  root: string,
  path: string,
  reference: ModuleReference,
): ExportTarget | undefined {
  const target = resolveModule(root, path, reference.specifier)
  return target ? resolveExport(root, target, reference.name) : undefined
}
