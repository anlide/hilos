// UNBUILT-PAGE keeps the unbuilt-page registry honest in every SDK view layer.
// The page tree is read from the PHP catalog as data, never copied into runtime
// frontend code. This is a source-shape check: project views/projectViews and
// pages assembled outside the recognized shell templates are not judged. The
// built HilosSettingPresetsPage also accepts a page key: its self-closing use
// still renders a complete settings surface inside HilosAdminPage.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, sep } from 'node:path'
import ts from 'typescript'

/** Rule id, listed once in automated-checks.md. */
export const UNBUILT_PAGE_RULE_ID = 'UNBUILT-PAGE'

const DOC = 'docs/agents/frontend/page-module-structure.md'
const LAYERS = ['vue', 'react', 'angular'] as const
const KEYS_PATH = 'framework/frontend/core/src/routing/hilosPages.ts'
const REGISTRY_PATH = 'framework/frontend/core/src/routing/hilosUnbuiltPages.ts'
const CATALOG_PATH = 'framework/backend/Database/Pages/HilosPageCatalog.php'
const CONSTANTS_PATH = 'framework/backend/Constants/HilosPageConstants.php'
// HIL-1478 opens the Vue main card by direct URL before HIL-1474 builds its list.
// HIL-1474 removes this staged-child exception when it opens that parent.
const STAGED_VUE_CHILD = {
  parent: 'hilos_i18n_languages',
  child: 'hilos_i18n_language',
} as const
type Layer = (typeof LAYERS)[number]

interface Site {
  path: string
  line: number
}
interface Page extends Site {
  key: string
  admin: boolean
}
interface Entry extends Site {
  key: string
  layers: string[]
}
interface View extends Site {
  bare: boolean
}
interface Finding extends Site {
  message: string
}

/**
 * Judge the registry, framework views and section tree in the supplied tree.
 *
 * @param root Absolute root of a repository or fixture tree.
 * @returns Finished report lines in path, line and message order.
 */
export function checkTree(root: string): string[] {
  const keys = source(root, KEYS_PATH)
  const pages = readPages(keys)
  const entries = readEntries(source(root, REGISTRY_PATH), pages)
  const children = readChildren(root)
  const found: Finding[] = []
  for (const entry of entries) {
    if (!pages.some((page) => page.key === entry.key && page.admin)) {
      found.push({
        ...entry,
        message: `'${entry.key}' is not an admin page; remove its registry entry`,
      })
    }
    if (
      entry.layers.length === 0 ||
      new Set(entry.layers).size !== entry.layers.length ||
      entry.layers.some((layer) => !LAYERS.some((known) => known === layer))
    ) {
      found.push({
        ...entry,
        message: `'${entry.key}' needs a nonempty list of distinct vue, react, angular layers`,
      })
    }
  }
  for (const layer of LAYERS) {
    const views = readViews(root, layer, pages)
    const unbuilt = new Map(
      entries
        .filter((entry) => entry.layers.includes(layer))
        .map((entry) => [entry.key, entry]),
    )
    for (const page of pages.filter((page) => page.admin)) {
      const entry = unbuilt.get(page.key)
      const view = views.get(page.key)
      const descendants = children.get(page.key) ?? []
      if (entry && view && !view.bare) {
        found.push({
          ...entry,
          message: `'${page.key}' is built in ${layer}; strike ${layer} from its entry`,
        })
      }
      if (
        !entry &&
        (!view ||
          (view.bare && descendants.every((child) => unbuilt.has(child))))
      ) {
        found.push({
          ...(view ?? page),
          message: `'${page.key}' is unbuilt in ${layer}; list it in HILOS_UNBUILT_PAGES${descendants.length > 0 ? ' (list its section too when none of its pages is built)' : ''}`,
        })
      }
      if (entry) {
        for (const child of descendants) {
          if (
            !unbuilt.has(child) &&
            !(
              layer === 'vue' &&
              page.key === STAGED_VUE_CHILD.parent &&
              child === STAGED_VUE_CHILD.child
            )
          ) {
            found.push({
              ...entry,
              message: `'${page.key}' hides '${child}' in ${layer}; strike ${layer} from the section or list the unbuilt child in HILOS_UNBUILT_PAGES`,
            })
          }
        }
      }
    }
  }
  return found
    .sort(
      (a, b) =>
        a.path.localeCompare(b.path) ||
        a.line - b.line ||
        a.message.localeCompare(b.message),
    )
    .map(
      (hit) =>
        `${UNBUILT_PAGE_RULE_ID} ${hit.path}:${hit.line} — ${hit.message} (see ${DOC})`,
    )
}

/**
 * @param root Absolute repository root.
 * @returns Every registry/view mismatch in the SDK.
 */
export function checkRepository(root: string): string[] {
  return checkTree(root)
}

/** Read declarations rather than strings or comments that look like them. */
function source(root: string, path: string): ts.SourceFile {
  return ts.createSourceFile(
    path,
    readFileSync(join(root, path), 'utf8'),
    ts.ScriptTarget.Latest,
    true,
  )
}

/** Strip type assertions and parentheses from a declaration's initializer. */
function unwrap(expression: ts.Expression): ts.Expression {
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
function site(file: ts.SourceFile, node: ts.Node): Site {
  return {
    path: file.fileName,
    line: file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1,
  }
}

/** Read the key values together with the route declarations' admin flags. */
function readPages(file: ts.SourceFile): Array<Page & { name: string }> {
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
function readEntries(
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

/** Remove PHP comments while preserving quoted values and line positions. */
function phpData(text: string): string {
  return text.replace(
    /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*/g,
    (token) =>
      token.startsWith("'") || token.startsWith('"')
        ? token
        : token.replace(/[^\n]/g, ' '),
  )
}

/** The PHP catalog is data for this frontend rule; it is never executed. */
function readChildren(root: string): Map<string, string[]> {
  const constants = new Map(
    [
      ...phpData(readFileSync(join(root, CONSTANTS_PATH), 'utf8')).matchAll(
        /public\s+const\s+string\s+(HILOS_\w+)\s*=\s*'([^']+)'\s*;/g,
      ),
    ].map((match) => [match[1], match[2]]),
  )
  const children = new Map<string, string[]>()
  const catalog = phpData(readFileSync(join(root, CATALOG_PATH), 'utf8'))
  for (const match of catalog.matchAll(
    /HilosPageConstants::(HILOS_\w+)\s*=>\s*\[([\s\S]*?)\]/g,
  )) {
    const parent =
      /PageCatalogConstants::CATALOG_ENTRY_PARENT\s*=>\s*HilosPageConstants::(HILOS_\w+)/.exec(
        match[2],
      )
    if (!parent) continue
    const key = constants.get(match[1])
    const parentKey = constants.get(parent[1])
    if (!key || !parentKey)
      throw new Error(`${CATALOG_PATH}: unresolved key in ${match[1]}`)
    children.set(parentKey, [...(children.get(parentKey) ?? []), key])
  }
  return children
}

/** Production admin files only; tests and dependency trees do not declare views. */
function filesUnder(directory: string): string[] {
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
function readViews(
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

interface Binding {
  name: string
  line: number
  bare: boolean
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
