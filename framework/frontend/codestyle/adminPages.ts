// The admin-page declarations read by more than one frontend rule — UNBUILT-PAGE
// and SHELL-PARITY: the page keys and route declarations of @hilos/core, the
// unbuilt-page registry, and the view binding each key in a layer. Everything is
// read from the checked tree as data through the compiler API, never imported
// from @hilos/core, so a fixture tree carries its own declarations and a report
// line points at the author's file and line.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, sep } from 'node:path'
import ts from 'typescript'

/** The SDK view layers, in report order. */
export const LAYERS = ['vue', 'react', 'angular'] as const
/** Page keys and route declarations, relative to the checked tree. */
export const KEYS_PATH = 'framework/frontend/core/src/routing/hilosPages.ts'
/** The unbuilt-page registry, relative to the checked tree. */
export const REGISTRY_PATH =
  'framework/frontend/core/src/routing/hilosUnbuiltPages.ts'

/** One of the three SDK view layers. */
export type Layer = (typeof LAYERS)[number]

/** A position reported to the author, in the checked tree's own paths. */
export interface Site {
  path: string
  line: number
}
/** A page key declared in HilosPages, with its route declaration's admin flag. */
export interface Page extends Site {
  key: string
  admin: boolean
}
/** One registry entry, retaining an invalid key or layer for the verdict. */
export interface Entry extends Site {
  key: string
  layers: string[]
}
/** The view binding a page key in one layer; bare is a shell with no content. */
export interface View extends Site {
  bare: boolean
}

interface Binding {
  name: string
  line: number
  bare: boolean
}

/** Read declarations rather than strings or comments that look like them. */
export function source(root: string, path: string): ts.SourceFile {
  return ts.createSourceFile(
    path,
    readFileSync(join(root, path), 'utf8'),
    ts.ScriptTarget.Latest,
    true,
  )
}

/** Strip type assertions and parentheses from a declaration's initializer. */
export function unwrap(expression: ts.Expression): ts.Expression {
  while (
    ts.isAsExpression(expression) ||
    ts.isSatisfiesExpression(expression) ||
    ts.isParenthesizedExpression(expression)
  ) {
    expression = expression.expression
  }
  return expression
}

/** Read the object declared under a particular name, refusing a missing input. */
function objectNamed(
  file: ts.SourceFile,
  name: string,
): ts.ObjectLiteralExpression {
  for (const statement of file.statements) {
    if (!ts.isVariableStatement(statement)) continue
    for (const declaration of statement.declarationList.declarations) {
      if (
        ts.isIdentifier(declaration.name) &&
        declaration.name.text === name &&
        declaration.initializer
      ) {
        const value = unwrap(declaration.initializer)
        if (ts.isObjectLiteralExpression(value)) return value
      }
    }
  }
  throw new Error(`${file.fileName}: cannot read ${name}`)
}

/** The HilosPages member in a property access, not a look-alike in a string. */
function pageName(expression: ts.Expression): string | undefined {
  return ts.isPropertyAccessExpression(expression) &&
    ts.isIdentifier(expression.expression) &&
    expression.expression.text === 'HilosPages'
    ? expression.name.text
    : undefined
}

/** The name of a computed HilosPages key in a catalog. */
function keyName(property: ts.ObjectLiteralElementLike): string | undefined {
  return property.name && ts.isComputedPropertyName(property.name)
    ? pageName(property.name.expression)
    : undefined
}

/** Position reported to the author, in the source file's own coordinates. */
export function site(file: ts.SourceFile, node: ts.Node): Site {
  return {
    path: file.fileName,
    line: file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1,
  }
}

/** Read the key values together with the route declarations' admin flags. */
export function readPages(file: ts.SourceFile): Array<Page & { name: string }> {
  const admin = new Set(
    objectNamed(file, 'HILOS_ROUTE_DECLARATIONS')
      .properties.filter(
        (property) =>
          ts.isPropertyAssignment(property) &&
          ts.isObjectLiteralExpression(property.initializer) &&
          property.initializer.properties.some(
            (field) =>
              ts.isPropertyAssignment(field) &&
              field.name.getText(file) === 'admin' &&
              field.initializer.kind === ts.SyntaxKind.TrueKeyword,
          ),
      )
      .map(keyName),
  )
  return objectNamed(file, 'HilosPages').properties.flatMap((property) => {
    if (
      !ts.isPropertyAssignment(property) ||
      !ts.isIdentifier(property.name) ||
      !ts.isStringLiteral(property.initializer)
    )
      return []
    const name = property.name.text
    return [
      {
        ...site(file, property),
        name,
        key: property.initializer.text,
        admin: admin.has(name),
      },
    ]
  })
}

/** Read literal layer lists, retaining invalid keys/layers for the verdict. */
export function readEntries(
  file: ts.SourceFile,
  pages: Array<Page & { name: string }>,
): Entry[] {
  return objectNamed(file, 'HILOS_UNBUILT_PAGES').properties.map((property) => {
    const name = keyName(property)
    const key =
      pages.find((page) => page.name === name)?.key ??
      property.name?.getText(file) ??
      '<unknown>'
    const value = ts.isPropertyAssignment(property)
      ? unwrap(property.initializer)
      : undefined
    const layers =
      value && ts.isArrayLiteralExpression(value)
        ? value.elements.map((layer) =>
            ts.isStringLiteral(layer) ? layer.text : layer.getText(file),
          )
        : []
    return { ...site(file, property), key, layers }
  })
}

/** Production admin files only; tests and dependency trees do not declare views. */
export function filesUnder(directory: string): string[] {
  if (!existsSync(directory)) return []
  return readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
      if (['node_modules', 'dist', '.angular'].includes(entry.name)) return []
      const path = join(directory, entry.name)
      if (entry.isDirectory()) return filesUnder(path)
      return /\.(vue|tsx|ts)$/.test(entry.name) &&
        !/\.(test|spec)\./.test(entry.name)
        ? [path]
        : []
    })
    .sort()
}

/** Identify shell bindings, plus mapped components with no shell of their own. */
export function readViews(
  root: string,
  layer: Layer,
  pages: Array<Page & { name: string }>,
): Map<string, View> {
  const views = new Map<string, View>()
  const directory = `framework/frontend/${layer}/src/admin`
  const files = new Map<string, ts.SourceFile>()
  for (const absolute of filesUnder(join(root, directory))) {
    const path = relative(root, absolute).split(sep).join('/')
    const file = source(root, path)
    files.set(path, file)
    const bindings =
      layer === 'react' ? reactBindings(file) : templateBindings(file, layer)
    for (const binding of bindings) {
      const key = pages.find((page) => page.name === binding.name)?.key
      if (key && (views.get(key)?.bare ?? true))
        views.set(key, { path, line: binding.line, bare: binding.bare })
    }
  }
  const map = files.get(`${directory}/hilosAdminViews.ts`)
  if (map) {
    const imports = new Map<string, string>()
    for (const statement of map.statements) {
      if (
        !ts.isImportDeclaration(statement) ||
        !ts.isStringLiteral(statement.moduleSpecifier)
      )
        continue
      const specifier = statement.moduleSpecifier.text
      if (!specifier.startsWith('.')) continue
      const path = join(dirname(map.fileName), specifier).split(sep).join('/')
      const target = [
        path,
        path.replace(/\.js$/, '.tsx'),
        path.replace(/\.js$/, '.ts'),
      ].find((candidate) => files.has(candidate))
      if (!target) continue
      if (statement.importClause?.name)
        imports.set(statement.importClause.name.text, target)
      const bindings = statement.importClause?.namedBindings
      if (bindings && ts.isNamedImports(bindings)) {
        for (const binding of bindings.elements)
          imports.set(binding.name.text, target)
      }
    }
    const visit = (node: ts.Node): void => {
      if (ts.isPropertyAssignment(node) && ts.isIdentifier(node.initializer)) {
        const key = pages.find((page) => page.name === keyName(node))?.key
        const target = imports.get(node.initializer.text)
        if (key && target && !views.has(key))
          views.set(key, { path: target, line: 1, bare: false })
      }
      ts.forEachChild(node, visit)
    }
    visit(map)
  }
  return views
}

/** JSX syntax makes comments and string examples invisible to the shell reader. */
function reactBindings(file: ts.SourceFile): Binding[] {
  const bindings: Binding[] = []
  const visit = (node: ts.Node): void => {
    if (
      (ts.isJsxSelfClosingElement(node) || ts.isJsxOpeningElement(node)) &&
      ['HilosAdminPage', 'HilosSettingPresetsPage'].includes(
        node.tagName.getText(file),
      )
    ) {
      const attribute = node.attributes.properties.find(
        (prop) => ts.isJsxAttribute(prop) && prop.name.getText(file) === 'page',
      )
      if (
        attribute &&
        ts.isJsxAttribute(attribute) &&
        attribute.initializer &&
        ts.isJsxExpression(attribute.initializer) &&
        attribute.initializer.expression
      ) {
        const name = pageName(attribute.initializer.expression)
        if (name) {
          let whole: ts.Node = node
          while (ts.isParenthesizedExpression(whole.parent))
            whole = whole.parent
          const bare =
            node.tagName.getText(file) === 'HilosAdminPage' &&
            ts.isJsxSelfClosingElement(node) &&
            (ts.isReturnStatement(whole.parent) ||
              (ts.isArrowFunction(whole.parent) && whole.parent.body === whole))
          bindings.push({ name, line: site(file, node).line, bare })
        }
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(file)
  return bindings
}

/** Vue SFC templates and Angular Component templates bind the shell by page. */
function templateBindings(
  file: ts.SourceFile,
  layer: 'vue' | 'angular',
): Binding[] {
  const templates: Array<{
    text: string
    offset: number
    fields: Map<string, string>
  }> = []
  if (layer === 'vue') {
    const match = /^<template[^>]*>([\s\S]*)<\/template>\s*$/m.exec(file.text)
    if (match)
      templates.push({
        text: match[1],
        offset: match.index + match[0].indexOf('>') + 1,
        fields: new Map(),
      })
  } else {
    for (const statement of file.statements) {
      if (!ts.isClassDeclaration(statement)) continue
      const fields = new Map<string, string>()
      for (const member of statement.members) {
        if (ts.isPropertyDeclaration(member) && member.initializer) {
          const name = pageName(member.initializer)
          if (name) fields.set(member.name.getText(file), name)
        }
      }
      for (const decorator of ts.getDecorators(statement) ?? []) {
        if (
          !ts.isCallExpression(decorator.expression) ||
          decorator.expression.expression.getText(file) !== 'Component'
        )
          continue
        const config = decorator.expression.arguments[0]
        if (!config || !ts.isObjectLiteralExpression(config)) continue
        for (const prop of config.properties) {
          if (
            ts.isPropertyAssignment(prop) &&
            prop.name.getText(file) === 'template' &&
            (ts.isNoSubstitutionTemplateLiteral(prop.initializer) ||
              ts.isStringLiteral(prop.initializer))
          ) {
            templates.push({
              text: prop.initializer.text,
              offset: prop.initializer.getStart(file) + 1,
              fields,
            })
          }
        }
      }
    }
  }
  return templates.flatMap(({ text, offset, fields }) => {
    const clean = text.replace(/<!--[\s\S]*?-->/g, (comment) =>
      comment.replace(/[^\n]/g, ' '),
    )
    const bindings: Binding[] = []
    const tag =
      layer === 'vue'
        ? /<(?:HilosAdminPage|HilosSettingPresetsPage)\b[^>]*>/g
        : /<hilos-(?:admin|setting-presets)-page\b[^>]*>/g
    for (const match of clean.matchAll(tag)) {
      const attribute =
        layer === 'vue'
          ? /:page\s*=\s*["']HilosPages\.(\w+)["']/.exec(match[0])
          : /\[page\]\s*=\s*["'](\w+)["']/.exec(match[0])
      const name =
        attribute && (layer === 'vue' ? attribute[1] : fields.get(attribute[1]))
      if (!name) continue
      bindings.push({
        name,
        line: file.getLineAndCharacterOfPosition(offset + match.index).line + 1,
        bare:
          /^<(HilosAdminPage|hilos-admin-page)\b/.test(match[0]) &&
          match[0].endsWith('/>') &&
          clean.trim() === match[0],
      })
    }
    return bindings
  })
}
