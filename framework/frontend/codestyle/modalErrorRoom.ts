import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { posix } from 'node:path'

import {
  ASTWithSource,
  BindingType,
  CssSelector,
  Interpolation,
  parseTemplate,
  SelectorMatcher,
  TmplAstBoundAttribute,
  TmplAstBoundText,
  TmplAstContent,
  TmplAstElement,
  TmplAstForLoopBlock,
  TmplAstIfBlock,
  TmplAstLetDeclaration,
  TmplAstSwitchBlock,
  TmplAstTemplate,
  TmplAstText,
  type AST,
  type TmplAstNode,
} from '@angular/compiler'
import {
  NodeTypes,
  parse as parseVue,
  type DirectiveNode,
  type ElementNode,
  type TemplateChildNode,
} from '@vue/compiler-dom'
import ts from 'typescript'

export const MODAL_ERROR_ROOM_RULE_ID = 'MODAL-ERROR-ROOM'
export const MODAL_ERROR_EMPTY_RULE_ID = 'MODAL-ERROR-EMPTY'

const STYLE_RULES = '(see docs/agents/frontend/styling-rules.md).'
const ROOM_MESSAGE =
  'Use HilosFormError or HilosActionError for the reserved modal error room'
const EMPTY_FILL =
  'Show a neutral fill around the invisible modal error twin only while the room is empty'
const EMPTY_TWIN =
  'Keep the empty modal error room decorative and its sizing twin invisible'
const NEUTRAL_FILL = 'bg-body-tertiary'

const ACTION_ERROR = 'HilosActionError'
const FORM_ERROR = 'HilosFormError'
const MODAL = 'HilosModal'
const SHARED = new Set([ACTION_ERROR, FORM_ERROR])
const TABLES = new Set(['HilosViewportTable', 'HilosTableLive'])
const SDK_COMPONENTS = new Set([MODAL, ...SHARED, ...TABLES])
const NATIVE_TABLE = new Set([
  'table',
  'thead',
  'tbody',
  'tfoot',
  'tr',
  'td',
  'th',
])
const TABLE_SLOTS = new Set([
  '#bulkUntouched',
  '#empty',
  '#rowProgress',
  '#tableProgress',
  '#tableProgressAction',
  '#table-progress',
  '#table-progress-action',
  '#table-progress-announcement',
  '#row-progress',
])
const TABLE_PROPS = new Set([
  'bulkUntouched',
  'cells',
  'details',
  'empty',
  'rowProgress',
  'tableProgress',
  'tableProgressAction',
])
const MODAL_SLOTS = new Set(['actions', 'body', 'children', 'header'])
const ACTION_EVENTS = new Set(['click', 'submit', 'ngSubmit'])
const SDK_ROOT = 'framework/frontend'
const SDK_PACKAGES = ['vue', 'react', 'angular']
const SKIPPED_DIRECTORIES = new Set([
  '__fixtures__',
  '__generated__',
  '__mocks__',
  '__tests__',
  '.angular',
  '.cache',
  'build',
  'coverage',
  'dist',
  'dist-pack',
  'dist-prerender',
  'fixtures',
  'generated',
  'node_modules',
  'test',
  'tests',
])
const CANONICAL_ACTION_ERRORS = new Set([
  'framework/frontend/vue/src/HilosActionError.vue',
  'framework/frontend/react/src/HilosActionError.tsx',
  'framework/frontend/angular/src/HilosActionError.ts',
])
const CANONICAL_FORM_ERRORS = new Set([
  'framework/frontend/vue/src/HilosFormError.vue',
  'framework/frontend/react/src/HilosFormError.tsx',
  'framework/frontend/angular/src/HilosFormError.ts',
])

interface Condition {
  expression: string
  truthy: boolean
  value: ts.Expression
  view?: View
  mount?: ViewMount
  nullish?: true
  opening?: true
  jsxOperand?: true
}

interface Attribute {
  name: string
  literal?: string
  expression?: ts.Expression
  bindingType?: BindingType
}

interface Element {
  kind: 'element'
  name: string
  component?: string
  target?: View
  templateReference?: string
  outlet?: string
  vueSlotName?: ts.Expression
  offset: number
  attributes: Attribute[]
  actions?: ts.Expression[]
  explicitChildren?: boolean
  jsxChildValue?: ts.Expression
  unknownInputs?: boolean
  unresolvedMount?: true
  jsxSlot?: true
  children: RenderNode[]
}

interface Text {
  kind: 'text'
  value: string
  bindings?: ts.Expression[]
  literalText?: string
}

interface Branch {
  kind: 'branch'
  conditions: Condition[]
  children: RenderNode[]
  insertion?: Element | null
}

type RenderNode = Element | Text | Branch
type Binding = Pick<Element, 'component' | 'target'> & { reactContext?: true }
type ResolveTag = (name: string, location?: ts.Node) => Binding | undefined
type ResolveImport = (specifier: string, name: string) => Binding | undefined

type Context = 'plain' | 'modal' | 'table'

interface View {
  path: string
  offset: number
  read: () => RenderNode[]
  roots?: RenderNode[]
  scripts: ts.SourceFile[]
  binding?: (name: string) => ts.Declaration | undefined
  jsxParameter?: ts.ParameterDeclaration
  jsxResolve?: ResolveTag
  jsxRender?: ts.Node
  mountedOnly?: boolean
  unresolvedMount?: boolean
  vueProps?: Map<ts.VariableDeclaration | undefined, ts.TypeNode>
  contentOutlets?: AngularOutlet[]
  selector?: string
  actionError?: boolean
  templates?: Map<string, Element>
  contentQueries?: Map<string, string>
}

interface SourceModule {
  views: View[]
  exported: ResolveTag
}

function renderTree(view: View): RenderNode[] {
  return (view.roots ??= view.read())
}

function slot(name: string, offset: number, children: RenderNode[]): Element {
  return { kind: 'element', name, offset, children, attributes: [] }
}

function stringValue(node: ts.Expression): string | undefined {
  if (ts.isStringLiteralLike(node) || ts.isNumericLiteral(node))
    return node.text
  if (node.kind === ts.SyntaxKind.TrueKeyword) return 'true'
  if (node.kind === ts.SyntaxKind.FalseKeyword) return 'false'
  return undefined
}

function unparenthesized(node: ts.Expression): ts.Expression {
  while (ts.isParenthesizedExpression(node)) node = node.expression
  return node
}

type TemplateScope = ReadonlyMap<string, ts.Expression | undefined>

const templateScopes = new WeakMap<ts.SourceFile, TemplateScope>()

function expression(
  source: string,
  scope?: TemplateScope,
): ts.Expression | undefined {
  if (!source.trim()) return undefined
  const file = ts.createSourceFile(
    'binding.ts',
    '(' + source + '\n)',
    ts.ScriptTarget.Latest,
    true,
  )
  if (scope?.size) templateScopes.set(file, scope)
  const [statement] = file.statements
  if (statement && ts.isExpressionStatement(statement)) {
    return unparenthesized(statement.expression)
  }
  return undefined
}

function parsedBindings(
  sources: string[],
  scope?: TemplateScope,
): ts.Expression[] {
  return sources.flatMap((source) => {
    const value = expression(source, scope)
    return value ? [value] : []
  })
}

function condition(
  node: ts.Expression,
  truthy = true,
  nullish?: true,
): Condition {
  node = unparenthesized(node)
  if (
    !nullish &&
    ts.isPrefixUnaryExpression(node) &&
    node.operator === ts.SyntaxKind.ExclamationToken
  ) {
    return condition(node.operand, !truthy)
  }
  const scanner = ts.createScanner(
    ts.ScriptTarget.Latest,
    true,
    ts.LanguageVariant.Standard,
    node.getText(),
  )
  const tokens: string[] = []
  while (scanner.scan() !== ts.SyntaxKind.EndOfFileToken)
    tokens.push(scanner.getTokenText())
  const expression = tokens.join(' ') + (nullish ? ' == null' : '')
  return { expression, truthy, value: node, nullish }
}

function opposite(guard: Condition): Condition {
  return { ...guard, truthy: !guard.truthy }
}

const conditionBindings = new WeakMap<ts.Expression, ts.Declaration[]>()
const conditionKeys = new WeakMap<Condition, string>()

function declarationMount(
  declaration: ts.Node,
  mount?: ViewMount,
): ViewMount | undefined {
  for (let current = mount; current; current = current.sender) {
    const render = current.view.jsxRender
    if (
      render &&
      render.getSourceFile() === declaration.getSourceFile() &&
      render.pos <= declaration.pos &&
      declaration.end <= render.end
    )
      return current
  }
  return undefined
}

function conditionKey(guard: Condition): string {
  const cached = conditionKeys.get(guard)
  if (cached !== undefined) return cached
  const key = scopedConditionKey(guard)
  conditionKeys.set(guard, key)
  return key
}

function scopedConditionKey(guard: Condition): string {
  const { expression, value, view, mount } = guard
  const stable = stableCondition(guard)
  if (stable) {
    const { subject, comparison } = stable
    if (!comparison) return JSON.stringify(['stable', subject])
    const { literal, equal } = comparison
    return JSON.stringify([
      'stable',
      subject,
      equal === guard.truthy,
      typeof literal,
      String(literal),
    ])
  }
  if (view && !view.jsxResolve)
    return JSON.stringify([view.path, view.offset, mount?.id, expression])
  let bindings = conditionBindings.get(value)
  if (!bindings) {
    const declarations: ts.Declaration[] = []
    const read = (node: ts.Node): void => {
      if (
        ts.isIdentifier(node) &&
        !(
          ts.isPropertyAccessExpression(node.parent) &&
          node.parent.name === node
        )
      ) {
        const declaration = jsxDeclaration(node.text, node)
        if (declaration) declarations.push(declaration)
      }
      ts.forEachChild(node, read)
    }
    read(value)
    conditionBindings.set(value, declarations)
    bindings = declarations
  }
  return bindings.length
    ? JSON.stringify([
        expression,
        bindings.map((declaration) => [
          declaration.getSourceFile().fileName,
          declaration.pos,
          declarationMount(declaration, mount)?.id,
        ]),
      ])
    : expression
}

function branch(conditions: Condition[], children: RenderNode[]): Branch {
  return { kind: 'branch', conditions, children }
}

function attribute(name: string, value?: ts.Expression): Attribute {
  return {
    name,
    literal: value && stringValue(unparenthesized(value)),
    expression: value,
  }
}

function key(name: ts.PropertyName): string | undefined {
  if (ts.isComputedPropertyName(name))
    return stringValue(transparent(name.expression))
  return ts.isIdentifier(name) ||
    ts.isStringLiteralLike(name) ||
    ts.isNumericLiteral(name)
    ? name.text
    : undefined
}

function transparent(node: ts.Expression): ts.Expression {
  node = unparenthesized(node)
  while (
    ts.isAsExpression(node) ||
    ts.isSatisfiesExpression(node) ||
    ts.isTypeAssertionExpression(node) ||
    ts.isNonNullExpression(node)
  )
    node = unparenthesized(node.expression)
  return node
}

function access(value: ts.Expression): [ts.Expression, string] | undefined {
  if (ts.isPropertyAccessExpression(value))
    return [value.expression, value.name.text]
  if (ts.isElementAccessExpression(value)) {
    const argument = transparent(value.argumentExpression)
    if (ts.isStringLiteralLike(argument) || ts.isNumericLiteral(argument))
      return [value.expression, argument.text]
  }
  return undefined
}

function objectEntries(
  value: ts.Expression,
  entries = new Map<string, ts.Expression | undefined>(),
  unknown?: (spread?: ts.Expression) => ReadonlySet<string> | void,
): Map<string, ts.Expression | undefined> | undefined {
  value = transparent(value)
  if (!ts.isObjectLiteralExpression(value)) return undefined
  for (const entry of value.properties) {
    if (ts.isSpreadAssignment(entry)) {
      if (!objectEntries(entry.expression, entries, unknown)) {
        const keys = unknown?.(entry.expression)
        if (keys) for (const name of keys) entries.delete(name)
        else entries.clear()
      }
      continue
    }
    const name = key(entry.name)
    if (name === undefined) {
      entries.clear()
      unknown?.()
      continue
    }
    entries.set(
      name,
      ts.isPropertyAssignment(entry)
        ? entry.initializer
        : ts.isShorthandPropertyAssignment(entry)
          ? entry.name
          : undefined,
    )
  }
  return entries
}

function property(
  object: ts.ObjectLiteralExpression,
  name: string,
): ts.Expression | undefined {
  const found = object.properties.find(
    (entry): entry is ts.PropertyAssignment =>
      ts.isPropertyAssignment(entry) && key(entry.name) === name,
  )
  return found?.initializer
}

function kebabCase(value: string): string {
  return value.replace(/([a-z\d])([A-Z])/g, '$1-$2').toLowerCase()
}

/** Reads runtime imports and preserves their local names for template lookup. */
function imports(
  file: ts.SourceFile,
  resolve: ResolveImport,
): Map<string, () => Binding | undefined> {
  const bindings = new Map<string, () => Binding | undefined>()
  for (const statement of file.statements) {
    if (
      !ts.isImportDeclaration(statement) ||
      !ts.isStringLiteral(statement.moduleSpecifier)
    ) {
      continue
    }
    const { importClause, moduleSpecifier } = statement
    if (!importClause || importClause.isTypeOnly) continue

    const path = moduleSpecifier.text
    if (importClause.name) {
      bindings.set(importClause.name.text, () => resolve(path, 'default'))
    }
    const named = importClause.namedBindings
    if (named && ts.isNamedImports(named)) {
      for (const element of named.elements) {
        if (element.isTypeOnly) continue
        const imported = (element.propertyName ?? element.name).text
        bindings.set(element.name.text, () => resolve(path, imported))
      }
    } else if (named) {
      bindings.set(named.name.text, () => ({ component: path }))
    }
  }
  return bindings
}

function importedBinding(
  name: string,
  bindings: ReturnType<typeof imports>,
  resolve: ResolveImport,
): Binding | undefined {
  const [namespace, member, ...rest] = name.split('.')
  if (member && !rest.length) {
    const binding = bindings.get(namespace)?.()
    if (binding?.reactContext && member === 'Provider')
      return { component: REACT_PROVIDER }
    return binding?.component ? resolve(binding.component, member) : undefined
  }
  return bindings.get(name)?.()
}

function localAlias(node: ts.Expression): string | undefined {
  if (ts.isIdentifier(node)) return node.text
  if (ts.isPropertyAccessExpression(node) && ts.isIdentifier(node.expression))
    return `${node.expression.text}.${node.name.text}`
  return undefined
}

function vueModule(
  path: string,
  source: string,
  resolveImport: ResolveImport,
): SourceModule {
  const root = parseVue(source, { parseMode: 'sfc', onError() {} })
  const elements = root.children.filter(
    (node): node is ElementNode => node.type === NodeTypes.ELEMENT,
  )
  const scopes: ResolveTag[] = []
  const scripts: ts.SourceFile[] = []
  for (const script of elements.filter((node) => node.tag === 'script')) {
    const code = script.children[0]
    if (code?.type !== NodeTypes.TEXT) continue
    const file = ts.createSourceFile(
      path,
      code.content,
      ts.ScriptTarget.Latest,
      true,
      ts.ScriptKind.TS,
    )
    scripts.push(file)
    const bindings = imports(file, resolveImport)
    const aliases = new Map<string, string>()
    for (const statement of file.statements) {
      if (
        ts.isVariableStatement(statement) &&
        statement.declarationList.flags & ts.NodeFlags.Const
      ) {
        for (const { name, initializer } of statement.declarationList
          .declarations) {
          const alias = initializer && localAlias(initializer)
          if (ts.isIdentifier(name) && alias) aliases.set(name.text, alias)
        }
      }
    }
    const setup = script.props.some(
      (prop) => prop.type === NodeTypes.ATTRIBUTE && prop.name === 'setup',
    )
    const registered = setup ? undefined : vueRegisteredComponents(file)
    scopes.push((name) => {
      const local = registered ? registered.get(name) : name
      if (!local) return undefined
      return importedBinding(
        aliases.get(local) ?? local,
        bindings,
        resolveImport,
      )
    })
  }
  const resolve: ResolveTag = (name) => {
    const camel = name.replace(/-([a-z])/g, (_, letter: string) =>
      letter.toUpperCase(),
    )
    const pascal = camel.charAt(0).toUpperCase() + camel.slice(1)
    for (const scope of scopes) {
      for (const alias of new Set([name, camel, pascal])) {
        const binding = scope(alias)
        if (binding) return binding
      }
    }
    return undefined
  }
  const views = elements
    .filter((element) => element.tag === 'template')
    .map((element) => ({
      path,
      offset: element.loc.start.offset,
      read: () => vueChildren(element.children, resolve),
      scripts,
      vueProps: vueProps(scripts),
      binding: (name: string) => {
        for (const file of scripts) {
          const declaration = statementDeclaration(file.statements, name)
          if (declaration) return declaration
        }
        return undefined
      },
    }))
  return {
    views,
    exported: (name) =>
      name === 'default' && views[0] ? { target: views[0] } : undefined,
  }
}

function vueRegisteredComponents(file: ts.SourceFile): Map<string, string> {
  const result = new Map<string, string>()
  const exported = file.statements.find(ts.isExportAssignment)?.expression
  const options =
    exported && ts.isCallExpression(exported) ? exported.arguments[0] : exported
  const components =
    options &&
    ts.isObjectLiteralExpression(options) &&
    property(options, 'components')
  if (components && ts.isObjectLiteralExpression(components)) {
    for (const entry of components.properties) {
      if (ts.isShorthandPropertyAssignment(entry))
        result.set(entry.name.text, entry.name.text)
      if (ts.isPropertyAssignment(entry)) {
        const name = key(entry.name)
        const local = localAlias(entry.initializer)
        if (name && local) result.set(name, local)
      }
    }
  }
  return result
}

function vueChildren(
  nodes: TemplateChildNode[],
  resolve: ResolveTag,
  scope: TemplateScope = new Map(),
): RenderNode[] {
  const children: RenderNode[] = []
  let remaining: Condition[] = []
  for (const node of nodes) {
    if (
      node.type === NodeTypes.COMMENT ||
      (node.type === NodeTypes.TEXT && !/[^ \t\r\n]/.test(node.content))
    )
      continue

    if (node.type === NodeTypes.ELEMENT) {
      const conditional = node.props.find(
        (prop): prop is DirectiveNode =>
          prop.type === NodeTypes.DIRECTIVE &&
          ['if', 'else-if', 'else'].includes(prop.name),
      )
      const guards = conditional?.name.startsWith('else') ? [...remaining] : []
      const test = conditional?.exp?.loc.source
      const value = test ? expression(test, scope) : undefined
      const guard = value && condition(value)
      remaining = guard ? [...guards, opposite(guard)] : []
      if (guard) guards.push(guard)
      const locals = new Map(scope)
      for (const prop of node.props) {
        if (prop.type !== NodeTypes.DIRECTIVE || !prop.exp) continue
        const pattern =
          prop.name === 'for'
            ? prop.forParseResult?.value?.loc.source
            : prop.name === 'slot'
              ? prop.exp.loc.source
              : undefined
        if (!pattern) continue
        const binder = expression('(' + pattern + ') => null')
        if (binder && ts.isArrowFunction(binder)) {
          const shadow = (name: ts.BindingName): void => {
            if (ts.isIdentifier(name)) locals.set(name.text, undefined)
            else
              for (const entry of name.elements)
                if (ts.isBindingElement(entry)) shadow(entry.name)
          }
          for (const parameter of binder.parameters) shadow(parameter.name)
        }
        for (const alias of [
          prop.forParseResult?.key,
          prop.forParseResult?.index,
        ])
          if (alias) locals.set(alias.loc.source, undefined)
      }
      const binding = resolve(node.tag)
      const attributes: Attribute[] = []
      for (const prop of node.props) {
        if (prop.type === NodeTypes.ATTRIBUTE) {
          attributes.push({ name: prop.name, literal: prop.value?.content })
        } else if (
          binding?.component === MODAL &&
          prop.name === 'model' &&
          !prop.arg &&
          prop.exp
        ) {
          attributes.push(
            attribute('modelValue', expression(prop.exp.loc.source, locals)),
          )
        } else if (['show', 'text'].includes(prop.name) && prop.exp) {
          attributes.push(
            attribute(
              'v-' + prop.name,
              expression(prop.exp.loc.source, locals),
            ),
          )
        } else if (
          prop.name === 'bind' &&
          prop.arg?.type === NodeTypes.SIMPLE_EXPRESSION &&
          prop.arg.isStatic
        ) {
          attributes.push(
            attribute(
              prop.arg.content,
              expression(prop.exp?.loc.source ?? '', locals),
            ),
          )
        } else if (prop.name === 'bind' && !prop.arg && prop.exp) {
          const value = expression(prop.exp.loc.source, locals)
          const entries = value && objectEntries(value)
          if (entries)
            for (const [name, value] of entries)
              attributes.push(attribute(name, value))
        }
      }
      const nested = vueChildren(node.children, resolve, locals)
      const actions = node.props.flatMap((prop) =>
        prop.type === NodeTypes.DIRECTIVE &&
        prop.name === 'on' &&
        prop.arg?.type === NodeTypes.SIMPLE_EXPRESSION &&
        prop.arg.isStatic &&
        ACTION_EVENTS.has(prop.arg.content) &&
        prop.exp
          ? parsedBindings([prop.exp.loc.source], locals)
          : [],
      )
      const namedSlot = node.props.find(
        (prop): prop is DirectiveNode =>
          prop.type === NodeTypes.DIRECTIVE && prop.name === 'slot',
      )
      const rendered: RenderNode[] =
        node.tag === 'template'
          ? namedSlot
            ? [
                {
                  ...slot(
                    '#' + (namedSlot.arg?.loc.source ?? 'default'),
                    node.loc.start.offset,
                    nested,
                  ),
                  vueSlotName:
                    namedSlot.arg?.type === NodeTypes.SIMPLE_EXPRESSION &&
                    !namedSlot.arg.isStatic
                      ? expression(namedSlot.arg.content, scope)
                      : undefined,
                },
              ]
            : nested
          : [
              {
                kind: 'element',
                name: node.tag,
                ...binding,
                offset: node.loc.start.offset,
                attributes,
                actions,
                children: nested,
              },
            ]
      children.push(...(guards.length ? [branch(guards, rendered)] : rendered))
    } else {
      remaining = []
      if (
        node.type === NodeTypes.TEXT ||
        node.type === NodeTypes.INTERPOLATION
      ) {
        children.push({
          kind: 'text',
          value:
            node.type === NodeTypes.TEXT
              ? node.content
              : node.content.loc.source,
          bindings:
            node.type === NodeTypes.INTERPOLATION
              ? parsedBindings([node.content.loc.source], scope)
              : undefined,
        })
      }
    }
  }
  return children
}

function angularViews(
  file: ts.SourceFile,
  resolve: ResolveTag,
  sources: ReadonlyMap<string, string>,
  imported: ResolveTag,
): Map<string, View> {
  const views = new Map<string, View>()
  for (const declaration of file.statements) {
    if (!ts.isClassDeclaration(declaration) || !declaration.name) continue
    for (const decorator of ts.getDecorators(declaration) ?? []) {
      const call = decorator.expression
      if (
        !ts.isCallExpression(call) ||
        imported(call.expression.getText(file))?.component !== 'Component'
      )
        continue
      const [metadata] = call.arguments
      if (!metadata || !ts.isObjectLiteralExpression(metadata)) continue

      const selector = property(metadata, 'selector')
      if (!selector || !ts.isStringLiteralLike(selector)) continue
      const dependencies = property(metadata, 'imports')
      const template = property(metadata, 'template')
      const templateUrl = property(metadata, 'templateUrl')
      const inline =
        template && ts.isStringLiteralLike(template) ? template : undefined
      const external =
        templateUrl && ts.isStringLiteralLike(templateUrl)
          ? posix.join(posix.dirname(file.fileName), templateUrl.text)
          : undefined
      const componentPath = file.fileName
      const path =
        !inline && external && sources.has(external) ? external : componentPath
      const start = inline ? inline.getStart(file) + 1 : 0
      const templates = new Map<string, Element>()
      const contentQueries = angularContentQueries(declaration, imported)
      const contentOutlets: AngularOutlet[] = []
      views.set(declaration.name.text, {
        path,
        scripts: [file],
        templates,
        contentQueries,
        contentOutlets,
        binding: (name) =>
          declaration.members.find(
            (member) =>
              (ts.isPropertyDeclaration(member) ||
                ts.isMethodDeclaration(member) ||
                ts.isGetAccessorDeclaration(member)) &&
              key(member.name) === name,
          ) ?? statementDeclaration(file.statements, name),
        offset: decorator.getStart(file),
        selector: selector.text,
        actionError: selector.text === kebabCase(ACTION_ERROR),
        read: () => {
          const raw = inline
            ? file.text.slice(start, inline.getEnd() - 1)
            : external && sources.get(external)
          if (raw === undefined) return []
          const components = new Map<string, Binding>()
          if (dependencies && ts.isArrayLiteralExpression(dependencies)) {
            for (const dependency of dependencies.elements) {
              const binding = resolve(dependency.getText(file))
              const names = binding?.target?.selector
                ?.split(',')
                .map((name) => name.trim())
              if (binding) {
                for (const name of names ??
                  (binding.component ? [kebabCase(binding.component)] : [])) {
                  components.set(name, binding)
                }
              }
            }
          }
          const { nodes, ngContentSelectors } = parseTemplate(raw, path, {
            escapedString: Boolean(inline),
            preserveLineEndings: true,
          })
          const projectionViews: AngularOutlet[][] = [[]]
          const roots = angularChildren(nodes, {
            start,
            resolve: (name) => components.get(name),
            templates,
            projectionViews,
            ngIf: Boolean(
              dependencies &&
              ts.isArrayLiteralExpression(dependencies) &&
              dependencies.elements.some((entry) =>
                ['NgIf', 'CommonModule'].includes(
                  resolve(entry.getText(file))?.component ?? '',
                ),
              ),
            ),
          })
          const outlets = projectionViews.flat()
          // An unmodeled template construct must not change another outlet's priority.
          if (ngContentSelectors.length === outlets.length)
            contentOutlets.push(...outlets)
          return roots
        },
      })
    }
  }
  return views
}

function angularContentQueries(
  declaration: ts.ClassDeclaration,
  imported: ResolveTag,
): Map<string, string> {
  const queries = new Map<string, string>()
  for (const member of declaration.members) {
    if (
      !ts.isPropertyDeclaration(member) ||
      !member.initializer ||
      !ts.isCallExpression(member.initializer)
    )
      continue
    const call = member.initializer
    const method = call.expression.getText()
    if (
      imported(method.replace(/\.required$/, ''))?.component !== 'contentChild'
    )
      continue
    const name = key(member.name)
    const [reference] = call.arguments
    if (name && reference && ts.isStringLiteralLike(reference))
      queries.set(name, reference.text)
  }
  return queries
}

function templateOutlet(
  value: ts.Expression | undefined,
  aliases: ReadonlyMap<string, string>,
): string | undefined {
  if (!value) return undefined
  value = unparenthesized(value)
  if (ts.isIdentifier(value)) return aliases.get(value.text) ?? value.text
  if (
    ts.isCallExpression(value) &&
    !value.arguments.length &&
    ts.isIdentifier(value.expression)
  )
    return `${value.expression.text}()`
  if (
    ts.isBinaryExpression(value) &&
    value.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken &&
    value.right.kind === ts.SyntaxKind.NullKeyword
  ) {
    return templateOutlet(value.left, aliases)
  }
  return undefined
}

function angularExpression(ast: AST | undefined): string {
  return ast instanceof ASTWithSource ? (ast.source ?? '') : ''
}

function angularBindings(ast: AST, scope: TemplateScope): ts.Expression[] {
  if (!(ast instanceof ASTWithSource) || !(ast.ast instanceof Interpolation))
    return []
  const source = ast.source ?? ''
  return parsedBindings(
    ast.ast.expressions.map(({ span }) => source.slice(span.start, span.end)),
    scope,
  )
}

function angularAttribute(
  input: TmplAstBoundAttribute,
  scope: TemplateScope,
): Attribute {
  const prefixes: Partial<Record<BindingType, string>> = {
    [BindingType.Class]: 'class.',
    [BindingType.Style]: 'style.',
  }
  return {
    ...attribute(
      (prefixes[input.type] ?? '') + input.name,
      expression(angularExpression(input.value), scope),
    ),
    bindingType: input.type,
  }
}

interface AngularOutlet {
  offset: number
  selector: string
}

interface AngularTemplate {
  start: number
  resolve: ResolveTag
  templates: Map<string, Element>
  projectionViews: AngularOutlet[][]
  ngIf: boolean
}

function angularChildren(
  nodes: TmplAstNode[],
  template: AngularTemplate,
  inherited: TemplateScope = new Map(),
  outlets = template.projectionViews[0]!,
): RenderNode[] {
  const { start, resolve, templates, projectionViews } = template
  let locals = inherited
  const embedded = (children: TmplAstNode[], scope = locals): RenderNode[] => {
    // Projection indexes follow view creation order, including the root first.
    const slots: AngularOutlet[] = []
    projectionViews.push(slots)
    return angularChildren(children, template, scope, slots)
  }
  const outletName = (value: ts.Expression | undefined): string | undefined => {
    const alias = value && templateLocal(value)
    return templateOutlet(alias ? alias.value : value, new Map())
  }
  const markup = (
    node: TmplAstElement | TmplAstTemplate,
    name: string,
  ): Element => ({
    ...slot(name, start + node.startSourceSpan.start.offset, []),
    attributes: [
      ...node.attributes.map(({ name, value }) => ({ name, literal: value })),
      ...node.inputs.map((input) => angularAttribute(input, locals)),
    ],
  })
  const insertion = (items: TmplAstNode[]): Element | null => {
    const roots = items.filter(
      (item) => !(item instanceof TmplAstLetDeclaration),
    )
    const [root] = roots
    return roots.length === 1 &&
      (root instanceof TmplAstElement ||
        (root instanceof TmplAstTemplate && root.tagName))
      ? markup(
          root,
          root instanceof TmplAstElement ? root.name : (root.tagName ?? ''),
        )
      : null
  }
  const insert = (
    conditions: Condition[],
    items: TmplAstNode[],
    scope = locals,
  ): Branch => ({
    ...branch(conditions, embedded(items, scope)),
    insertion: insertion(items),
  })
  const children: RenderNode[] = []
  for (const node of nodes) {
    if (node instanceof TmplAstLetDeclaration) {
      locals = new Map(locals).set(
        node.name,
        expression(angularExpression(node.value), locals),
      )
    } else if (node instanceof TmplAstIfBlock) {
      const remaining: Condition[] = []
      for (const arm of node.branches) {
        const value = expression(
          angularExpression(arm.expression ?? undefined),
          locals,
        )
        const guard = value && condition(value)
        const scope = arm.expressionAlias
          ? new Map(locals).set(arm.expressionAlias.name, value)
          : locals
        children.push(
          insert(
            guard ? [...remaining, guard] : [...remaining],
            arm.children,
            scope,
          ),
        )
        if (guard) remaining.push(opposite(guard))
      }
    } else if (node instanceof TmplAstContent) {
      const offset = start + node.sourceSpan.start.offset
      outlets.push({ offset, selector: node.selector })
      children.push({
        ...slot('ng-content', offset, embedded(node.children)),
        attributes: node.attributes.map(({ name, value }) => ({
          name,
          literal: value,
        })),
      })
    } else if (node instanceof TmplAstElement) {
      children.push({
        ...markup(node, node.name),
        ...resolve(node.name),
        outlet: outletName(
          expression(
            angularExpression(
              node.inputs.find(({ name }) => name === 'ngTemplateOutlet')
                ?.value,
            ),
            locals,
          ),
        ),
        actions: parsedBindings(
          node.outputs
            .filter(({ name }) => ACTION_EVENTS.has(name))
            .map(({ handler }) => angularExpression(handler)),
          locals,
        ),
        children: angularChildren(node.children, template, locals, outlets),
      })
    } else if (node instanceof TmplAstTemplate) {
      const bindings = new Map(locals)
      for (const variable of node.variables)
        bindings.set(variable.name, undefined)
      const nested = embedded(node.children, bindings)
      if (node.tagName === 'ng-template') {
        const tableCell = [...node.attributes, ...node.inputs].find(
          ({ name }) => ['hilosTableCell', 'hilosTableDetail'].includes(name),
        )
        const reference = node.references[0]?.name
        const declaration = {
          ...markup(node, '#' + (tableCell?.name ?? reference ?? '')),
          templateReference: reference ?? '',
          children: nested,
        }
        if (reference) templates.set(reference, declaration)
        children.push(declaration)
        continue
      }
      const structural = (name: string): ts.Expression | undefined => {
        const found = node.templateAttrs.find(
          (attr) => attr instanceof TmplAstBoundAttribute && attr.name === name,
        )
        return found instanceof TmplAstBoundAttribute
          ? angularAttribute(found, locals).expression
          : undefined
      }
      const outletValue = structural('ngTemplateOutlet')
      if (outletValue) {
        const anchor = markup(node, 'ng-container')
        anchor.attributes.push(attribute('ngTemplateOutlet', outletValue))
        children.push({ ...anchor, outlet: outletName(outletValue) })
        continue
      }
      const value = structural('ngIf')
      const anchor = markup(node, node.tagName ?? '')
      const templateBranch = (selection: ts.Expression): RenderNode[] => [
        {
          ...slot('ng-container', anchor.offset, []),
          outlet: outletName(selection),
          attributes: [attribute('ngTemplateOutlet', selection)],
        },
      ]
      const primary = template.ngIf && value && structural('ngIfThen')
      children.push({
        ...branch(
          value ? [condition(value)] : [],
          primary ? templateBranch(primary) : nested,
        ),
        insertion: primary ? null : anchor,
      })
      const alternate = template.ngIf && value && structural('ngIfElse')
      if (value && alternate) {
        children.push({
          ...branch([condition(value, false)], templateBranch(alternate)),
          insertion: null,
        })
      }
    } else if (
      node instanceof TmplAstText ||
      node instanceof TmplAstBoundText
    ) {
      children.push({
        kind: 'text',
        value:
          node instanceof TmplAstText
            ? node.value
            : angularExpression(node.value),
        bindings:
          node instanceof TmplAstBoundText
            ? angularBindings(node.value, locals)
            : undefined,
        literalText:
          node instanceof TmplAstBoundText &&
          node.value instanceof ASTWithSource &&
          node.value.ast instanceof Interpolation
            ? node.value.ast.strings.join('')
            : undefined,
      })
    } else if (node instanceof TmplAstForLoopBlock) {
      const scope = new Map(locals)
      for (const local of [node.item, ...node.contextVariables])
        scope.set(local.name, undefined)
      children.push(insert([], node.children, scope))
      if (node.empty) children.push(insert([], node.empty.children))
    } else if (node instanceof TmplAstSwitchBlock) {
      const subject = angularExpression(node.expression)
      const remaining: Condition[] = []
      const groups = node.groups.map((group) => ({
        group,
        guards: group.cases.map((arm) => {
          const comparison =
            arm.expression &&
            expression(
              '(' +
                subject +
                ') === (' +
                angularExpression(arm.expression) +
                ')',
              locals,
            )
          return comparison ? condition(comparison) : undefined
        }),
      }))
      const fallback = groups.flatMap(({ guards }) =>
        guards.flatMap((guard) => (guard ? [opposite(guard)] : [])),
      )
      for (const { group, guards } of groups) {
        const inserted = insert([], group.children)
        for (const guard of guards) {
          children.push({
            ...inserted,
            conditions: guard ? [...remaining, guard] : fallback,
          })
          if (guard) remaining.push(opposite(guard))
        }
      }
    }
  }
  return children
}

function jsxNodes(
  node: ts.Expression,
  resolve: ResolveTag,
  seen = new Set<ts.Expression>(),
): RenderNode[] {
  node = transparent(node)
  if (seen.has(node) || seen.size >= 64) return []
  seen = new Set(seen).add(node)
  if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
    const opening = ts.isJsxElement(node) ? node.openingElement : node
    const name = opening.tagName.getText()
    const binding = resolve(name, opening.tagName)
    const component = binding?.component
    const attributes = new Map<string, Attribute>()
    const offsets = new Map<string, number>()
    let unknownInputs = false
    const suppliedChildren = ts.isJsxElement(node)
      ? node.children.filter((child) =>
          ts.isJsxText(child)
            ? Boolean(
                child.text && (!/[\r\n]/.test(child.text) || child.text.trim()),
              )
            : !ts.isJsxExpression(child) || Boolean(child.expression),
        )
      : []
    const [onlyChild] = suppliedChildren
    const explicitChildren = suppliedChildren.length > 0
    const jsxChildValue =
      suppliedChildren.length === 1 &&
      onlyChild &&
      ts.isJsxExpression(onlyChild)
        ? onlyChild.expression
        : undefined
    const children = ts.isJsxElement(node)
      ? jsxChildren(node.children, resolve, seen)
      : []
    for (const prop of opening.attributes.properties) {
      if (ts.isJsxSpreadAttribute(prop)) {
        const entries = objectEntries(prop.expression, undefined, () => {
          unknownInputs = true
          attributes.clear()
        })
        if (!entries) {
          unknownInputs = true
          attributes.clear()
        } else
          for (const [name, value] of entries) {
            attributes.set(name, attribute(name, value))
            offsets.set(name, prop.getStart())
          }
        continue
      }
      const name = prop.name.getText()
      const value = prop.initializer
      const attr =
        value && ts.isJsxExpression(value)
          ? attribute(name, value.expression)
          : {
              name,
              literal:
                value && ts.isStringLiteral(value) ? value.text : undefined,
            }
      attributes.set(name, attr)
      offsets.set(name, prop.getStart())
    }
    const actions: ts.Expression[] = []
    for (const [name, { expression }] of attributes) {
      if (!expression) continue
      if (name === 'onClick' || name === 'onSubmit') actions.push(expression)
      if (
        (component === MODAL &&
          MODAL_SLOTS.has(name) &&
          (name !== 'children' || !explicitChildren)) ||
        (component && TABLES.has(component) && TABLE_PROPS.has(name))
      ) {
        const body = jsxSlot(expression, resolve, seen)
        if (
          body.length ||
          name !== 'body' ||
          literalTruth(expression) !== false
        )
          children.push({
            ...slot(name, offsets.get(name)!, body),
            jsxSlot: true,
          })
      }
    }
    return [
      {
        kind: 'element',
        name,
        ...binding,
        offset: opening.getStart(),
        attributes: [...attributes.values()],
        actions,
        explicitChildren,
        jsxChildValue,
        unknownInputs,
        children,
      },
    ]
  }
  if (ts.isJsxFragment(node)) return jsxChildren(node.children, resolve, seen)
  const choice =
    ts.isBinaryExpression(node) &&
    node.operatorToken.kind === ts.SyntaxKind.BarBarToken
      ? jsxValueChoice(node)
      : jsxChoice(node)
  if (choice)
    return choice.map(([guard, arm]) =>
      branch([jsxSelectionTest(node, guard)], jsxNodes(arm, resolve, seen)),
    )
  if (
    ts.isBinaryExpression(node) &&
    node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
  ) {
    return [
      branch(
        [jsxSelectionTest(node, condition(node.left))],
        jsxNodes(node.right, resolve, seen),
      ),
    ]
  }
  if (
    ts.isCallExpression(node) &&
    ts.isPropertyAccessExpression(node.expression) &&
    node.expression.name.text === 'map'
  ) {
    const [callback] = node.arguments
    if (
      callback &&
      (ts.isArrowFunction(callback) || ts.isFunctionExpression(callback))
    ) {
      return jsxSlot(callback, resolve, seen)
    }
  }
  if (ts.isCallExpression(node)) {
    const supplied = node.arguments.flatMap((argument) =>
      jsxSlot(argument, resolve, seen),
    )
    if (siblingElements(supplied).length)
      return [
        {
          ...slot('unresolved-render', node.getStart(), supplied),
          unresolvedMount: true,
        },
      ]
  }
  if (ts.isArrayLiteralExpression(node))
    return node.elements.flatMap((item) => jsxNodes(item, resolve, seen))
  const reference = jsxReference(node)
  if (reference && !ts.isFunctionDeclaration(reference)) {
    const resolved = jsxNodes(reference, resolve, seen)
    if (resolved.some((item) => item.kind !== 'text') || !resolved.length)
      return resolved
  }
  if (
    [
      ts.SyntaxKind.NullKeyword,
      ts.SyntaxKind.FalseKeyword,
      ts.SyntaxKind.TrueKeyword,
    ].includes(node.kind)
  )
    return []
  const literal = stringValue(node)
  return [
    {
      kind: 'text',
      value: literal ?? node.getText(),
      bindings: literal === undefined ? [node] : undefined,
    },
  ]
}

function jsxChildren(
  children: readonly ts.JsxChild[],
  resolve: ResolveTag,
  seen: Set<ts.Expression>,
): RenderNode[] {
  return children.flatMap((child) => {
    if (ts.isJsxText(child))
      return [{ kind: 'text', value: child.text } satisfies Text]
    if (ts.isJsxExpression(child))
      return child.expression ? jsxNodes(child.expression, resolve, seen) : []
    return jsxNodes(child, resolve, seen)
  })
}

function binds(name: ts.BindingName, local: string): boolean {
  if (ts.isIdentifier(name)) return name.text === local
  return name.elements.some(
    (entry) => ts.isBindingElement(entry) && binds(entry.name, local),
  )
}

function statementDeclaration(
  statements: readonly ts.Statement[],
  name: string,
): ts.Declaration | undefined {
  for (const statement of statements) {
    if (ts.isFunctionDeclaration(statement) && statement.name?.text === name)
      return statement
    if (!ts.isVariableStatement(statement)) continue
    const declaration = statement.declarationList.declarations.find(
      (declaration) => binds(declaration.name, name),
    )
    if (declaration) return declaration
  }
  return undefined
}

const jsxDeclarations = new WeakMap<
  ts.Node,
  Map<string, ts.Declaration | null>
>()

/** Looks up a local JSX binding, including declarations that shadow an import. */
function jsxDeclaration(
  name: string,
  location: ts.Node,
): ts.Declaration | undefined {
  const parent = location.parent
  if (!parent) return undefined
  // Mounted views share the same parsed lexical scopes.
  let declarations = jsxDeclarations.get(parent)
  if (!declarations) {
    declarations = new Map()
    jsxDeclarations.set(parent, declarations)
  }
  const cached = declarations.get(name)
  if (cached !== undefined) return cached ?? undefined

  let declaration: ts.Declaration | undefined
  for (let scope: ts.Node | undefined = parent; scope; scope = scope.parent) {
    if (ts.isFunctionLike(scope)) {
      declaration = scope.parameters.find((parameter) =>
        binds(parameter.name, name),
      )
      if (declaration) break
    }
    if (!ts.isSourceFile(scope) && !ts.isBlock(scope)) continue
    declaration = statementDeclaration(scope.statements, name)
    if (declaration) break
  }
  declarations.set(name, declaration ?? null)
  return declaration
}

function jsxReference(
  value: ts.Expression,
): ts.Expression | ts.FunctionDeclaration | undefined {
  const member = access(value)
  const identifier = member?.[0] ?? value
  if (!ts.isIdentifier(identifier)) return undefined
  const binding = jsxDeclaration(identifier.text, identifier)
  if (binding && ts.isFunctionDeclaration(binding) && !member) return binding
  if (
    !binding ||
    (!ts.isVariableDeclaration(binding) && !ts.isParameter(binding))
  )
    return undefined
  if (ts.isParameter(binding)) {
    if (!variableWritten(binding, identifier.text, member?.[1]))
      return undefined
    const assigned = assignedParameter(binding, identifier)
    if (!assigned) return undefined
    return member ? objectEntries(assigned)?.get(member[1]) : assigned
  }
  if (ts.isIdentifier(binding.name) && !member) {
    const input = immutableInitializer(binding)
    return input && transparent(input)
  }
  return undefined
}

function assignedParameter(
  binding: ts.ParameterDeclaration,
  reference: ts.Identifier,
): ts.Expression | undefined {
  const procedure = binding.parent
  if (
    !ts.isFunctionLike(procedure) ||
    !('body' in procedure) ||
    !procedure.body ||
    !ts.isBlock(procedure.body)
  )
    return undefined
  const matches = (target: ts.Expression): boolean =>
    ts.isIdentifier(target) &&
    target.text === reference.text &&
    jsxDeclaration(target.text, target) === binding
  let known: ts.Expression | undefined
  for (const statement of procedure.body.statements) {
    if (statement.end > reference.getStart()) break
    if (
      ts.isExpressionStatement(statement) &&
      ts.isBinaryExpression(statement.expression) &&
      statement.expression.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
      matches(statement.expression.left)
    ) {
      known = statement.expression.right
    } else if (writtenUnder(statement, matches)) {
      known = undefined
    }
  }
  return known
}

function jsxChoice(
  value: ts.Expression,
): [Condition, ts.Expression][] | undefined {
  if (ts.isConditionalExpression(value)) {
    const test = condition(value.condition)
    return [
      [test, value.whenTrue],
      [opposite(test), value.whenFalse],
    ]
  }
  if (
    ts.isBinaryExpression(value) &&
    value.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken
  ) {
    const test = condition(value.left, true, true)
    return [
      [test, value.right],
      [opposite(test), value.left],
    ]
  }
  return undefined
}

function jsxValueChoice(
  value: ts.Expression,
): [Condition, ts.Expression][] | undefined {
  if (ts.isBinaryExpression(value)) {
    const operator = value.operatorToken.kind
    const and = operator === ts.SyntaxKind.AmpersandAmpersandToken
    if (and || operator === ts.SyntaxKind.BarBarToken) {
      const test = condition(value.left, and)
      return [
        [test, value.right],
        [opposite(test), value.left],
      ]
    }
  }
  return jsxChoice(value)
}

function jsxSlot(
  node: ts.Expression,
  resolve: ResolveTag,
  seen = new Set<ts.Expression>(),
): RenderNode[] {
  node = transparent(node)
  if (seen.has(node) || seen.size >= 64) return []
  const value = jsxReference(node) ?? node
  if (value !== node && !ts.isFunctionDeclaration(value))
    return jsxSlot(value, resolve, new Set(seen).add(node))
  if (!ts.isFunctionDeclaration(value)) {
    const choice = jsxValueChoice(value)
    if (choice)
      return choice.map(([guard, arm]) =>
        branch(
          [jsxSelectionTest(value, guard)],
          jsxSlot(arm, resolve, new Set(seen).add(node)),
        ),
      )
  }
  if (ts.isObjectLiteralExpression(value)) {
    return value.properties.flatMap((entry) => {
      if (ts.isPropertyAssignment(entry))
        return jsxSlot(entry.initializer, resolve, new Set(seen).add(node))
      if (ts.isShorthandPropertyAssignment(entry))
        return jsxSlot(entry.name, resolve, new Set(seen).add(node))
      if (ts.isMethodDeclaration(entry) && entry.body)
        return jsxReturns(entry.body, resolve, new Set(seen).add(node))
      return []
    })
  }
  if (
    ts.isFunctionDeclaration(value) ||
    ts.isArrowFunction(value) ||
    ts.isFunctionExpression(value)
  ) {
    if (!value.body) return []
    return ts.isBlock(value.body)
      ? jsxReturns(value.body, resolve, new Set(seen).add(node))
      : jsxNodes(value.body, resolve, new Set(seen).add(node))
  }
  return jsxNodes(value, resolve, seen)
}

function jsxReturns(
  block: ts.Block,
  resolve: ResolveTag,
  seen = new Set<ts.Expression>(),
): RenderNode[] {
  const roots: RenderNode[] = []
  const visit = (node: ts.Node, guards: Condition[]): Condition[][] => {
    if (ts.isReturnStatement(node)) {
      if (node.expression) {
        const rendered = jsxNodes(node.expression, resolve, seen)
        roots.push(...(guards.length ? [branch(guards, rendered)] : rendered))
      }
      return []
    }
    if (ts.isBlock(node)) {
      return node.statements.reduce(
        (scopes, statement) =>
          scopes.flatMap((scope) => visit(statement, scope)),
        [guards],
      )
    }
    if (ts.isIfStatement(node)) {
      const test = condition(node.expression)
      const whenTrue = [...guards, test]
      const whenFalse = [...guards, opposite(test)]
      const truthy = possible(whenTrue)
        ? visit(node.thenStatement, whenTrue)
        : []
      const falsy = possible(whenFalse)
        ? node.elseStatement
          ? visit(node.elseStatement, whenFalse)
          : [whenFalse]
        : []
      if (
        truthy.length === 1 &&
        truthy[0] === whenTrue &&
        falsy.length === 1 &&
        falsy[0] === whenFalse
      )
        return [guards]
      return [...truthy, ...falsy]
    }
    if (!ts.isFunctionLike(node))
      ts.forEachChild(node, (child) => {
        visit(child, guards)
      })
    return [guards]
  }
  visit(block, [])
  return roots
}

function jsxRootRender(
  statement: ts.ExpressionStatement,
  file: ts.SourceFile,
  resolve: ResolveTag,
): View | undefined {
  const call = transparent(statement.expression)
  if (!ts.isCallExpression(call) || call.arguments.length !== 1) return
  const method = access(call.expression)
  if (method?.[1] !== 'render') return
  let root = transparent(method[0])
  const seen = new Set<ts.Declaration>()
  while (ts.isIdentifier(root)) {
    const binding = jsxDeclaration(root.text, root)
    if (!binding || seen.has(binding)) break
    const input = immutableInitializer(binding)
    if (!input) break
    seen.add(binding)
    root = transparent(input)
  }
  const view: View = {
    path: file.fileName,
    offset: statement.getStart(file),
    scripts: [file],
    jsxResolve: resolve,
    jsxRender: statement,
    read: () => jsxNodes(call.arguments[0]!, resolve),
  }
  const factory =
    ts.isCallExpression(root) && expressionImport(root.expression, view)
  view.unresolvedMount = !(
    factory &&
    factory.module === 'react-dom/client' &&
    factory.name === 'createRoot'
  )
  return view
}

function jsxViews(
  file: ts.SourceFile,
  resolve: ResolveTag,
): { declarations: Map<string, View>; roots: View[] } {
  const declarations = new Map<string, View>()
  const roots: View[] = []
  const byDeclaration = new Map<ts.Node, View>()
  const aliases = new Set<ts.Node>()
  const scoped: ResolveTag = (name, location) => {
    const [local, ...member] = name.split('.')
    const declaration = location && jsxDeclaration(local, location)
    if (!declaration) return resolve(name)
    if (member.length) {
      if (
        member.length === 1 &&
        member[0] === 'Provider' &&
        ts.isVariableDeclaration(declaration) &&
        ts.isSourceFile(declaration.parent.parent.parent)
      )
        return resolve(name)
      return undefined
    }
    const known = byDeclaration.get(declaration)
    if (known) return { target: known }
    if (ts.isFunctionDeclaration(declaration) && declaration.body) {
      return { target: declare(local, declaration, declaration) }
    }
    if (
      ts.isVariableDeclaration(declaration) &&
      declaration.initializer &&
      declaration.parent.flags & ts.NodeFlags.Const
    ) {
      const value = unparenthesized(declaration.initializer)
      if (ts.isArrowFunction(value) || ts.isFunctionExpression(value)) {
        return { target: declare(local, declaration, value) }
      }
      const alias = localAlias(value)
      if (alias && !aliases.has(declaration)) {
        aliases.add(declaration)
        const binding = scoped(alias, value)
        aliases.delete(declaration)
        return binding
      }
    }
    return undefined
  }
  const declare = (
    name: string,
    declaration: ts.Node,
    render: ts.FunctionDeclaration | ts.ArrowFunction | ts.FunctionExpression,
  ): View => {
    const body = render.body
    const view: View = {
      path: file.fileName,
      offset: declaration.getStart(file),
      scripts: [file],
      jsxParameter: render.parameters[0],
      jsxResolve: scoped,
      jsxRender: render,
      read: () =>
        !body
          ? []
          : ts.isBlock(body)
            ? jsxReturns(body, scoped)
            : jsxNodes(body, scoped),
      actionError: name === ACTION_ERROR,
    }
    byDeclaration.set(declaration, view)
    return view
  }

  for (const statement of file.statements) {
    if (ts.isFunctionDeclaration(statement) && statement.body) {
      const name = statement.name?.text ?? 'default'
      declarations.set(name, declare(name, statement, statement))
    } else if (ts.isVariableStatement(statement)) {
      for (const declaration of statement.declarationList.declarations) {
        const { name, initializer } = declaration
        if (!ts.isIdentifier(name) || !initializer) continue
        const value = unparenthesized(initializer)
        if (ts.isArrowFunction(value) || ts.isFunctionExpression(value)) {
          declarations.set(name.text, declare(name.text, declaration, value))
        }
      }
    } else if (ts.isExportAssignment(statement)) {
      const value = unparenthesized(statement.expression)
      if (ts.isArrowFunction(value) || ts.isFunctionExpression(value)) {
        declarations.set('default', declare('default', statement, value))
      }
    } else if (ts.isExpressionStatement(statement)) {
      const root = jsxRootRender(statement, file, scoped)
      if (root) roots.push(root)
    }
  }

  // Only skip a standalone visit when every reference to a private component
  // is a statically named JSX mount. Exported or escaped values may be mounted
  // by code outside the known call sites.
  const called = new Set<View>()
  const escaped = new Set<View>()
  const candidates = new Set(declarations.values())
  const names = new Set(declarations.keys())
  const identifiers: ts.Identifier[] = []
  const aliasInputs = new Map<ts.VariableDeclaration, ts.Identifier>()
  const collect = (node: ts.Node): void => {
    if (ts.isIdentifier(node)) identifiers.push(node)
    if (
      ts.isVariableDeclaration(node) &&
      ts.isIdentifier(node.name) &&
      node.initializer &&
      node.parent.flags & ts.NodeFlags.Const
    ) {
      const alias = unparenthesized(node.initializer)
      if (ts.isIdentifier(alias)) {
        names.add(node.name.text)
        aliasInputs.set(node, alias)
      }
    }
    ts.forEachChild(node, collect)
  }
  collect(file)

  const resolved = new Map<ts.Declaration, View | undefined>()
  const referencedView = (name: ts.Identifier): View | undefined => {
    const declaration = jsxDeclaration(name.text, name)
    if (!declaration) return undefined
    if (resolved.has(declaration)) return resolved.get(declaration)
    const view = byDeclaration.get(declaration)
    if (view && candidates.has(view)) return view
    const alias =
      ts.isVariableDeclaration(declaration) && aliasInputs.get(declaration)
    if (!alias) return undefined
    resolved.set(declaration, undefined)
    const target = referencedView(alias)
    resolved.set(declaration, target)
    return target
  }
  for (const node of identifiers) {
    if (!names.has(node.text)) continue
    const view = referencedView(node)
    if (!view) continue
    let parent = node.parent
    while (ts.isParenthesizedExpression(parent)) parent = parent.parent
    if (
      (ts.isJsxOpeningElement(parent) || ts.isJsxSelfClosingElement(parent)) &&
      parent.tagName === node
    ) {
      called.add(view)
    } else if (
      !(ts.isJsxClosingElement(parent) && parent.tagName === node) &&
      !(
        (ts.isVariableDeclaration(parent) ||
          ts.isFunctionDeclaration(parent)) &&
        parent.name === node
      ) &&
      !(
        ts.isVariableDeclaration(parent) &&
        aliasInputs.get(parent) === node &&
        (!ts.isVariableStatement(parent.parent.parent) ||
          !hasModifier(parent.parent.parent, ts.SyntaxKind.ExportKeyword))
      )
    ) {
      escaped.add(view)
    }
  }
  for (const [declaration, view] of byDeclaration) {
    if (!called.has(view) || escaped.has(view)) continue
    const statement = ts.isVariableDeclaration(declaration)
      ? declaration.parent.parent
      : declaration
    view.mountedOnly = !(
      ts.canHaveModifiers(statement) &&
      hasModifier(statement, ts.SyntaxKind.ExportKeyword)
    )
  }
  return { declarations, roots }
}

function hasModifier(node: ts.HasModifiers, kind: ts.SyntaxKind): boolean {
  return Boolean(
    ts.getModifiers(node)?.some((modifier) => modifier.kind === kind),
  )
}

function typescriptModule(
  path: string,
  source: string,
  resolveImport: ResolveImport,
  sources: ReadonlyMap<string, string>,
): SourceModule {
  const file = ts.createSourceFile(path, source, ts.ScriptTarget.Latest, true)
  const bindings = imports(file, resolveImport)
  const exports = new Map<string, () => Binding | undefined>()
  const barrels: string[] = []
  const aliases = new Map<string, string>()
  const contexts = new Set<string>()
  const resolve: ResolveTag = (name) => {
    const local = aliases.get(name) ?? name
    const target = views.get(local)
    if (target) return { target }
    if (contexts.has(local)) return { reactContext: true }
    const [object, member] = local.split('.')
    if (member === 'Provider' && contexts.has(object))
      return { component: REACT_PROVIDER }
    return importedBinding(local, bindings, resolveImport)
  }
  const jsx = path.endsWith('.tsx') ? jsxViews(file, resolve) : undefined
  const views =
    jsx?.declarations ??
    angularViews(file, resolve, sources, (name) =>
      importedBinding(name, bindings, resolveImport),
    )

  for (const statement of file.statements) {
    if (ts.isExportDeclaration(statement) && !statement.isTypeOnly) {
      const specifier = statement.moduleSpecifier
      const from =
        specifier && ts.isStringLiteral(specifier) ? specifier.text : undefined
      if (statement.exportClause && ts.isNamedExports(statement.exportClause)) {
        for (const element of statement.exportClause.elements) {
          if (element.isTypeOnly) continue
          const name = (element.propertyName ?? element.name).text
          exports.set(element.name.text, () =>
            from ? resolveImport(from, name) : resolve(name),
          )
        }
      } else if (from && !statement.exportClause) {
        barrels.push(from)
      }
    } else if (ts.isExportAssignment(statement)) {
      const name = localAlias(statement.expression) ?? 'default'
      exports.set('default', () => resolve(name))
    } else if (ts.isVariableStatement(statement)) {
      for (const { name, initializer } of statement.declarationList
        .declarations) {
        if (!ts.isIdentifier(name)) continue
        if (
          initializer &&
          statement.declarationList.flags & ts.NodeFlags.Const
        ) {
          const alias = localAlias(initializer)
          if (alias) aliases.set(name.text, alias)
          if (ts.isCallExpression(initializer)) {
            const factory = localAlias(initializer.expression)
            const symbol =
              factory && importedSymbol(factory, { scripts: [file] })
            if (
              symbol &&
              symbol.module === 'react' &&
              symbol.name === 'createContext'
            )
              contexts.add(name.text)
          }
        }
        if (hasModifier(statement, ts.SyntaxKind.ExportKeyword)) {
          exports.set(name.text, () => resolve(name.text))
        }
      }
    } else if (
      (ts.isClassDeclaration(statement) ||
        ts.isFunctionDeclaration(statement)) &&
      hasModifier(statement, ts.SyntaxKind.ExportKeyword)
    ) {
      const name = statement.name?.text ?? 'default'
      const exported = hasModifier(statement, ts.SyntaxKind.DefaultKeyword)
        ? 'default'
        : name
      exports.set(exported, () => resolve(name))
    }
  }
  return {
    views: [...views.values(), ...(jsx?.roots ?? [])],
    exported: (name) => {
      const binding = exports.get(name)
      if (binding) return binding()
      if (name !== 'default') {
        for (const barrel of barrels) {
          const resolved = resolveImport(barrel, name)
          if (resolved) return resolved
        }
      }
      return undefined
    },
  }
}

interface StyleFact {
  name: string
  value: string
  guards?: Condition[]
  unresolved?: true
  overrides?: ReadonlySet<string>
  spreadKeys?: ReadonlySet<string>
  // Alternatives from one object property share the same Vue assignment.
  styleWrite?: { key: string }
}

function indexedSpreadKeys(
  spread: ts.Expression,
  state: EmptyState,
): ReadonlySet<string> | undefined {
  spread = transparent(spread)
  if (!ts.isElementAccessExpression(spread)) return undefined
  const receiver = transparent(spread.expression)
  let array = receiver
  if (ts.isIdentifier(receiver)) {
    const binding = stateBinding(receiver, state.view)
    const initial = immutableInitializer(binding)
    if (
      !initial ||
      !binding ||
      !ts.isVariableDeclaration(binding) ||
      !ts.isIdentifier(binding.name)
    )
      return undefined
    const name = binding.name.text
    const otherUse = (node: ts.Node): boolean => {
      if (
        ts.isIdentifier(node) &&
        node.text === name &&
        node !== binding.name &&
        jsxDeclaration(name, node) === binding
      )
        return !(
          ts.isElementAccessExpression(node.parent) &&
          node.parent.expression === node &&
          ts.isSpreadAssignment(node.parent.parent)
        )
      return Boolean(ts.forEachChild(node, otherUse))
    }
    if (otherUse(binding.getSourceFile())) return undefined
    array = transparent(initial)
  }
  if (!ts.isArrayLiteralExpression(array)) return undefined
  const keys = new Set<string>()
  for (const variant of array.elements) {
    let unknown = false
    const entries = objectEntries(variant, undefined, () => {
      unknown = true
    })
    if (!entries || unknown) return undefined
    for (const key of entries.keys()) keys.add(key)
  }
  return keys
}

function suppliedStyleFacts(
  name: string,
  input: MountedValue,
  guards: Condition[],
  composeStyle: boolean,
): StyleFact[] {
  return styleFacts(
    name,
    input.value,
    input.state,
    guards,
    composeStyle,
  ).flatMap((fact) =>
    applyGuards(
      guards,
      fact.guards?.slice(guards.length) ?? [],
      input.state,
    ).map((guards) => ({ ...fact, guards })),
  )
}

function vueStyleText(value: string, guards?: Condition[]): StyleFact[] {
  const entries = new Map<string, string>()
  // Vue parses strings inside a style array as object entries before merging.
  const declarations = value
    .replace(
      /"(?:[^"\\]|\\[^])*"|'(?:[^'\\]|\\[^])*'|\\[^]|\/\*[^]*?\*\//g,
      (match) => (match.startsWith('/*') ? '' : match),
    )
    .split(/;(?![^(]*\))/g)
  for (const declaration of declarations) {
    const [key, css] = declaration.split(/:([^]+)/)
    if (css !== undefined) entries.set(key!.trim(), css.trim())
  }
  return [...entries].map(([key, value]) => ({
    name: 'style.' + kebabCase(key),
    value,
    guards,
    styleWrite: { key },
  }))
}

/** Resolves the last assignment to each normalized Vue style key on each path. */
function mergedVueStyle(
  facts: StyleFact[],
  guards: Condition[],
  state: EmptyState,
): StyleFact[] {
  let winners: StyleFact[] = []
  for (const fact of facts) {
    for (const scope of applyGuards(
      [],
      fact.guards?.slice(guards.length) ?? [],
      state,
    )) {
      winners = winners.flatMap((earlier) => {
        const write = fact.styleWrite
        const prior = earlier.styleWrite
        const overwrites =
          prior &&
          (write
            ? write !== prior && write.key === prior.key
            : mayWriteStyle(fact, kebabCase(prior.key)))
        const override =
          !prior && write && mayWriteStyle(earlier, kebabCase(write.key))
        if (!overwrites && !override) return [earlier]
        const starting = earlier.guards ?? []
        const overlap = applyGuards(starting, scope, state)
        if (!overlap.length) return [earlier]
        const remaining = withoutGuardOptions(starting, overlap).map(
          (guards) => ({ ...earlier, guards }),
        )
        if (override) {
          const overrides = new Set(earlier.overrides).add(kebabCase(write.key))
          for (const guards of overlap)
            remaining.push({ ...earlier, guards, overrides })
        }
        return remaining
      })
      winners.push({ ...fact, guards: scope })
    }
  }
  return winners.map((fact) => ({
    ...fact,
    guards: [...guards, ...(fact.guards ?? [])],
  }))
}

/** Reads the literal alternatives of a class or style binding. */
function styleFacts(
  name: string,
  value: ts.Expression,
  state: EmptyState,
  guards?: Condition[],
  composeStyle = false,
): StyleFact[] {
  value = transparent(value)
  const resolve = (input: ts.Expression): StaticValue | undefined =>
    mountedScalar(input, state)
  const literal = scalarLiteral(value)
  const known = literal === undefined ? resolve(value) : literal
  if (name.startsWith('class.')) {
    const truthy =
      literalTruth(value) ?? (known === undefined ? undefined : Boolean(known))
    if (truthy !== undefined)
      return truthy ? [{ name, value: 'true', guards }] : []
  }
  if (composeStyle && name === 'style' && typeof known === 'string')
    return vueStyleText(known, guards)
  if (typeof known === 'string' || typeof known === 'number')
    return [{ name, value: String(known), guards }]
  const writesProperty = composeStyle && name.startsWith('style.')
  if (
    known !== undefined ||
    (writesProperty && canonicalLiteral(value) === 'undefined')
  )
    return writesProperty ? [{ name, value: '', guards }] : []

  if (
    (state.inputs && jsxInputReference(value, state.view)) ||
    (state.view.jsxResolve && ts.isIdentifier(value) && jsxChoiceAlias(value))
  )
    return jsxValuePaths(value, state, guards ?? []).flatMap(
      ({ input, guards, unresolved }): StyleFact[] => {
        if (
          unresolved ||
          !input ||
          (input.value === value && input.state === state)
        )
          return [{ name, value: '', guards, unresolved: true }]
        return suppliedStyleFacts(name, input, guards, composeStyle)
      },
    )
  const supplied = mountedValue(value, state)
  if (supplied && supplied.state !== state)
    return suppliedStyleFacts(name, supplied, guards ?? [], composeStyle)
  if (
    name === 'class' &&
    ts.isBinaryExpression(value) &&
    value.operatorToken.kind === ts.SyntaxKind.PlusToken
  ) {
    return concatenatedClassFacts(value, state, guards, resolve)
  }
  if (ts.isConditionalExpression(value)) {
    const test = condition(value.condition)
    return [
      ...styleFacts(
        name,
        value.whenTrue,
        state,
        [...(guards ?? []), test],
        composeStyle,
      ),
      ...styleFacts(
        name,
        value.whenFalse,
        state,
        [...(guards ?? []), opposite(test)],
        composeStyle,
      ),
    ]
  }
  if (
    ts.isBinaryExpression(value) &&
    value.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
  ) {
    const test = condition(value.left)
    const selected = styleFacts(
      name,
      value.right,
      state,
      [...(guards ?? []), test],
      composeStyle,
    )
    if (writesProperty)
      selected.push({
        name,
        value: '',
        guards: [...(guards ?? []), opposite(test)],
      })
    return selected
  }
  if (name === 'class' && ts.isTemplateExpression(value)) {
    return templateClassFacts(value, state, guards)
  }
  if (ts.isArrayLiteralExpression(value)) {
    const merge =
      !composeStyle && name === 'style' && state.view.path.endsWith('.vue')
    const facts = value.elements.flatMap((entry) =>
      styleFacts(name, entry, state, guards, composeStyle || merge),
    )
    return merge ? mergedVueStyle(facts, guards ?? [], state) : facts
  }
  let unknownEntries = false
  let bounded = name === 'style'
  let spreadKeys: Set<string> | undefined
  const entries = objectEntries(value, undefined, (spread) => {
    unknownEntries = true
    const keys = name === 'style' && spread && indexedSpreadKeys(spread, state)
    if (!keys) bounded = false
    else if (bounded) {
      spreadKeys ??= new Set()
      for (const key of keys) spreadKeys.add(kebabCase(key))
    }
    return keys || undefined
  })
  if (entries) {
    const facts = [...entries].flatMap(([propertyName, input]) => {
      const property =
        name === 'class'
          ? 'class.' + propertyName
          : 'style.' + kebabCase(propertyName)
      const facts = input
        ? styleFacts(property, input, state, guards, composeStyle)
        : []
      if (!composeStyle) return facts
      const styleWrite = { key: propertyName }
      if (!facts.length)
        return [{ name: property, value: '', guards, styleWrite }]
      return facts.map((fact) => ({ ...fact, styleWrite }))
    })
    if (!unknownEntries) return facts
    return [
      ...facts,
      {
        name,
        value: '',
        guards,
        unresolved: true,
        overrides: new Set([...entries.keys()].map(kebabCase)),
        spreadKeys: bounded ? spreadKeys : undefined,
      },
    ]
  }
  if (name.startsWith('class.'))
    return [
      { name, value: 'true', guards: [...(guards ?? []), condition(value)] },
    ]
  return /^(?:class|style(?:\.(?:background(?:-color)?|border.*|display|height|min-height|opacity|position|visibility))?)$/.test(
    name,
  ) && canonicalLiteral(value) !== 'undefined'
    ? [{ name, value: '', guards, unresolved: true }]
    : []
}

function concatenatedClassFacts(
  value: ts.BinaryExpression,
  state: EmptyState,
  guards: Condition[] | undefined,
  resolve: (value: ts.Expression) => StaticValue | undefined,
): StyleFact[] {
  const left = resolve(value.left)
  const right = resolve(value.right)
  if (typeof left === 'string' && typeof right === 'string')
    return [{ name: 'class', value: left + right, guards }]
  if (typeof left === 'string') {
    return [
      { name: 'class', value: left.replace(/\S+$/, ''), guards },
      ...styleFacts('class', value.right, state, guards).map((fact) => ({
        ...fact,
        value: left + fact.value,
      })),
    ]
  }
  if (typeof right === 'string') {
    return [
      { name: 'class', value: right.replace(/^\S+/, ''), guards },
      ...styleFacts('class', value.left, state, guards).map((fact) => ({
        ...fact,
        value: fact.value + right,
      })),
    ]
  }
  return [{ name: 'class', value: '', guards, unresolved: true }]
}

function templateClassFacts(
  literal: ts.TemplateExpression,
  state: EmptyState,
  guards?: Condition[],
): StyleFact[] {
  const name = 'class'
  const facts: StyleFact[] = [
    { name, value: literal.head.text.replace(/\S+$/, ''), guards },
  ]
  let startsToken = !literal.head.text || /\s$/.test(literal.head.text)
  for (const span of literal.templateSpans) {
    const text = span.literal.text
    const isTail = ts.isTemplateTail(span.literal)
    const endsToken = /^\s/.test(text) || (isTail && !text)
    for (const fact of styleFacts(name, span.expression, state, guards)) {
      let value = fact.value
      if (!startsToken) value = value.replace(/^\S+/, '')
      if (!endsToken) value = value.replace(/\S+$/, '')
      facts.push({ ...fact, value })
    }
    const complete = text.replace(/^\S+/, '')
    facts.push({
      name,
      value: isTail ? complete : complete.replace(/\S+$/, ''),
      guards,
    })
    startsToken = /\s$/.test(text)
  }
  return facts
}

const styleCache = new WeakMap<
  Element,
  WeakMap<View | EmptyState, StyleFact[] | false>
>()

function elementStyles(element: Element, state: EmptyState): StyleFact[] {
  let byScope = styleCache.get(element)
  if (!byScope) {
    byScope = new WeakMap()
    styleCache.set(element, byScope)
  }
  let declared = byScope.get(state.view)
  if (declared === undefined) {
    const facts = readElementStyles(element, {
      view: state.view,
      mountView: state.mountView,
    })
    // A resolved guard can name a particular mount, even when its value is literal.
    declared = facts.every((fact) => !fact.unresolved && !fact.guards?.length)
      ? facts
      : false
    byScope.set(state.view, declared)
  }
  if (declared) return declared
  const known = byScope.get(state)
  if (known) return known
  const facts = readElementStyles(element, state)
  byScope.set(state, facts)
  return facts
}

function readElementStyles(element: Element, state: EmptyState): StyleFact[] {
  return element.attributes.flatMap((attr) => {
    const name = ['className', 'ngClass'].includes(attr.name)
      ? 'class'
      : attr.name === 'ngStyle'
        ? 'style'
        : attr.name
    if (!/^(?:class|style)(?:\.|$)/.test(name)) return []
    if (attr.expression) return styleFacts(name, attr.expression, state)
    return attr.literal === undefined ? [] : [{ name, value: attr.literal }]
  })
}

function classTokens(fact: StyleFact): string[] {
  if (fact.name === 'class') return fact.value.split(/\s+/)
  if (fact.name.startsWith('class.') && fact.value === 'true')
    return fact.name.slice(6).split(/\s+/)
  return []
}

function cssDeclarations(fact: StyleFact): Array<[string, string]> {
  if (!/^style(?:\.|$)/.test(fact.name)) return []
  const declarations =
    fact.name === 'style'
      ? fact.value.split(';').map((declaration) => declaration.split(':', 2))
      : [[fact.name.slice(6), fact.value]]
  const winners = new Map<string, { value: string; important: boolean }>()
  for (const [rawName, rawValue] of declarations) {
    const name = rawName.trim().toLowerCase()
    const value = rawValue?.trim().toLowerCase()
    if (!name || !value) continue
    const important = /\s*!important$/.test(value)
    if (important || !winners.get(name)?.important)
      winners.set(name, {
        value: value.replace(/\s*!important$/, ''),
        important,
      })
  }
  return [...winners].map(([name, { value }]) => [name, value])
}

interface RoomStyles {
  hidden: Condition[][]
  heights: Condition[][]
  marked: Condition[][]
  inFlow: (paths: Condition[][], known: boolean) => boolean
  uncertainTwin: Condition[][]
  buttons: StyleFact[]
}

function roomStyles(
  { element, conditions }: PlacedElement,
  ancestors: PlacedElement[],
  state: EmptyState,
): RoomStyles {
  const scopes = applyGuards([], conditions, state)
  const facts = elementStyles(element, state).filter((fact) =>
    scopes.some((scope) => applyGuards(scope, fact.guards ?? [], state).length),
  )
  const hiding = facts.filter(
    (fact) =>
      appearanceEffects(fact).some(
        (effect) => effect === 'hidden' || effect === 'concealed',
      ) && !classTokens(fact).includes('visually-hidden'),
  )
  const sizing = facts.filter(
    (fact) =>
      classTokens(fact).some((token) =>
        /^(?:min-|m)?h-(?!0$|auto$)\d/.test(token),
      ) ||
      cssDeclarations(fact).some(
        ([name, css]) =>
          ['height', 'min-height'].includes(name) &&
          /^(?:[.\d]+(?:[a-z%]+)?|(?:var|calc|clamp|min|max)\(.+\))$/.test(
            css,
          ) &&
          !/^0(?:[a-z%]+)?$/.test(css),
      ),
  )
  const chain = [...ancestors, { element, conditions }]
  const layout = (
    scope: Condition[],
    requireFlow: boolean,
    known: boolean,
  ): Condition[][] =>
    chain.reduce(
      (paths, { element: node }) =>
        paths.flatMap((path) =>
          appearances(node, path, state)
            .filter(
              (look) =>
                !look.removed &&
                (!requireFlow || !look.outOfFlow) &&
                (!known ||
                  !uncertainLayout(
                    uncertainAppearance(node, look.conditions, state),
                  )),
            )
            .map((look) => look.conditions),
        ),
      [scope],
    )
  const factPaths = (matching: StyleFact[]): Condition[][] =>
    matching.flatMap((fact) =>
      scopes.flatMap((scope) => applyGuards(scope, fact.guards ?? [], state)),
    )
  const presentPaths = (paths: Condition[][]): Condition[][] =>
    distinctPaths(paths.flatMap((scope) => layout(scope, false, false)))
  const hidden = presentPaths(factPaths(hiding))
  const heights = presentPaths(factPaths(sizing))
  const id = stableId(element)
  const marked =
    roomId(id) !== id
      ? presentPaths(scopes)
      : conditions.length
        ? presentPaths(
            factPaths(
              facts.filter((fact) => classTokens(fact).includes(NEUTRAL_FILL)),
            ),
          )
        : []
  const uncertainTwin = marked.length
    ? distinctPaths(
        (element.unknownInputs
          ? marked
          : facts
              .filter(
                (fact) =>
                  fact.unresolved &&
                  uncertainStyleEffects(fact).some(
                    (effect) => effect === 'hidden' || effect === 'concealed',
                  ),
              )
              .flatMap((fact) =>
                marked.flatMap((scope) =>
                  applyGuards(scope, fact.guards ?? [], state),
                ),
              )
        ).flatMap((scope) => layout(scope, true, false)),
      )
    : []
  return {
    hidden,
    heights,
    marked,
    inFlow: (paths, known) =>
      paths.some((scope) => layout(scope, true, known).length > 0),
    uncertainTwin,
    buttons: facts.filter(
      (fact) => !fact.unresolved && classTokens(fact).includes('btn'),
    ),
  }
}

interface PlacedElement {
  element: Element
  conditions: Condition[]
  state?: EmptyState
  domPrefix?: Condition[]
}

type ErrorCondition = (
  value: ts.Expression,
  truthy: boolean,
  fixedRefusal?: boolean,
  scope?: Condition[],
) => boolean

function stateName(value: ts.Expression): string | undefined {
  if (ts.isIdentifier(value)) return value.text
  const member = access(value)
  if (!member) return undefined
  if (member[0].kind === ts.SyntaxKind.ThisKeyword) return member[1]
  return member[1] === 'value' ? stateName(member[0]) : undefined
}

function templateLocal(
  value: ts.Expression,
): { value?: ts.Expression } | undefined {
  if (!ts.isIdentifier(value)) return undefined
  const scope = templateScopes.get(value.getSourceFile())
  return scope?.has(value.text) ? { value: scope.get(value.text) } : undefined
}

function stateBinding(
  value: ts.Expression,
  view: View,
): ts.Declaration | undefined {
  if (templateLocal(value)) return undefined
  const name = stateName(value)
  if (!name) return undefined
  let reference = value
  for (let member = access(reference); member; member = access(reference)) {
    if (member[0].kind === ts.SyntaxKind.ThisKeyword)
      return view.binding?.(name)
    if (member[1] !== 'value') break
    reference = member[0]
  }
  if (templateLocal(reference)) return undefined
  return jsxDeclaration(name, value) ?? view.binding?.(name)
}

function literalTruth(value: ts.Expression): boolean | undefined {
  value = transparent(value)
  const literal = canonicalLiteral(value)
  if (literal) return literal === 'true'
  if (ts.isStringLiteralLike(value)) return Boolean(value.text)
  if (ts.isNumericLiteral(value)) return Boolean(Number(value.text))
  return undefined
}

type StaticValue = string | number | boolean | null

function scalarLiteral(value: ts.Expression): StaticValue | undefined {
  if (ts.isStringLiteralLike(value)) return value.text
  if (ts.isNumericLiteral(value)) return Number(value.text)
  if (value.kind === ts.SyntaxKind.TrueKeyword) return true
  if (value.kind === ts.SyntaxKind.FalseKeyword) return false
  if (value.kind === ts.SyntaxKind.NullKeyword) return null
  return undefined
}

function staticValue(
  value: ts.Expression,
  view: View,
  seen = new Set<ts.Expression>(),
  state?: EmptyState,
): StaticValue | undefined {
  value = transparent(value)
  if (seen.has(value) || seen.size >= 32) return undefined
  seen = new Set(seen).add(value)
  if (
    state?.inputs &&
    !ts.isCallExpression(value) &&
    jsxInputReference(value, view)
  ) {
    const paths = jsxValuePaths(value, state, [])
    const values = paths.map(({ input, unresolved }) =>
      input && !unresolved
        ? staticValue(input.value, input.state.view, seen, input.state)
        : undefined,
    )
    const [first] = values
    return values.every((item) => item === first) ? first : undefined
  }
  const mounted = state && mountedValue(value, state)
  if (mounted)
    return staticValue(mounted.value, mounted.state.view, seen, mounted.state)
  const literal = scalarLiteral(value)
  if (literal !== undefined) return literal
  if (ts.isBinaryExpression(value)) {
    const operator = value.operatorToken.kind
    if (
      operator === ts.SyntaxKind.PlusToken ||
      operator === ts.SyntaxKind.EqualsEqualsEqualsToken ||
      operator === ts.SyntaxKind.ExclamationEqualsEqualsToken
    ) {
      const left = staticValue(value.left, view, seen, state)
      const right = staticValue(value.right, view, seen, state)
      if (left === undefined || right === undefined) return undefined
      if (operator === ts.SyntaxKind.EqualsEqualsEqualsToken)
        return left === right
      if (operator === ts.SyntaxKind.ExclamationEqualsEqualsToken)
        return left !== right
      if (typeof left === 'string' || typeof right === 'string')
        return String(left) + String(right)
      if (typeof left === 'number' && typeof right === 'number')
        return left + right
    }
    return undefined
  }
  if (ts.isTemplateExpression(value)) {
    let text = value.head.text
    for (const span of value.templateSpans) {
      const part = staticValue(span.expression, view, seen, state)
      if (part === undefined) return undefined
      text += String(part) + span.literal.text
    }
    return text
  }
  const initial =
    templateLocal(value)?.value ??
    immutableInitializer(stateBinding(value, view))
  return initial && staticValue(initial, view, seen, state)
}

function initializer(
  binding: ts.Declaration | undefined,
): ts.Expression | undefined {
  if (
    binding &&
    (ts.isVariableDeclaration(binding) || ts.isPropertyDeclaration(binding))
  )
    return binding.initializer
  return undefined
}

function immutableInitializer(
  binding: ts.Declaration | undefined,
): ts.Expression | undefined {
  if (!binding) return undefined
  if (
    ts.isVariableDeclaration(binding) &&
    binding.parent.flags & ts.NodeFlags.Const
  )
    return binding.initializer
  if (
    ts.isPropertyDeclaration(binding) &&
    binding.modifiers?.some(
      ({ kind }) => kind === ts.SyntaxKind.ReadonlyKeyword,
    ) &&
    !ts.getDecorators(binding)?.length &&
    !assignedInClass(binding)
  )
    return binding.initializer
  return undefined
}

function targetContains(
  target: ts.Expression,
  matches: (value: ts.Expression) => boolean,
): boolean {
  target = transparent(target)
  if (matches(target)) return true
  if (
    ts.isBinaryExpression(target) &&
    target.operatorToken.kind === ts.SyntaxKind.EqualsToken
  )
    return targetContains(target.left, matches)
  if (ts.isSpreadElement(target))
    return targetContains(target.expression, matches)
  if (ts.isArrayLiteralExpression(target))
    return target.elements.some((item) => targetContains(item, matches))
  if (ts.isObjectLiteralExpression(target))
    return target.properties.some((item) => {
      if (ts.isPropertyAssignment(item))
        return targetContains(item.initializer, matches)
      if (ts.isShorthandPropertyAssignment(item)) return matches(item.name)
      return (
        ts.isSpreadAssignment(item) && targetContains(item.expression, matches)
      )
    })
  return false
}

function writtenUnder(
  scope: ts.Node,
  matches: (value: ts.Expression) => boolean,
): boolean {
  const assigned = (node: ts.Node): boolean => {
    if (
      (ts.isBinaryExpression(node) &&
        node.operatorToken.kind >= ts.SyntaxKind.FirstAssignment &&
        node.operatorToken.kind <= ts.SyntaxKind.LastAssignment &&
        targetContains(node.left, matches)) ||
      (ts.isForOfStatement(node) &&
        !ts.isVariableDeclarationList(node.initializer) &&
        targetContains(node.initializer, matches))
    )
      return true
    if (
      (ts.isPostfixUnaryExpression(node) || ts.isPrefixUnaryExpression(node)) &&
      (node.operator === ts.SyntaxKind.PlusPlusToken ||
        node.operator === ts.SyntaxKind.MinusMinusToken) &&
      matches(transparent(node.operand))
    )
      return true
    if (
      ts.isCallExpression(node) &&
      ts.isPropertyAccessExpression(node.expression) &&
      ts.isIdentifier(node.expression.expression) &&
      node.expression.expression.text === 'Object' &&
      !jsxDeclaration('Object', node) &&
      node.expression.name.text === 'assign'
    ) {
      const [target, ...sources] = node.arguments
      if (
        target &&
        (matches(target) ||
          (target.kind === ts.SyntaxKind.ThisKeyword &&
            sources.some((source) => {
              let unknown = false
              const entries = objectEntries(source, undefined, () => {
                unknown = true
              })
              return (
                unknown ||
                !entries ||
                [...entries.keys()].some((name) =>
                  matches(
                    ts.factory.createPropertyAccessExpression(target, name),
                  ),
                )
              )
            })))
      )
        return true
    }
    return Boolean(ts.forEachChild(node, assigned))
  }
  return assigned(scope)
}

const propertyWriteChecks = new WeakMap<ts.PropertyDeclaration, boolean>()

function assignedInClass(binding: ts.PropertyDeclaration): boolean {
  const cached = propertyWriteChecks.get(binding)
  if (cached !== undefined) return cached
  const name = key(binding.name)
  const written = writtenUnder(binding.parent, (target) => {
    const member = access(target)
    return member?.[0].kind === ts.SyntaxKind.ThisKeyword && member[1] === name
  })
  propertyWriteChecks.set(binding, written)
  return written
}

const writeChecks = new WeakMap<ts.Declaration, Map<string, boolean>>()

function variableWritten(
  binding: ts.VariableDeclaration | ts.ParameterDeclaration,
  name?: string,
  member?: string,
): boolean {
  const scope = ts.isParameter(binding)
    ? binding.parent
    : binding.parent.parent.parent
  const checks = writeChecks.get(binding) ?? new Map<string, boolean>()
  const key = JSON.stringify([name, member])
  const cached = checks.get(key)
  if (cached !== undefined) return cached
  const written = writtenUnder(scope, (target) => {
    const reference = access(target)
    if (member !== undefined && reference && reference[1] !== member)
      return false
    if (member === undefined && reference) return false
    const value = reference?.[0] ?? target
    return (
      ts.isIdentifier(value) &&
      (name === undefined || value.text === name) &&
      jsxDeclaration(value.text, value) === binding
    )
  })
  checks.set(key, written)
  writeChecks.set(binding, checks)
  return written
}

function callable(
  binding: ts.Declaration | undefined,
): ts.ConciseBody | undefined {
  if (!binding) return undefined
  if (
    ts.isFunctionDeclaration(binding) ||
    ts.isMethodDeclaration(binding) ||
    ts.isGetAccessorDeclaration(binding)
  )
    return binding.body
  const value = initializer(binding)
  if (value && (ts.isArrowFunction(value) || ts.isFunctionExpression(value)))
    return value.body
  return undefined
}

function memberName(value: ts.Expression): string | undefined {
  if (ts.isPropertyAccessExpression(value)) return value.name.text
  return stateName(value)
}

function vueProps(
  files: ts.SourceFile[],
): Map<ts.VariableDeclaration | undefined, ts.TypeNode> {
  const props = new Map<ts.VariableDeclaration | undefined, ts.TypeNode>()
  const read = (
    node: ts.Expression | undefined,
    declaration?: ts.VariableDeclaration,
  ): void => {
    if (!node || !ts.isCallExpression(node)) return
    if (stateName(node.expression) === 'withDefaults')
      return read(node.arguments[0], declaration)
    const type = node.typeArguments?.[0]
    if (stateName(node.expression) === 'defineProps' && type)
      props.set(declaration, type)
  }
  for (const file of files) {
    for (const statement of file.statements) {
      if (ts.isExpressionStatement(statement)) read(statement.expression)
      if (ts.isVariableStatement(statement)) {
        for (const declaration of statement.declarationList.declarations)
          read(declaration.initializer, declaration)
      }
    }
  }
  return props
}

interface InputReference {
  name: string
  fallback?: ts.Expression
}

function inputReference<T>(
  value: ts.Expression,
  view: View,
  root: (binding: ts.Declaration | undefined) => T | undefined,
  seen = new Set<ts.Declaration>(),
): (InputReference & { owner: T }) | undefined {
  value = transparent(value)
  const member = access(value)
  const binding = stateBinding(member?.[0] ?? value, view)
  if (!binding || seen.has(binding) || seen.size >= 32) return undefined
  seen = new Set(seen)
  const object = (input: ts.Expression, name: string): T | undefined => {
    input = transparent(input)
    if (!ts.isIdentifier(input)) return undefined
    const declaration = stateBinding(input, view)
    if (
      !declaration ||
      (!ts.isVariableDeclaration(declaration) &&
        !ts.isParameter(declaration)) ||
      !ts.isIdentifier(declaration.name) ||
      variableWritten(declaration, input.text, name)
    )
      return undefined
    const owner = root(declaration)
    if (owner !== undefined) return owner
    if (seen.has(declaration) || seen.size >= 32) return undefined
    seen.add(declaration)
    const alias = immutableInitializer(declaration)
    return alias && object(alias, name)
  }
  if (member) {
    const [receiver, name] = member
    const owner = object(receiver, name)
    return owner === undefined ? undefined : { name, owner }
  }
  if (
    !ts.isIdentifier(value) ||
    (!ts.isVariableDeclaration(binding) && !ts.isParameter(binding)) ||
    variableWritten(binding, value.text)
  )
    return undefined
  seen.add(binding)
  if (ts.isObjectBindingPattern(binding.name)) {
    const entry = binding.name.elements.find(
      ({ name }) => ts.isIdentifier(name) && name.text === value.text,
    )
    if (!entry || entry.dotDotDotToken) return undefined
    const name = entry.propertyName ? key(entry.propertyName) : value.text
    if (name === undefined) return undefined
    const owner =
      root(binding) ??
      (binding.initializer && object(binding.initializer, name))
    return owner === undefined
      ? undefined
      : { name, owner, fallback: entry.initializer }
  }
  const alias = immutableInitializer(binding)
  return alias && inputReference(alias, view, root, seen)
}

function jsxInputReference(
  value: ts.Expression,
  view: View,
): InputReference | undefined {
  if (!view.jsxParameter) return undefined
  value = transparent(value)
  if (ts.isCallExpression(value)) value = value.expression
  return inputReference(value, view, (binding) =>
    binding === view.jsxParameter ? true : undefined,
  )
}

function jsxInput(value: ts.Expression, view: View): string | undefined {
  return jsxInputReference(value, view)?.name
}

function vueInput(
  value: ts.Expression,
  view: View,
): { name: string; type: ts.TypeNode } | undefined {
  const props = view.vueProps
  if (!props) return undefined
  const resolved = inputReference(value, view, (binding) =>
    binding && ts.isVariableDeclaration(binding)
      ? props.get(binding)
      : undefined,
  )
  if (resolved) return { name: resolved.name, type: resolved.owner }
  if (
    !ts.isIdentifier(value) ||
    templateLocal(value) ||
    stateBinding(value, view)
  )
    return undefined
  const type = [...props.values()].find((type) => memberType(type, value.text))
  return type ? { name: value.text, type } : undefined
}

function memberType(
  type: ts.TypeNode | undefined,
  name: string,
): ts.TypeNode | undefined {
  if (type && ts.isTypeReferenceNode(type) && ts.isIdentifier(type.typeName)) {
    const local = type.typeName.text
    const declaration = type
      .getSourceFile()
      .statements.find(
        (item) =>
          (ts.isInterfaceDeclaration(item) ||
            ts.isTypeAliasDeclaration(item)) &&
          item.name.text === local,
      )
    if (declaration && ts.isTypeAliasDeclaration(declaration))
      type = declaration.type
    if (declaration && ts.isInterfaceDeclaration(declaration))
      return propertyType(declaration.members)
  }
  return type && ts.isTypeLiteralNode(type)
    ? propertyType(type.members)
    : undefined

  function propertyType(
    members: ts.NodeArray<ts.TypeElement>,
  ): ts.TypeNode | undefined {
    return members.find(
      (member): member is ts.PropertySignature =>
        ts.isPropertySignature(member) && key(member.name) === name,
    )?.type
  }
}

interface ImportedSymbol {
  name: string
  module: string
  path: string
}

function importedSymbol(
  name: string,
  view: Pick<View, 'scripts'>,
): ImportedSymbol | undefined {
  const [local, member, ...rest] = name.split('.')
  if (rest.length) return undefined
  for (const file of view.scripts) {
    for (const statement of file.statements) {
      if (
        !ts.isImportDeclaration(statement) ||
        !ts.isStringLiteral(statement.moduleSpecifier)
      )
        continue
      const bindings = statement.importClause?.namedBindings
      const module = statement.moduleSpecifier.text
      const path = module.startsWith('.')
        ? posix.join(posix.dirname(file.fileName), module)
        : module
      if (
        member &&
        bindings &&
        ts.isNamespaceImport(bindings) &&
        bindings.name.text === local
      )
        return { name: member, module, path }
      if (!member && bindings && ts.isNamedImports(bindings)) {
        const entry = bindings.elements.find(({ name }) => name.text === local)
        if (entry)
          return { name: (entry.propertyName ?? entry.name).text, module, path }
      }
    }
  }
  return undefined
}

function expressionImport(
  value: ts.Expression,
  view: View,
  seen = new Set<ts.Declaration>(),
  member?: string,
): ImportedSymbol | undefined {
  value = transparent(value)
  const local = localAlias(value)
  if (!local || (member && !ts.isIdentifier(value))) return undefined
  const root = ts.isPropertyAccessExpression(value) ? value.expression : value
  if (templateLocal(root)) return undefined
  const binding = stateBinding(root, view)
  if (!binding)
    return importedSymbol(member ? `${local}.${member}` : local, view)
  if (seen.has(binding) || seen.size >= 32) return undefined
  const alias = immutableInitializer(binding)
  return (
    alias &&
    expressionImport(
      alias,
      view,
      new Set(seen).add(binding),
      ts.isPropertyAccessExpression(value) ? value.name.text : member,
    )
  )
}

function trackedAction(
  symbol: ImportedSymbol | undefined,
  names: string[],
): boolean {
  return Boolean(
    symbol &&
    names.includes(symbol.name) &&
    (/^@hilos\/(?:react|vue|angular)(?:\/|$)/.test(symbol.module) ||
      /^framework\/frontend\/(?:react|vue|angular)\/src\/(?:useTrackedAction|hilosTrackedAction)\.js$/.test(
        symbol.path,
      )),
  )
}

type Outcome = 'action' | 'validation'
type InputOutcome = (name: string) => Outcome | undefined

function outcomeOf(
  value: ts.Expression,
  view: View,
  inputOutcome?: InputOutcome,
  seen = new Set<ts.Declaration>(),
): Outcome | undefined {
  value = unparenthesized(value)
  if (ts.isCallExpression(value)) {
    if (
      trackedAction(expressionImport(value.expression, view), [
        'useTrackedAction',
        'createHilosTrackedAction',
      ])
    )
      return 'action'
    if (memberName(value.expression) === 'safeParse') return 'validation'
    if (value.arguments.length) return undefined
    value = value.expression
  }
  const input = jsxInput(value, view)
  const mounted = input && inputOutcome?.(input)
  if (mounted) return mounted
  const binding = stateBinding(value, view)
  const initial = stableInitializer(binding)
  if (binding && initial && !seen.has(binding)) {
    seen.add(binding)
    const outcome = outcomeOf(initial, view, inputOutcome, seen)
    if (outcome) return outcome
  }
  const vue = vueInput(value, view)
  const type = input
    ? memberType(view.jsxParameter?.type, input)
    : vue
      ? memberType(vue.type, vue.name)
      : binding &&
          (ts.isVariableDeclaration(binding) ||
            ts.isPropertyDeclaration(binding))
        ? (binding.type ??
          (initial &&
            ts.isCallExpression(initial) &&
            initial.typeArguments?.[0]))
        : undefined
  if (
    type &&
    ts.isTypeReferenceNode(type) &&
    trackedAction(importedSymbol(type.typeName.getText(), view), [
      'TrackedAction',
      'HilosTrackedAction',
    ])
  )
    return 'action'
  return undefined
}

function stableInitializer(
  binding: ts.Declaration | undefined,
): ts.Expression | undefined {
  if (
    binding &&
    ts.isVariableDeclaration(binding) &&
    ts.isIdentifier(binding.name)
  )
    return variableWritten(binding, binding.name.text)
      ? undefined
      : binding.initializer
  if (binding && ts.isPropertyDeclaration(binding))
    return assignedInClass(binding) ? undefined : binding.initializer
  return initializer(binding)
}

interface ActionWrite {
  value: ts.Expression
  conditions: Condition[]
  unresolvedMount: boolean
}

interface MountedEvent {
  event: ts.Expression
  conditions: Condition[]
  unresolvedMount: boolean
}

/** Reads writes on reachable click and submit paths. */
function actionWrites(
  state: EmptyState,
  events: MountedEvent[],
): Map<ts.Declaration, ActionWrite[]> {
  const writes = new Map<ts.Declaration, ActionWrite[]>()
  const active = new Set<ts.Node>()
  const { view } = state
  let unresolvedMount = false
  const record = (
    target: ts.Expression,
    value: ts.Expression | undefined,
    conditions: Condition[],
  ): void => {
    const binding = stateBinding(target, view)
    if (!binding || !value) return
    const previous = writes.get(binding) ?? []
    previous.push({ value, conditions, unresolvedMount })
    writes.set(binding, previous)
  }
  const visit = (node: ts.Node, scope: Condition[]): Condition[][] => {
    if (active.has(node) || ts.isFunctionLike(node)) return [scope]
    active.add(node)
    const continues = inspect(node, scope)
    active.delete(node)
    return continues
  }
  const byGuard = (
    scope: Condition[],
    value: ts.Expression,
    truthy: boolean,
  ): Condition[][] => applyGuards(scope, [condition(value, truthy)], state)
  const inspect = (node: ts.Node, scope: Condition[]): Condition[][] => {
    if (ts.isReturnStatement(node) || ts.isThrowStatement(node)) {
      if (node.expression) visit(node.expression, scope)
      return []
    }
    if (ts.isBlock(node))
      return node.statements.reduce<Condition[][]>(
        (paths, statement) => paths.flatMap((path) => visit(statement, path)),
        [scope],
      )
    if (ts.isIfStatement(node)) {
      const yes = byGuard(scope, node.expression, true).flatMap((guards) =>
        visit(node.thenStatement, guards),
      )
      const no = byGuard(scope, node.expression, false).flatMap((guards) =>
        node.elseStatement ? visit(node.elseStatement, guards) : [guards],
      )
      return [...yes, ...no]
    }
    if (ts.isConditionalExpression(node)) {
      for (const guards of byGuard(scope, node.condition, true))
        visit(node.whenTrue, guards)
      for (const guards of byGuard(scope, node.condition, false))
        visit(node.whenFalse, guards)
      return [scope]
    }
    if (ts.isBinaryExpression(node)) {
      if (node.operatorToken.kind === ts.SyntaxKind.EqualsToken)
        record(node.left, node.right, scope)
      if (
        [
          ts.SyntaxKind.AmpersandAmpersandToken,
          ts.SyntaxKind.BarBarToken,
        ].includes(node.operatorToken.kind)
      ) {
        for (const guards of byGuard(
          scope,
          node.left,
          node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken,
        ))
          visit(node.right, guards)
        return [scope]
      }
    } else if (ts.isCallExpression(node)) {
      const target = node.expression
      const binding = stateBinding(target, view)
      const body = callable(binding)
      if (body) visit(body, scope)
      else {
        const previous = unresolvedMount
        unresolvedMount = true
        for (const argument of node.arguments) {
          const callback = transparent(argument)
          const possibleBody =
            ts.isArrowFunction(callback) || ts.isFunctionExpression(callback)
              ? callback.body
              : callable(stateBinding(callback, view))
          if (possibleBody) visit(possibleBody, scope)
        }
        unresolvedMount = previous
      }
      if (ts.isPropertyAccessExpression(target) && target.name.text === 'set')
        record(target.expression, node.arguments[0], scope)
      if (
        binding &&
        ts.isVariableDeclaration(binding) &&
        ts.isArrayBindingPattern(binding.name)
      ) {
        const setter = binding.name.elements[1]
        if (
          setter &&
          ts.isBindingElement(setter) &&
          binds(setter.name, stateName(target) ?? '')
        )
          record(target, node.arguments[0], scope)
      }
    }
    ts.forEachChild(node, (child) => {
      visit(child, scope)
    })
    return [scope]
  }
  for (const mounted of events) {
    const { event, conditions } = mounted
    unresolvedMount = mounted.unresolvedMount
    const body =
      ts.isArrowFunction(event) || ts.isFunctionExpression(event)
        ? event.body
        : (callable(stateBinding(event, view)) ?? event)
    visit(body, conditions)
  }
  return writes
}

function returnedValue(
  body: ts.ConciseBody,
  matches: (value: ts.Expression) => boolean,
): boolean {
  if (!ts.isBlock(body)) return matches(body)
  const visit = (node: ts.Node): boolean => {
    if (ts.isReturnStatement(node))
      return Boolean(node.expression && matches(node.expression))
    return !ts.isFunctionLike(node) && Boolean(ts.forEachChild(node, visit))
  }
  return visit(body)
}

function emptyValue(value: ts.Expression): boolean {
  return (
    value.kind === ts.SyntaxKind.NullKeyword ||
    (ts.isIdentifier(value) && value.text === 'undefined') ||
    stringValue(value) === '' ||
    value.kind === ts.SyntaxKind.FalseKeyword
  )
}

function validationFailure(
  value: ts.Expression,
  truthy: boolean,
  view: View,
): boolean {
  value = transparent(value)
  if (ts.isCallExpression(value) && !value.arguments.length)
    value = value.expression
  const member = access(value)
  return Boolean(
    member &&
    outcomeOf(member[0], view) === 'validation' &&
    (truthy
      ? ['error', 'errors', 'failure'].includes(member[1])
      : member[1] === 'success'),
  )
}

function writableState(binding: ts.Declaration, view: View): boolean {
  if (ts.isVariableDeclaration(binding)) {
    if (
      !(binding.parent.flags & ts.NodeFlags.Const) ||
      ts.isArrayBindingPattern(binding.name)
    )
      return true
  } else if (!ts.isPropertyDeclaration(binding)) return false
  else if (!immutableInitializer(binding)) return true
  const input = initializer(binding)
  if (!input || !ts.isCallExpression(input)) return false
  const factory = expressionImport(input.expression, view)
  return Boolean(
    factory &&
    ((factory.module === 'vue' &&
      ['ref', 'shallowRef'].includes(factory.name)) ||
      (factory.module === '@angular/core' && factory.name === 'signal')),
  )
}

type StableLiteral = StaticValue | bigint

interface StableCondition {
  subject: string
  comparison?: { literal: StableLiteral; equal: boolean }
}

const stableConditions = new WeakMap<Condition, StableCondition | undefined>()

function stableSubject(
  value: ts.Expression,
  view: View,
  seen = new Set<ts.Declaration>(),
): ts.VariableDeclaration | ts.PropertyDeclaration | null | undefined {
  value = transparent(value)
  if (
    !ts.isIdentifier(value) &&
    (!ts.isPropertyAccessExpression(value) ||
      value.expression.kind !== ts.SyntaxKind.ThisKeyword)
  )
    return undefined
  const binding = stateBinding(value, view)
  if (!binding || seen.has(binding)) return undefined
  const initial = immutableInitializer(binding)
  if (!initial) return undefined
  const input = transparent(initial)
  const factory = ts.isCallExpression(input)
    ? expressionImport(input.expression, view)
    : undefined
  // An alias of a Vue ref or computed still reads the current unwrapped value.
  if (
    view.vueProps &&
    factory?.module === 'vue' &&
    ['ref', 'shallowRef', 'computed'].includes(factory.name)
  )
    return null
  if (ts.isPropertyDeclaration(binding))
    return factory?.module === '@angular/core' && factory.name === 'signal'
      ? null
      : binding
  if (!ts.isVariableDeclaration(binding)) return undefined
  seen.add(binding)
  const subject = stableSubject(input, view, seen)
  return subject === undefined && ts.isSourceFile(binding.parent.parent.parent)
    ? binding
    : subject
}

function stableLiteral(value: ts.Expression): StableLiteral | undefined {
  value = transparent(value)
  if (ts.isBigIntLiteral(value)) return BigInt(value.text.slice(0, -1))
  if (
    ts.isPrefixUnaryExpression(value) &&
    value.operator === ts.SyntaxKind.MinusToken &&
    ts.isNumericLiteral(value.operand)
  )
    return -Number(value.operand.text)
  return scalarLiteral(value)
}

function stableCondition(guard: Condition): StableCondition | undefined {
  if (stableConditions.has(guard)) return stableConditions.get(guard)
  const { value, view, mount, truthy } = guard
  const read = (): StableCondition | undefined => {
    if (!view || guard.nullish) return undefined
    const key = (
      binding: ts.VariableDeclaration | ts.PropertyDeclaration,
    ): string =>
      JSON.stringify([
        binding.getSourceFile().fileName,
        binding.pos,
        view.jsxResolve ? declarationMount(binding, mount)?.id : mount?.id,
      ])
    const binding = stableSubject(value, view)
    if (binding) return { subject: key(binding) }
    if (!ts.isBinaryExpression(value)) return undefined
    const operator = value.operatorToken.kind
    const equal = operator === ts.SyntaxKind.EqualsEqualsEqualsToken
    if (!equal && operator !== ts.SyntaxKind.ExclamationEqualsEqualsToken)
      return undefined
    for (const [subject, operand] of [
      [value.left, value.right],
      [value.right, value.left],
    ] as const) {
      const literal = stableLiteral(operand)
      if (literal === undefined) continue
      const binding = stableSubject(subject, view)
      if (binding)
        return {
          subject: key(binding),
          comparison: { literal, equal: truthy === equal },
        }
    }
    return undefined
  }
  const result = read()
  stableConditions.set(guard, result)
  return result
}

function stableEventGuard(guard: Condition): boolean {
  return stableCondition(guard) !== undefined
}

/** Traces a message or branch back to error state in its declaring view. */
function errorSource(
  state: EmptyState,
  writes: () => ReturnType<typeof actionWrites>,
  readSource: (
    origin: EmptyState,
    includeUnresolved: boolean,
  ) => ErrorCondition,
  includeUnresolved = false,
): ErrorCondition {
  const { view, inputOutcome } = state
  const active = new Set<ts.Declaration>()
  const matches: ErrorCondition = (
    value,
    truthy,
    fixedRefusal = false,
    scope = [],
  ) => {
    value = transparent(value)
    const follow = (input: ts.Expression, when = truthy): boolean =>
      matches(input, when, fixedRefusal, scope)
    if (state.inputs && jsxInputReference(value, view))
      return jsxValuePaths(value, state, scope).some(
        ({ input, guards, unresolved }) =>
          input &&
          (!unresolved || includeUnresolved) &&
          input.value !== UNDEFINED_INPUT &&
          (input.state !== state || input.value !== value) &&
          readSource(input.state, includeUnresolved)(
            input.value,
            truthy,
            fixedRefusal,
            guards,
          ),
      )
    const mounted = mountedValue(value, state)
    if (mounted && mounted.value !== UNDEFINED_INPUT)
      return readSource(mounted.state, includeUnresolved)(
        mounted.value,
        truthy,
        fixedRefusal,
        scope,
      )
    const choice = jsxChoice(value)
    if (choice)
      return choice.some(([test, arm]) =>
        applyGuards(scope, [test], state).some((path) =>
          matches(arm, truthy, fixedRefusal, path),
        ),
      )
    const local = templateLocal(value)
    if (local) return Boolean(local.value && follow(local.value))
    if (
      ts.isPrefixUnaryExpression(value) &&
      value.operator === ts.SyntaxKind.ExclamationToken
    )
      return follow(value.operand, !truthy)
    if (ts.isBinaryExpression(value)) {
      const operator = value.operatorToken.kind
      if (
        operator === ts.SyntaxKind.AmpersandAmpersandToken ||
        operator === ts.SyntaxKind.BarBarToken
      ) {
        const left = follow(value.left)
        const right = follow(value.right)
        return truthy === (operator === ts.SyntaxKind.AmpersandAmpersandToken)
          ? left || right
          : left && right
      }
      const equal =
        operator === ts.SyntaxKind.EqualsEqualsToken ||
        operator === ts.SyntaxKind.EqualsEqualsEqualsToken
      const unequal =
        operator === ts.SyntaxKind.ExclamationEqualsToken ||
        operator === ts.SyntaxKind.ExclamationEqualsEqualsToken
      if (equal || unequal) {
        if (emptyValue(value.right))
          return follow(value.left, truthy === unequal)
        if (emptyValue(value.left))
          return follow(value.right, truthy === unequal)
      }
      return false
    }
    if (ts.isPropertyAccessExpression(value)) {
      const name = value.name.text
      if (['error', 'errors', 'failure', 'success'].includes(name)) {
        const outcome = outcomeOf(value.expression, view, inputOutcome)
        if (outcome)
          return (
            truthy === (name !== 'success') &&
            (name !== 'success' || outcome === 'validation')
          )
      }
      if (name === 'value' || name === 'message')
        return follow(value.expression)
    }
    if (ts.isCallExpression(value) && !value.arguments.length)
      return follow(value.expression)
    if (
      ts.isCallExpression(value) &&
      stateName(value.expression) === 'Boolean'
    ) {
      const [argument] = value.arguments
      return Boolean(argument && follow(argument))
    }
    const name = stateName(value)
    if (!name) return false
    const binding = stateBinding(value, view)
    if (!binding || active.has(binding)) return false
    active.add(binding)
    const input = stableInitializer(binding)
    const body = callable(binding)
    let found = body
      ? returnedValue(body, (result) => follow(result))
      : Boolean(input && follow(input))
    if (!found && input && ts.isCallExpression(input)) {
      const result = vueComputedResult(input, view)
      const [callback] = input.arguments
      if (result) found = follow(result)
      else if (
        memberName(input.expression) === 'useMemo' &&
        callback &&
        (ts.isArrowFunction(callback) || ts.isFunctionExpression(callback))
      )
        found = returnedValue(callback.body, (result) => follow(result))
    }
    if (!found && truthy && writableState(binding, view)) {
      found = (writes().get(binding) ?? []).some(
        ({ value, conditions, unresolvedMount }) =>
          (includeUnresolved || !unresolvedMount) &&
          (!scope.length ||
            possible([
              ...scope.filter(stableEventGuard),
              ...conditions.filter(stableEventGuard),
            ])) &&
          (follow(value, true) ||
            (literalTruth(value) === true &&
              (fixedRefusal ||
                conditions.some(
                  ({ value, truthy, view: origin, opening }) =>
                    !opening &&
                    validationFailure(value, truthy, origin ?? view),
                )))),
      )
    }
    active.delete(binding)
    return found
  }
  return memoRefusal(matches)
}

function memoRefusal(read: ErrorCondition): ErrorCondition {
  const results = new WeakMap<ts.Expression, Map<string, boolean>>()
  return (value, truthy, fixedRefusal, scope = []) => {
    const key = JSON.stringify([
      truthy,
      Boolean(fixedRefusal),
      guardPathKey(scope),
    ])
    const entries = results.get(value) ?? new Map<string, boolean>()
    const cached = entries.get(key)
    if (cached !== undefined) return cached
    const found = read(value, truthy, fixedRefusal, scope)
    entries.set(key, found)
    results.set(value, entries)
    return found
  }
}

function unresolvedRefusal(
  state: EmptyState,
  matches: ErrorCondition,
  possibleSource: ErrorCondition,
  readUnresolved: (origin: EmptyState) => ErrorCondition,
): ErrorCondition {
  return memoRefusal((value, truthy, fixedRefusal, scope = []) => {
    const source = (input: ts.Expression, when: boolean): boolean =>
      possibleSource(input, when, fixedRefusal, scope)
    if (source(value, truthy) && !matches(value, truthy, fixedRefusal, scope))
      return true

    const visited = new WeakMap<ts.Expression, Set<string>>()
    const containsRefusal = (node: ts.Node, scope: Condition[]): boolean =>
      (ts.isExpression(node) &&
        (possibleSource(node, true, fixedRefusal, scope) ||
          possibleSource(node, false, fixedRefusal, scope))) ||
      Boolean(
        ts.forEachChild(
          node,
          (child) => containsRefusal(child, scope) || undefined,
        ),
      )
    const trace = (input: ts.Expression, scope: Condition[]): boolean => {
      input = transparent(input)
      const paths = visited.get(input) ?? new Set<string>()
      const key = guardPathKey(scope)
      if (paths.has(key)) return false
      paths.add(key)
      visited.set(input, paths)
      const fromMounted = (input: MountedValue, guards: Condition[]): boolean =>
        input.value !== UNDEFINED_INPUT &&
        (input.state === state
          ? trace(input.value, guards)
          : readUnresolved(input.state)(
              input.value,
              truthy,
              fixedRefusal,
              guards,
            ))
      if (state.inputs && jsxInputReference(input, state.view))
        return jsxValuePaths(input, state, scope).some(
          ({ input, guards }) => input && fromMounted(input, guards),
        )
      const choice = jsxChoice(input)
      if (choice)
        return choice.some(([test, arm]) =>
          applyGuards(scope, [test], state).some((guards) =>
            trace(arm, guards),
          ),
        )
      const mounted = mountedValue(input, state)
      if (mounted && mounted.value !== UNDEFINED_INPUT)
        return fromMounted(mounted, scope)
      const alias =
        templateLocal(input)?.value ??
        immutableInitializer(stateBinding(input, state.view))
      if (alias && trace(alias, scope)) return true
      if (ts.isCallExpression(input)) {
        const result = vueComputedResult(input, state.view)
        if (result) return trace(result, scope)
      }
      if (
        (ts.isCallExpression(input) ||
          ts.isElementAccessExpression(input) ||
          (ts.isBinaryExpression(input) &&
            input.operatorToken.kind === ts.SyntaxKind.PlusToken) ||
          ts.isTemplateExpression(input)) &&
        containsRefusal(input, scope)
      )
        return true
      return Boolean(
        ts.forEachChild(
          input,
          (child) =>
            (ts.isExpression(child) && trace(child, scope)) || undefined,
        ),
      )
    }
    return trace(value, scope)
  })
}

function possible(conditions: Condition[]): boolean {
  const values = new Map<string, boolean>()
  const stable = new Map<
    string,
    {
      truthy?: boolean
      equal?: StableLiteral
      excluded?: Set<StableLiteral>
    }
  >()
  for (const guard of conditions) {
    const { expression, truthy } = guard
    const key = conditionKey(guard)
    if (values.has(key) && values.get(key) !== truthy) return false
    if (expression === 'true' || expression === 'false') {
      if (truthy !== (expression === 'true')) return false
    }
    values.set(key, truthy)
    const fact = stableCondition(guard)
    if (!fact) continue
    const facts = stable.get(fact.subject) ?? {}
    const comparison = fact.comparison
    if (!comparison) facts.truthy = truthy
    else if (comparison.equal) {
      if (facts.equal !== undefined && facts.equal !== comparison.literal)
        return false
      facts.equal = comparison.literal
    } else {
      facts.excluded ??= new Set()
      facts.excluded.add(comparison.literal)
    }
    if (
      facts.equal !== undefined &&
      (facts.excluded?.has(facts.equal) ||
        (facts.truthy !== undefined && facts.truthy !== Boolean(facts.equal)))
    )
      return false
    stable.set(fact.subject, facts)
  }
  return true
}

function siblingElements(
  nodes: RenderNode[],
  conditions: Condition[] = [],
): PlacedElement[] {
  return nodes.flatMap((node) => {
    if (node.kind === 'element') return [{ element: node, conditions }]
    if (node.kind === 'branch') {
      const guards = [...conditions, ...node.conditions]
      if (possible(guards)) return siblingElements(node.children, guards)
    }
    return []
  })
}

function sharedPaths(
  nodes: RenderNode[],
  conditions: Condition[],
  state: EmptyState,
): Condition[][] {
  return siblingElements(nodes).flatMap(({ element, conditions: guards }) =>
    applyGuards(conditions, guards, state).flatMap((scope) =>
      presentOptions(element, scope, state).flatMap((present) =>
        element.component && SHARED.has(element.component)
          ? [present]
          : sharedPaths(element.children, present, state),
      ),
    ),
  )
}

function presentOptions(
  element: Element,
  conditions: Condition[],
  state: EmptyState,
): Condition[][] {
  return appearances(element, conditions, state).flatMap((look) =>
    look.removed ? [] : [look.conditions],
  )
}

const INLINE = new Set([
  'a',
  'b',
  'code',
  'em',
  'i',
  'small',
  'span',
  'strong',
  'svg',
  'use',
  'br',
])
const CONTROLS = new Set([
  'button',
  'input',
  'select',
  'textarea',
  'loading-button',
  'LoadingButton',
])
function stableId(element: Element): string {
  return (
    element.attributes.find(
      ({ name, literal }) =>
        ['id', 'data-id', 'dataId', 'data-testid'].includes(name) && literal,
    )?.literal ?? ''
  )
}

function messageText(nodes: RenderNode[]): string {
  return nodes
    .map((node): string => {
      if (node.kind === 'text') return node.value
      if (node.kind === 'branch' || INLINE.has(node.name))
        return messageText(node.children)
      return ''
    })
    .join(' ')
    .replace(/(?:&nbsp;|&#160;)/gi, ' ')
    .trim()
}

function contentPaths(
  nodes: RenderNode[],
  conditions: Condition[],
  state: EmptyState,
): Condition[][] {
  return nodes.flatMap((node) => {
    if (node.kind === 'text') return messageText([node]) ? [conditions] : []
    if (node.kind === 'branch')
      return applyGuards(conditions, node.conditions, state).flatMap((scope) =>
        contentPaths(node.children, scope, state),
      )
    return presentOptions(node, conditions, state).flatMap((scope) =>
      CONTROLS.has(node.name) || node.component
        ? [scope]
        : contentPaths(node.children, scope, state),
    )
  })
}

function role(
  element: Element,
  conditions?: Condition[],
  state?: EmptyState,
): string | undefined {
  const attr = element.attributes.find(({ name }) => name === 'role')
  if (!attr?.expression || !conditions || !state) return attr?.literal
  const values = styleFacts('role', attr.expression, state)
  const selected = values.find(
    ({ value, unresolved, guards }) =>
      value &&
      !unresolved &&
      !withoutGuardOptions(
        conditions,
        applyGuards(conditions, guards ?? [], state),
      ).length,
  )
  return selected?.value
}

function otherMessage(
  element: Element,
  conditions?: Condition[],
  state?: EmptyState,
): boolean {
  return ['status', 'log', 'progressbar'].includes(
    role(element, conditions, state) ?? '',
  )
}

function textBinding(element: Element): ts.Expression | undefined {
  return element.attributes.find(
    ({ name, expression }) =>
      expression && ['v-text', 'textContent'].includes(name),
  )?.expression
}

function reachableText(
  nodes: RenderNode[],
  conditions: Condition[],
  state: EmptyState,
): Text[] {
  return nodes.flatMap((node) => {
    if (node.kind === 'text') return [node]
    const scopes =
      node.kind === 'branch'
        ? applyGuards([], [...conditions, ...node.conditions], state)
        : INLINE.has(node.name)
          ? visibleConditions(node, conditions, state)
          : []
    return scopes.flatMap((scope) => reachableText(node.children, scope, state))
  })
}

function textExpressions(
  { element, conditions }: PlacedElement,
  state: EmptyState,
): ts.Expression[] {
  const binding = textBinding(element)
  if (binding) return [binding]
  return reachableText(element.children, conditions, state).flatMap(
    ({ bindings }) => bindings ?? [],
  )
}

function sameTextSource(
  a: PlacedElement,
  b: PlacedElement,
  state: EmptyState,
): boolean {
  const sources = (placed: PlacedElement): ts.Declaration[] => {
    const origin = placed.state ?? state
    return [...new Set(textExpressions(placed, origin))].flatMap((value) => {
      const binding = stateBinding(value, origin.view)
      return binding && immutableInitializer(binding) ? [binding] : []
    })
  }
  const left = sources(a)
  const right = sources(b)
  return (
    left.length > 0 &&
    left.length === right.length &&
    left.every((binding, index) => binding === right[index])
  )
}

function fixedRefusalValue(
  value: ts.Expression,
  view: View,
  seen = new Set<ts.Declaration>(),
): boolean {
  const symbol = expressionImport(value, view)
  if (
    symbol?.module === '@hilos/core/base' &&
    symbol.name === 'HILOS_SUPPORT_REFUSAL'
  )
    return true
  const binding = stateBinding(value, view)
  const input = immutableInitializer(binding)
  return Boolean(
    binding &&
    input &&
    !seen.has(binding) &&
    fixedRefusalValue(input, view, new Set(seen).add(binding)),
  )
}

/** Support has a fixed refusal published by the core data module. */
function fixedRefusalText(placed: PlacedElement, state: EmptyState): boolean {
  return textExpressions(placed, state).some((value) =>
    fixedRefusalValue(value, state.view),
  )
}

function isRefusalMessage(
  placed: PlacedElement,
  matches: ErrorCondition,
  state: EmptyState,
  visibleText?: Text,
): boolean {
  const { element, conditions } = placed
  if (otherMessage(element, conditions, state)) return false
  const { view } = state
  const binding = textBinding(element)
  const hasText = binding
    ? boundText(element, view) !== ''
    : Boolean(
        messageText(
          visibleText
            ? [visibleText]
            : reachableText(element.children, conditions, state),
        ),
      )
  if (!hasText) return false
  const fixed = visibleText
    ? Boolean(
        visibleText.bindings?.some((value) => fixedRefusalValue(value, view)),
      )
    : fixedRefusalText(placed, state)
  const matchesOn: ErrorCondition = (value, truthy, fixed, scope = []) =>
    (scope.every(resolvedGuard) ? [scope] : applyGuards([], scope, state)).some(
      (path) => matches(value, truthy, fixed, path),
    )
  const fails = (guard: Condition, scope: Condition[]): boolean =>
    localRefusalCondition(guard, state) &&
    matchesOn(guard.value, guard.truthy, fixed, scope)
  if (conditions.some((guard) => fails(guard, conditions))) return true
  if (binding) return matchesOn(binding, true, false, conditions)
  if (visibleText)
    return (
      visibleText.bindings?.some((value) =>
        matchesOn(value, true, false, conditions),
      ) ?? false
    )

  const containsError = (node: RenderNode, scope: Condition[]): boolean => {
    if (node.kind === 'text')
      return (
        node.bindings?.some((value) => matchesOn(value, true, false, scope)) ??
        false
      )
    if (node.kind === 'branch')
      return applyGuards([], [...scope, ...node.conditions], state).some(
        (placed) =>
          (node.conditions.some((guard) => fails(guard, placed)) &&
            Boolean(
              messageText(reachableText(node.children, placed, state)),
            )) ||
          node.children.some((child) => containsError(child, placed)),
      )
    return (
      INLINE.has(node.name) &&
      visibleConditions(node, scope, state).some((visible) =>
        node.children.some((child) => containsError(child, visible)),
      )
    )
  }
  return element.children.some((child) => containsError(child, conditions))
}

function localRefusalCondition(guard: Condition, state: EmptyState): boolean {
  return (
    !guard.opening &&
    (!guard.view || guard.view === state.view) &&
    (!guard.mount || guard.mount === state.mount)
  )
}

function roomId(id: string): string {
  return id.replace(
    /[-_.](?:empty|ghost|idle|placeholder|reserve|room|slot|twin)$/,
    '',
  )
}

function refusalTransition(
  vacancy: Condition[],
  active: Condition[],
  refusal: (guard: Condition) => boolean,
): { mode: Condition[]; switches: boolean } | undefined {
  const changing = new Set(active.filter(refusal).map(conditionKey))
  const activeTerms = new Set(active.map(signedConditionKey))
  const switched = (guard: Condition): boolean =>
    changing.has(conditionKey(guard)) &&
    activeTerms.has(signedConditionKey(opposite(guard)))
  const mode = [
    ...vacancy.filter((guard) => !switched(guard)),
    ...active.filter((guard) => !changing.has(conditionKey(guard))),
  ]
  return possible(mode) ? { mode, switches: vacancy.some(switched) } : undefined
}

interface RefusalOverlay {
  anchor: string
  conditions: Condition[]
  refusal: (guard: Condition) => boolean
}

interface ReservedRoom {
  reserve: PlacedElement
  requiresDelegation: boolean
  active: EvidencePaths
  service: Condition[][]
  vacancy?: Condition[][]
  peers: PlacedElement[]
  overlays?: RefusalOverlay[]
  reserveIn?: (path: Condition[], vacancy: Condition[]) => boolean
  unresolvedSource?: true
  unresolvedLayout?: true
  wrapper?: PlacedElement
}

interface RefusalChecks {
  matches: ErrorCondition
  unresolved: ErrorCondition
}

interface RoomDetail {
  placed: PlacedElement
  origin: EmptyState
  wrapper?: PlacedElement
  styles: RoomStyles
  text: boolean
  ancestors: PlacedElement[]
}

interface RoomEvidence {
  details: RoomDetail[]
  visibleFor: (entry: RoomDetail, source: ErrorCondition) => EvidencePaths
}

interface RoomScope {
  siblings: PlacedElement[]
  parent?: PlacedElement
  state: EmptyState
  domain: Condition[]
  dom: DomVisibility
  hasAlternateDom?: boolean
  candidatesAt?: ReadonlySet<PlacedElement>
  previous?: RoomEvidence
}

function reservedRooms(
  {
    siblings,
    parent,
    state,
    domain,
    dom,
    hasAlternateDom,
    candidatesAt,
    previous,
  }: RoomScope,
  sourceAt: (state: EmptyState) => RefusalChecks,
  onAnalyze: () => void,
): ReservedRoom[] {
  onAnalyze()
  const inheritedHidden = Boolean(dom.hidden)
  const slot = parent ? stableId(parent.element) : ''
  const lexical = (placed: PlacedElement): EmptyState => placed.state ?? state
  const detail = (
    placed: PlacedElement,
    ancestors: PlacedElement[] = [],
  ): RoomDetail => {
    const origin = lexical(placed)
    const { element } = placed
    let styles: RoomStyles | undefined
    return {
      placed,
      origin,
      wrapper: ancestors[0],
      get styles() {
        return (styles ??= roomStyles(placed, ancestors, origin))
      },
      text: Boolean(textBinding(element) || messageText(element.children)),
      ancestors,
    }
  }
  const excluded = (element: Element): boolean =>
    CONTROLS.has(element.name) ||
    role(element) === 'button' ||
    otherMessage(element) ||
    Boolean(element.component && SHARED.has(element.component))
  const eligiblePaths = (
    { placed, origin, styles }: RoomDetail,
    paths: Condition[][],
  ): Condition[][] => {
    const { element } = placed
    if (excluded(element)) return []
    const roles = element.attributes.find(({ name }) => name === 'role')
    const excludedRoles = roles?.expression
      ? styleFacts('role', roles.expression, origin).filter(
          ({ value, unresolved }) =>
            !unresolved &&
            ['button', 'status', 'log', 'progressbar'].includes(value),
        )
      : []
    return paths.flatMap((scope) =>
      withoutGuardOptions(
        scope,
        [...styles.buttons, ...excludedRoles].flatMap((fact) =>
          applyGuards(scope, fact.guards ?? [], origin),
        ),
      ).flatMap((conditions) =>
        withoutGuardOptions(
          conditions,
          sharedPaths(element.children, conditions, origin),
        ),
      ),
    )
  }
  const nestedDetails = (
    parent: PlacedElement,
    ancestors: PlacedElement[],
  ): RoomDetail[] => {
    const { element, conditions } = parent
    const origin = lexical(parent)
    if (
      conditions.length <= (parent.domPrefix ?? domain).length ||
      element.component ||
      element.target ||
      elementContext(element, 'modal') !== 'modal' ||
      interactive(element) ||
      otherMessage(element, conditions, origin)
    )
      return []
    const parents = [...ancestors, parent]
    return siblingElements(element.children, conditions)
      .filter(
        ({ element }) =>
          elementContext(element, 'modal') === 'modal' &&
          element.component !== MODAL,
      )
      .flatMap((child) => {
        const placed = { ...child, state: origin, domPrefix: parent.domPrefix }
        return [detail(placed, parents), ...nestedDetails(placed, parents)]
      })
  }
  const details = siblings.flatMap((placed) => [
    detail(placed),
    ...nestedDetails(placed, []),
  ])
  const evidence = new Map<RoomDetail, Map<ErrorCondition, EvidencePaths>>()
  const visibleFor = (
    entry: RoomDetail,
    source: ErrorCondition,
  ): EvidencePaths => {
    const sources =
      evidence.get(entry) ?? new Map<ErrorCondition, EvidencePaths>()
    const found = sources.get(source)
    if (found) return found
    const paths = visibleRoomPaths(
      entry.placed,
      entry.origin,
      entry.ancestors,
      dom,
      source,
    )
    sources.set(source, paths)
    evidence.set(entry, sources)
    return paths
  }
  const alternateRooms = new Map<string, ReservedRoom[]>()
  const reserveIn = (element: Element, origin: EmptyState) =>
    hasAlternateDom
      ? (path: Condition[], vacancy: Condition[]): boolean => {
          const key = guardPathKey(path)
          let rooms = alternateRooms.get(key)
          if (!rooms) {
            const rebased = siblings.map((placed) =>
              !placed.domPrefix || placed.domPrefix === domain
                ? {
                    ...placed,
                    conditions: [
                      ...path,
                      ...placed.conditions.slice(domain.length),
                    ],
                    domPrefix: path,
                  }
                : placed,
            )
            rooms = reservedRooms(
              {
                siblings: rebased,
                parent,
                state,
                domain: path,
                dom: {},
                previous: { details, visibleFor },
              },
              sourceAt,
              onAnalyze,
            )
            alternateRooms.set(key, rooms)
          }
          return rooms.some(
            (room) =>
              room.reserve.element === element &&
              lexical(room.reserve) === origin &&
              (room.vacancy ?? room.service).some((scope) =>
                possible([...scope, ...vacancy]),
              ),
          )
        }
      : undefined

  const candidatePaths = new Map<
    RoomDetail,
    { hidden: Condition[][]; heights: Condition[][]; marked: Condition[][] }
  >()
  const pathsFor = (entry: RoomDetail) => {
    const cached = candidatePaths.get(entry)
    if (cached) return cached
    const { styles } = entry
    const paths = {
      hidden: eligiblePaths(entry, styles.hidden),
      heights: eligiblePaths(entry, styles.heights).flatMap((scope) =>
        withoutGuardOptions(
          scope,
          contentPaths(entry.placed.element.children, scope, entry.origin),
        ),
      ),
      marked: eligiblePaths(entry, styles.marked),
    }
    candidatePaths.set(entry, paths)
    return paths
  }

  const rooms: ReservedRoom[] = []
  for (const entry of details) {
    const { placed, origin, styles, wrapper } = entry
    if (candidatesAt && !candidatesAt.has(wrapper ?? placed)) continue
    const { element } = placed
    const { hidden, heights, marked } = pathsFor(entry)
    const ownShape = distinctPaths([...hidden, ...heights])
    const childPaths = marked.length
      ? details.flatMap((child) => {
          if (child.wrapper !== placed) return []
          const paths = pathsFor(child)
          return [...paths.hidden, ...paths.heights, ...paths.marked]
        })
      : []
    const candidates = distinctPaths([
      ...ownShape,
      ...marked.flatMap((scope) => withoutGuardOptions(scope, childPaths)),
    ])
    if (!candidates.length) continue
    const { matches, unresolved } = sourceAt(origin)
    const flowFor = (vacancies: Condition[][], known: boolean): boolean =>
      styles.inFlow(
        distinctPaths(
          ownShape.flatMap((shape) =>
            vacancies.flatMap((vacant) => {
              const linked = [...shape, ...vacant]
              return possible(linked) ? [linked] : []
            }),
          ),
        ),
        known,
      )
    const reserve = { ...placed, state: origin }
    const createRoom = (
      room: Pick<
        ReservedRoom,
        | 'active'
        | 'service'
        | 'peers'
        | 'vacancy'
        | 'unresolvedLayout'
        | 'unresolvedSource'
        | 'overlays'
      >,
    ): ReservedRoom => ({
      reserve,
      requiresDelegation: false,
      wrapper,
      reserveIn: reserveIn(element, origin),
      ...room,
    })
    const live = () => visibleFor(entry, matches)
    const fromOwnNode = (other: RoomDetail): boolean =>
      other.placed.element === element && other.origin === origin
    const priorOwn = hidden.length
      ? previous?.details.filter(fromOwnNode)
      : undefined
    const direct = hidden.filter(
      (conditions) =>
        !inheritedHidden &&
        !dom.uncertainVisibility &&
        isRefusalMessage({ ...placed, conditions }, matches, origin),
    )
    const ownEvidence = (
      certainty: keyof EvidencePaths,
    ): { active: Condition[][]; vacancy: Condition[][] } => {
      const active: Condition[][] = []
      const vacancy: Condition[][] = []
      if (hidden.length)
        for (const path of [
          ...live()[certainty],
          ...(priorOwn?.flatMap(
            (other) => previous!.visibleFor(other, matches)[certainty],
          ) ?? []),
        ]) {
          const fixed = fixedRefusalText(placed, origin)
          for (const scope of hidden) {
            const transition = refusalTransition(
              scope,
              path,
              (guard) =>
                localRefusalCondition(guard, origin) &&
                matches(guard.value, guard.truthy, fixed, path),
            )
            if (!transition) continue
            active.push([...path, ...transition.mode])
            vacancy.push([...scope, ...transition.mode])
          }
        }
      return { active: distinctPaths(active), vacancy: distinctPaths(vacancy) }
    }
    const known = ownEvidence('known')
    const uncertain = ownEvidence('possible')
    const ownVacancy = distinctPaths([
      ...direct,
      ...known.vacancy,
      ...uncertain.vacancy,
    ])
    if (ownVacancy.length) {
      const provenVacancy = distinctPaths([...direct, ...known.vacancy])
      const requiresDelegation = flowFor(provenVacancy, true)
      const uncertainFlow = !requiresDelegation && flowFor(provenVacancy, false)
      rooms.push({
        ...createRoom({
          active: { known: known.active, possible: uncertain.active },
          service: [],
          vacancy: ownVacancy,
          peers: [],
          unresolvedLayout:
            uncertainFlow || dom.unresolved || !provenVacancy.length
              ? true
              : undefined,
        }),
        requiresDelegation,
      })
    }

    const peerCandidates = ownVacancy.length
      ? distinctPaths(
          candidates.flatMap((scope) => withoutGuardOptions(scope, ownVacancy)),
        )
      : candidates
    if (!peerCandidates.length) continue

    const isPeer = (other: RoomDetail): boolean =>
      !fromOwnNode(other) &&
      (other.placed.element !== wrapper?.element || other.origin !== origin) &&
      (other.wrapper?.element !== element || other.origin !== origin) &&
      other.text
    const peers = details.filter(isPeer)
    const priorPeers = previous?.details.filter(isPeer) ?? []
    const overlays = (active: PlacedElement): Appearance[] =>
      appearances(active.element, active.conditions, lexical(active)).filter(
        (look) => look.outOfFlow && !look.removed,
      )
    const hasRelativeAnchor = (conditions: Condition[]): boolean =>
      Boolean(
        parent &&
        elementStyles(parent.element, lexical(parent)).some(
          (fact) =>
            (classTokens(fact).includes('position-relative') ||
              cssDeclarations(fact).some(
                ([name, value]) => name === 'position' && value === 'relative',
              )) &&
            !withoutGuardOptions(
              conditions,
              applyGuards(conditions, fact.guards ?? [], lexical(parent)),
            ).length,
        ),
      )
    const separateReason = (active: PlacedElement, anchor: string): boolean =>
      peers.some(
        ({ placed: alternative, origin: alternativeOrigin, ancestors }) => {
          if (alternative.element === active.element) return false
          return applyGuards(
            active.conditions,
            alternative.conditions,
            alternativeOrigin,
          )
            .flatMap((conditions) =>
              visibleConditions(
                alternative.element,
                conditions,
                alternativeOrigin,
                ancestors,
                inheritedHidden,
              ),
            )
            .some((conditions) => {
              const reason = { ...alternative, conditions }
              return (
                sameTextSource(reserve, reason, origin) &&
                !isRefusalMessage(
                  { ...reason, conditions: alternative.conditions },
                  sourceAt(alternativeOrigin).matches,
                  alternativeOrigin,
                ) &&
                overlays(reason).some(
                  (look) =>
                    overlayAnchor(
                      reason.element,
                      look.conditions,
                      alternativeOrigin,
                    ) !== anchor,
                )
              )
            })
        },
      )
    const link = (
      scope: Condition[],
      active: PlacedElement,
      peer: RoomDetail,
      source: ErrorCondition,
      candidateSource: ErrorCondition,
    ): { mode: Condition[]; overlays: RefusalOverlay[] } | undefined => {
      const fixed = fixedRefusalText(active, peer.origin)
      const refusal = (guard: Condition): boolean =>
        (localRefusalCondition(guard, peer.origin) &&
          source(guard.value, guard.truthy, fixed, active.conditions)) ||
        (peer.origin !== origin &&
          localRefusalCondition(guard, origin) &&
          candidateSource(guard.value, guard.truthy, fixed, active.conditions))
      const transition = refusalTransition(scope, active.conditions, refusal)
      if (!transition) return
      const twin = { ...reserve, conditions: scope }
      const fromHidden = hidden.some(
        (path) => !withoutGuardOptions(scope, [path]).length,
      )
      const liveId = stableId(active.element)
      const candidateId = stableId(element)
      const addressed =
        (fromHidden ||
          styles.uncertainTwin.some(
            (path) => !withoutGuardOptions(scope, [path]).length,
          )) &&
        (hasTrueAttribute(element, 'aria-hidden', scope, origin) ||
          hasTrueAttribute(element, 'inert', scope, origin)) &&
        !messageText(element.children) &&
        !textBinding(element) &&
        liveId &&
        ((candidateId && roomId(candidateId) === roomId(liveId)) ||
          (slot && roomId(slot) === roomId(liveId)))
      const liveOverlays = overlays(active)
      const candidateOverlays = marked.length ? [] : overlays(twin)
      if (
        liveOverlays.some((look) =>
          candidateOverlays.some(
            (candidate) =>
              overlayAnchor(element, candidate.conditions, origin) ===
              overlayAnchor(active.element, look.conditions, peer.origin),
          ),
        )
      )
        return
      if (
        transition.switches ||
        addressed ||
        (fromHidden &&
          hasRelativeAnchor(transition.mode) &&
          (sameTextSource(twin, active, origin) ||
            (fixedRefusalText(twin, origin) && fixed)) &&
          liveOverlays.some(
            (look) =>
              !separateReason(
                { ...active, conditions: look.conditions },
                overlayAnchor(active.element, look.conditions, peer.origin),
              ),
          ))
      ) {
        // The peer's render guards describe the serviced mode. Styling can
        // hide the peer within that mode, leaving a vacancy to check.
        for (const path of applyGuards(
          [],
          peer.placed.conditions,
          peer.origin,
        )) {
          if (!possible([...path, ...active.conditions])) continue
          const mode = refusalTransition(scope, path, refusal)?.mode
          if (mode)
            return {
              mode,
              overlays: peer.wrapper
                ? []
                : liveOverlays
                    .map((look) => ({
                      anchor: overlayAnchor(
                        active.element,
                        look.conditions,
                        peer.origin,
                      ),
                      conditions: look.conditions,
                      refusal,
                    }))
                    .filter(({ anchor }) => Boolean(anchor)),
            }
        }
      }
      return undefined
    }

    const linkedCandidates = styles.uncertainTwin.length
      ? distinctPaths(
          peerCandidates.flatMap((scope) => [
            ...withoutGuardOptions(scope, styles.uncertainTwin),
            ...styles.uncertainTwin
              .map((path) => [...scope, ...path])
              .filter(possible),
          ]),
        )
      : peerCandidates
    const collect = (kind: keyof RefusalChecks) => {
      const active: EvidencePaths = { known: [], possible: [] }
      const service: Condition[][] = []
      const knownVacancy: Condition[][] = []
      const linked: PlacedElement[] = []
      const overlays: RefusalOverlay[] = []
      for (const peer of [...peers, ...priorPeers]) {
        const source = sourceAt(peer.origin)[kind]
        const paths = priorPeers.includes(peer)
          ? previous!.visibleFor(peer, source)
          : visibleFor(peer, source)
        let used = false
        for (const certainty of ['known', 'possible'] as const)
          for (const conditions of paths[certainty])
            for (const scope of linkedCandidates) {
              const linkOnPath = link(
                scope,
                { ...peer.placed, conditions },
                peer,
                source,
                sourceAt(origin)[kind],
              )
              if (!linkOnPath) continue
              used = true
              service.push(linkOnPath.mode)
              if (certainty === 'known')
                knownVacancy.push([...scope, ...linkOnPath.mode])
              active[certainty].push([...linkOnPath.mode, ...conditions])
              overlays.push(...linkOnPath.overlays)
            }
        if (used) linked.push(peer.wrapper ?? peer.placed)
      }
      return {
        active: {
          known: distinctPaths(active.known),
          possible: distinctPaths(active.possible),
        },
        service: distinctPaths(service),
        knownVacancy: distinctPaths(knownVacancy),
        peers: linked,
        overlays,
      }
    }
    const linked = collect('matches')
    if (linked.service.length) {
      const requiresDelegation = flowFor(linked.knownVacancy, true)
      const uncertainFlow =
        !requiresDelegation && flowFor(linked.knownVacancy, false)
      rooms.push({
        ...createRoom({
          ...linked,
          unresolvedLayout:
            !linked.active.known.length ||
            uncertainFlow ||
            (!ownShape.length &&
              linked.knownVacancy.some((scope) =>
                styles.uncertainTwin.some((path) =>
                  possible([...scope, ...path]),
                ),
              ))
              ? true
              : undefined,
        }),
        requiresDelegation,
      })
    } else if (
      ((!inheritedHidden || live().known.length || live().possible.length) &&
        peerCandidates.some((conditions) =>
          isRefusalMessage({ ...placed, conditions }, unresolved, origin),
        )) ||
      collect('unresolved').service.length
    )
      rooms.push(
        createRoom({
          active: { known: [], possible: [] },
          service: [],
          peers: [],
          unresolvedSource: true,
        }),
      )
  }

  return rooms
}

function elementContext(element: Element, context: Context): Context {
  if (element.component === MODAL) return 'modal'
  if (
    (element.component && TABLES.has(element.component)) ||
    NATIVE_TABLE.has(element.name) ||
    TABLE_SLOTS.has(element.name) ||
    /^#(?:cell-|detail-|hilosTable(?:Cell|Detail)$)/.test(element.name)
  )
    return 'table'
  return context
}

function projectedTemplate(element: Element, parent?: Element): boolean {
  if (element.templateReference === undefined) return true
  if (parent?.component === MODAL)
    return ['#modalBody', '#modalActions'].includes(element.name)
  return Boolean(
    parent?.component &&
    TABLES.has(parent.component) &&
    elementContext(element, 'plain') === 'table',
  )
}

const MODAL_STATE = condition(expression('$modalContext')!)
const REFUSAL_STATE = condition(expression('$refusalMessage')!)
const SUPPRESSED_STATE = condition(expression('$suppressedInput')!)
const NULL_MODAL = condition(expression('$nullModalContext')!)
const NULL_REFUSAL = condition(expression('$nullRefusalMessage')!)
const CANONICAL_GUARDS = new Set(
  [MODAL_STATE, REFUSAL_STATE, SUPPRESSED_STATE, NULL_MODAL, NULL_REFUSAL].map(
    ({ value }) => value,
  ),
)

interface ViewMount {
  id: number
  view: View
  events: MountedEvent[]
  sender?: ViewMount
}

type MountView = (
  view: View,
  sender?: ViewMount,
  element?: Element,
) => ViewMount

type SlotAvailability = 'absent' | 'null' | 'falsy' | 'truthy'

interface MountedValue {
  value: ts.Expression
  state: EmptyState
  availability?: SlotAvailability
  nodes?: RenderNode[]
}

interface MountedContent {
  nodes: RenderNode[]
  state: EmptyState
}

interface RenderedPath extends MountedContent {
  guards: Condition[]
  unresolved?: true
}

interface EmptyState {
  view: View
  mountView: MountView
  mount?: ViewMount
  knownJsxInputs?: boolean
  canonical?: 'form' | 'action'
  owner?: View
  inputs?: ReadonlyMap<string, MountedValue | undefined>
  outlets?: ReadonlyMap<string | number | symbol, MountedOutlet>
  rendering?: ReadonlySet<View>
  inputOutcome?: InputOutcome
  twin?: Element
  origins?: ReadonlyMap<Element, EmptyState>
}

const UNDEFINED_INPUT = expression('undefined')!
const TRUE_INPUT = expression('true')!

function mountedValue(
  value: ts.Expression,
  state: EmptyState,
): MountedValue | undefined {
  if (!state.inputs) return undefined
  const subject =
    ts.isCallExpression(value) && !value.arguments.length
      ? value.expression
      : value
  const jsx =
    !ts.isCallExpression(subject) && jsxInputReference(subject, state.view)
  const name =
    (jsx ? jsx.name : undefined) ??
    vueInput(subject, state.view)?.name ??
    angularInputName(subject, state.view)
  if (!name) return undefined
  const supplied = state.inputs.get(name)
  if (
    supplied &&
    (!(jsx && jsx.fallback) || supplied.availability !== 'absent')
  )
    return supplied
  if (jsx && state.knownJsxInputs && (supplied || !state.inputs.has(name)))
    return { value: jsx.fallback ?? UNDEFINED_INPUT, state }
  return supplied
}

function mountedScalar(
  value: ts.Expression,
  state: EmptyState,
): StaticValue | undefined {
  return staticValue(value, state.view, undefined, state)
}

function mountedInputValues(
  element: Element,
  target: View,
  state: EmptyState,
  reachable: Condition[] = [],
): Map<string, MountedValue | undefined> {
  const inputs = new Map<string, MountedValue | undefined>()
  for (const attr of element.attributes) {
    if (
      attr.bindingType === BindingType.Attribute ||
      attr.bindingType === BindingType.Animation
    )
      continue
    const value =
      attr.expression ??
      (attr.literal === undefined
        ? TRUE_INPUT
        : expression(JSON.stringify(attr.literal))!)
    const name = target.vueProps
      ? attr.name.replace(/-([a-z])/g, (_, letter: string) =>
          letter.toUpperCase(),
        )
      : attr.name
    inputs.set(name, {
      value,
      state,
      availability: state.view.jsxResolve
        ? jsxSlotAvailability(value, state, reachable)
        : undefined,
    })
  }
  return inputs
}

function setExplicitJsxChildren(
  element: Element,
  state: EmptyState,
  inputs: Map<string, MountedValue | undefined>,
): void {
  if (!element.explicitChildren) return
  const value = element.jsxChildValue
  inputs.set(
    'children',
    value
      ? { value, state, availability: jsxSlotAvailability(value, state) }
      : {
          value: TRUE_INPUT,
          state,
          nodes: element.children,
          availability: 'truthy',
        },
  )
}

interface JsxValuePath {
  guards: Condition[]
  input?: MountedValue
  unresolved?: true
}

function jsxSelectionTest(value: ts.Expression, test: Condition): Condition {
  return ts.isConditionalExpression(value)
    ? test
    : { ...test, jsxOperand: true }
}

function jsxChoiceAlias(value: ts.Expression): ts.Expression | undefined {
  const alias = jsxReference(value)
  if (
    alias &&
    !ts.isFunctionDeclaration(alias) &&
    (ts.isIdentifier(alias) || jsxValueChoice(alias))
  )
    return alias
  return undefined
}

function jsxValuePaths(
  value: ts.Expression,
  state: EmptyState,
  guards: Condition[],
  seen = new Set<ts.Expression>(),
): JsxValuePath[] {
  value = transparent(value)
  if (seen.has(value) || seen.size >= 32) return [{ guards, unresolved: true }]
  seen = new Set(seen).add(value)
  const reference = state.inputs && jsxInputReference(value, state.view)
  if (reference) {
    const supplied = state.inputs?.get(reference.name)
    const options: JsxValuePath[] = supplied
      ? supplied.nodes
        ? [{ guards, input: supplied }]
        : jsxValuePaths(supplied.value, supplied.state, guards, seen)
      : [{ guards, unresolved: state.knownJsxInputs ? undefined : true }]
    return options.flatMap((option) => {
      if (
        option.input &&
        !unshadowed(option.input.value, option.input.state.view, 'undefined')
      )
        return [option]
      const fallback = reference.fallback
      if (!fallback)
        return [
          option.unresolved
            ? option
            : { ...option, input: { value: UNDEFINED_INPUT, state } },
        ]
      return jsxValuePaths(fallback, state, option.guards, seen).map(
        (path) => ({
          ...path,
          unresolved: option.unresolved || path.unresolved,
        }),
      )
    })
  }
  const choice = jsxValueChoice(value)
  if (choice)
    return choice.flatMap(([test, arm]) =>
      applyGuards(guards, [jsxSelectionTest(value, test)], state).flatMap(
        (scope) => jsxValuePaths(arm, state, scope, seen),
      ),
    )
  const alias = jsxChoiceAlias(value)
  if (alias) return jsxValuePaths(alias, state, guards, seen)
  return [{ guards, input: { value, state } }]
}

function renderedJsxInput(
  node: Text,
  state: EmptyState,
  guards: Condition[],
): RenderedPath[] | undefined {
  const value = node.bindings?.[0]
  if (
    !value ||
    !state.view.jsxResolve ||
    !state.inputs ||
    !jsxInputReference(value, state.view)
  )
    return undefined
  return jsxValuePaths(value, state, guards).flatMap(
    ({ input, guards, unresolved }) => {
      if (!input || !input.state.view.jsxResolve) return []
      const nodes =
        input.nodes ??
        (unshadowed(input.value, input.state.view, 'undefined')
          ? []
          : jsxSlot(input.value, input.state.view.jsxResolve))
      return [{ nodes, state: input.state, guards, unresolved }]
    },
  )
}

function unshadowed(value: ts.Expression, view: View, name: string): boolean {
  return (
    ts.isIdentifier(value) &&
    value.text === name &&
    !templateLocal(value) &&
    !stateBinding(value, view) &&
    !view.scripts.some((file) => imports(file, () => undefined).has(name))
  )
}

function canonicalInput(
  value: ts.Expression,
  view: View,
  name: string,
): boolean {
  if (jsxInput(value, view) === name) return true
  const vue = vueInput(value, view)
  if (vue?.name === name && memberType(vue.type, name)) return true
  return angularInputName(value, view) === name
}

function angularInputName(
  value: ts.Expression,
  view: View,
): string | undefined {
  if (!view.contentOutlets) return undefined
  const name = stateName(value)
  const input = initializer(stateBinding(value, view))
  if (!name || !input || !ts.isCallExpression(input)) return undefined
  const factory = input.expression
  const imported = expressionImport(
    ts.isPropertyAccessExpression(factory) && factory.name.text === 'required'
      ? factory.expression
      : factory,
    view,
  )
  return imported?.name === 'input' && imported.module === '@angular/core'
    ? name
    : undefined
}

function angularActionInput(
  value: ts.Expression,
  state: EmptyState,
  seen: Set<ts.Declaration>,
): boolean {
  value = transparent(value)
  const mounted = mountedValue(value, state)
  if (mounted) return angularActionInput(mounted.value, mounted.state, seen)
  const { view } = state
  if (ts.isCallExpression(value) && !value.arguments.length)
    return (
      view === (state.owner ?? view) &&
      canonicalInput(value.expression, view, 'action')
    )
  const binding = stateBinding(value, view)
  const alias = immutableInitializer(binding)
  return Boolean(
    binding &&
    alias &&
    !seen.has(binding) &&
    angularActionInput(alias, state, new Set(seen).add(binding)),
  )
}

function angularReceiverError(
  receiver: ts.Expression,
  state: EmptyState,
  seen = new Set<ts.Declaration>(),
): MountedValue | undefined {
  receiver = transparent(receiver)
  const mounted = mountedValue(receiver, state)
  if (mounted) return angularReceiverError(mounted.value, mounted.state, seen)
  if (ts.isObjectLiteralExpression(receiver)) {
    const error = objectEntries(receiver)?.get('error')
    if (
      error &&
      (ts.isArrowFunction(error) || ts.isFunctionExpression(error))
    ) {
      const value = simpleReturn(error.body)
      if (value) return { value, state }
    }
  }
  const binding = stateBinding(receiver, state.view)
  const alias = immutableInitializer(binding)
  return binding && alias && !seen.has(binding)
    ? angularReceiverError(alias, state, new Set(seen).add(binding))
    : undefined
}

function modalContext(call: ts.CallExpression, view: View): boolean {
  const factory = expressionImport(call.expression, view)
  if (factory?.name === 'useHilosModal')
    return factory.path === 'framework/frontend/react/src/hilosModalContext.js'
  if (factory?.name !== 'inject') return false
  const [argument] = call.arguments
  const token = argument && expressionImport(argument, view)
  return (
    (factory.module === 'vue' &&
      token?.name === 'HILOS_MODAL_CONTEXT' &&
      token.path === 'framework/frontend/vue/src/hilosModalContext.js') ||
    (factory.module === '@angular/core' &&
      token?.name === 'HILOS_MODAL_TOKEN' &&
      token.path === 'framework/frontend/angular/src/hilosModalToken.js')
  )
}

type CanonicalLiteral = 'null' | 'undefined' | 'emptyString' | 'false' | 'true'
type CanonicalFalsy = Exclude<CanonicalLiteral, 'true'>

interface CanonicalValue {
  guard: Condition
  truthy: 'boolean' | 'text'
  falsy: CanonicalFalsy[]
  nullCase?: Condition
}

function canonicalLiteral(value: ts.Expression): CanonicalLiteral | undefined {
  value = unparenthesized(value)
  if (value.kind === ts.SyntaxKind.NullKeyword) return 'null'
  if (value.kind === ts.SyntaxKind.TrueKeyword) return 'true'
  if (value.kind === ts.SyntaxKind.FalseKeyword) return 'false'
  if (ts.isIdentifier(value) && value.text === 'undefined') return 'undefined'
  if (ts.isStringLiteralLike(value) && value.text === '') return 'emptyString'
  return undefined
}

function canonicalComparison(
  value: ts.Expression,
  state: EmptyState,
  seen: Set<ts.Declaration>,
): { equal: boolean; guard: Condition; subcase?: Condition } | undefined {
  if (!ts.isBinaryExpression(value)) return undefined
  const operator = value.operatorToken.kind
  const loose =
    operator === ts.SyntaxKind.EqualsEqualsToken ||
    operator === ts.SyntaxKind.ExclamationEqualsToken
  const equal =
    operator === ts.SyntaxKind.EqualsEqualsToken ||
    operator === ts.SyntaxKind.EqualsEqualsEqualsToken
  if (
    !equal &&
    operator !== ts.SyntaxKind.ExclamationEqualsToken &&
    operator !== ts.SyntaxKind.ExclamationEqualsEqualsToken
  )
    return undefined
  for (const [subject, literal] of [
    [value.left, value.right],
    [value.right, value.left],
  ]) {
    const compared = canonicalLiteral(literal)
    if (!compared) continue
    const known = canonicalValue(subject, state, seen)
    if (!known) return undefined
    if (compared === 'true')
      return known.truthy === 'boolean'
        ? { equal, guard: known.guard }
        : undefined
    if (loose && compared === 'false' && known.truthy !== 'boolean')
      return undefined
    const matching = known.falsy.filter(
      (value) =>
        value === compared ||
        (loose &&
          ((nullish(value) && nullish(compared)) ||
            (value === 'false' && compared === 'emptyString') ||
            (value === 'emptyString' && compared === 'false'))),
    )
    if (!matching.length)
      return loose
        ? undefined
        : { equal, guard: condition(expression('false')!) }
    const guard = opposite(known.guard)
    if (matching.length === known.falsy.length) return { equal, guard }
    // Nullable inputs list the null case first, even after coalescing its value.
    const { nullCase } = known
    if (nullCase)
      return {
        equal,
        guard,
        subcase: matching[0] === known.falsy[0] ? nullCase : opposite(nullCase),
      }
  }
  return undefined
}

function nullish(value: CanonicalLiteral): boolean {
  return value === 'null' || value === 'undefined'
}

function vueComputedResult(
  call: ts.CallExpression,
  view: View,
): ts.Expression | undefined {
  const [callback] = call.arguments
  if (
    !callback ||
    (!ts.isArrowFunction(callback) && !ts.isFunctionExpression(callback))
  )
    return undefined
  const factory = expressionImport(call.expression, view)
  return factory?.module === 'vue' && factory.name === 'computed'
    ? simpleReturn(callback.body, view)
    : undefined
}

function simpleReturn(
  body: ts.ConciseBody | undefined,
  view?: View,
): ts.Expression | undefined {
  if (!body || !ts.isBlock(body)) return body
  // A null result continues to the next statement; undefined means the
  // control flow is unknown.
  const read = (statement: ts.Statement): ts.Expression | null | undefined => {
    if (ts.isReturnStatement(statement))
      return statement.expression ?? UNDEFINED_INPUT
    if (ts.isBlock(statement)) {
      for (const child of statement.statements) {
        const result = read(child)
        if (result !== null) return result
      }
      return null
    }
    if (ts.isIfStatement(statement) && view) {
      const test = condition(statement.expression)
      const value = staticValue(test.value, view)
      const truthy =
        value === undefined ? literalTruth(test.value) : Boolean(value)
      if (truthy === undefined) return undefined
      const selected =
        truthy === test.truthy
          ? statement.thenStatement
          : statement.elseStatement
      return selected ? read(selected) : null
    }
    if (
      ts.isVariableStatement(statement) &&
      statement.declarationList.flags & ts.NodeFlags.Const
    )
      return null
    return undefined
  }
  const result = read(body)
  return result === null ? view && UNDEFINED_INPUT : result
}

function canonicalAlias(
  binding: ts.Declaration | undefined,
  view: View,
  invoked: boolean,
): ts.Expression | undefined {
  if (
    binding &&
    ((ts.isGetAccessorDeclaration(binding) && !invoked) ||
      (invoked &&
        (ts.isMethodDeclaration(binding) ||
          ts.isFunctionDeclaration(binding)) &&
        !binding.parameters.length))
  )
    return simpleReturn(binding.body)
  const input = immutableInitializer(binding)
  if (!input) return undefined
  if (ts.isArrowFunction(input) || ts.isFunctionExpression(input))
    return invoked && !input.parameters.length
      ? simpleReturn(input.body)
      : undefined
  if (!ts.isCallExpression(input)) return invoked ? undefined : input
  const factory = expressionImport(input.expression, view)
  const [compute, dependencies] = input.arguments
  if (
    compute &&
    (ts.isArrowFunction(compute) || ts.isFunctionExpression(compute))
  ) {
    const result = simpleReturn(
      compute.body,
      factory?.module === 'vue' ? view : undefined,
    )
    if (
      factory?.name === 'computed' &&
      ((factory.module === 'vue' && !invoked) ||
        (factory.module === '@angular/core' && invoked))
    )
      return result
    if (
      factory?.name === 'useMemo' &&
      factory.module === 'react' &&
      !invoked &&
      result &&
      dependencies &&
      ts.isArrayLiteralExpression(dependencies) &&
      dependencies.elements.length === 1 &&
      condition(result).expression ===
        condition(dependencies.elements[0]!).expression
    )
      return result
  }
  return invoked ? undefined : input
}

function canonicalValue(
  value: ts.Expression,
  state: EmptyState,
  seen: Set<ts.Declaration>,
): CanonicalValue | undefined {
  const { view } = state
  value = transparent(value)
  const local = templateLocal(value)
  if (local) return local.value && canonicalValue(local.value, state, seen)
  const mounted = mountedValue(value, state)
  if (mounted) return canonicalValue(mounted.value, mounted.state, seen)
  const owner = state.owner ?? view
  if (
    ts.isPrefixUnaryExpression(value) &&
    value.operator === ts.SyntaxKind.ExclamationToken
  ) {
    const subject = canonicalValue(value.operand, state, seen)
    return (
      subject && {
        guard: opposite(subject.guard),
        truthy: 'boolean',
        falsy: ['false'],
      }
    )
  }

  const compared = canonicalComparison(value, state, seen)
  if (compared && !compared.subcase)
    return {
      guard: compared.equal ? compared.guard : opposite(compared.guard),
      truthy: 'boolean',
      falsy: ['false'],
    }

  if (
    ts.isBinaryExpression(value) &&
    [ts.SyntaxKind.QuestionQuestionToken, ts.SyntaxKind.BarBarToken].includes(
      value.operatorToken.kind,
    )
  ) {
    const subject = canonicalValue(value.left, state, seen)
    const fallback = canonicalLiteral(value.right)
    if (subject && fallback && fallback !== 'true') {
      const valueOperator = value.operatorToken.kind
      const falsy = subject.falsy.map((value) =>
        valueOperator === ts.SyntaxKind.BarBarToken || nullish(value)
          ? fallback
          : value,
      )
      return { ...subject, falsy: [...new Set(falsy)] }
    }
  }

  if (ts.isConditionalExpression(value)) {
    const test = canonicalValue(value.condition, state, seen)
    for (const [empty, live, inverted] of [
      [value.whenTrue, value.whenFalse, true],
      [value.whenFalse, value.whenTrue, false],
    ] as const) {
      const fallback = canonicalLiteral(empty)
      const present = canonicalValue(live, state, seen)
      if (
        fallback &&
        fallback !== 'true' &&
        test &&
        present &&
        test.guard.expression === present.guard.expression &&
        (test.guard.truthy !== present.guard.truthy) === inverted
      )
        return { ...present, falsy: [fallback] }
    }
  }

  let invoked = false
  if (ts.isCallExpression(value)) {
    if (state.canonical === 'form' && modalContext(value, view)) {
      if (view.vueProps) {
        const fallback = value.arguments[1]
        const literal = fallback ? canonicalLiteral(fallback) : 'undefined'
        if (literal === 'true')
          return {
            guard: condition(expression('true')!),
            truthy: 'boolean',
            falsy: [],
          }
        return literal
          ? { guard: MODAL_STATE, truthy: 'boolean', falsy: [literal] }
          : undefined
      }
      return {
        guard: MODAL_STATE,
        truthy: 'boolean',
        falsy: view.contentOutlets ? ['null', 'false'] : ['false'],
        nullCase: view.contentOutlets ? NULL_MODAL : undefined,
      }
    }
    if (
      stateName(value.expression) === 'Boolean' &&
      !stateBinding(value.expression, view) &&
      value.arguments[0]
    ) {
      const subject = canonicalValue(value.arguments[0], state, seen)
      return (
        subject && { guard: subject.guard, truthy: 'boolean', falsy: ['false'] }
      )
    }
    if (value.arguments.length) return undefined
    invoked = true
    value = value.expression
  }
  if (
    state.canonical === 'form' &&
    (!view.contentOutlets || invoked) &&
    canonicalInput(value, owner, 'message')
  )
    return {
      guard: REFUSAL_STATE,
      truthy: 'text',
      falsy: ['null', 'emptyString'],
      nullCase: NULL_REFUSAL,
    }
  if (state.canonical === 'action' && (!view.contentOutlets || invoked)) {
    if (
      (!view.vueProps || view === owner) &&
      canonicalInput(value, owner, 'suppressed')
    )
      return { guard: SUPPRESSED_STATE, truthy: 'boolean', falsy: ['false'] }
    if (ts.isPropertyAccessExpression(value) && value.name.text === 'error') {
      const receiver = value.expression
      const angularInput =
        view.contentOutlets && angularActionInput(receiver, state, seen)
      if (
        angularInput ||
        (!view.contentOutlets &&
          (!view.vueProps || view === owner) &&
          canonicalInput(receiver, owner, 'action'))
      )
        return {
          guard: REFUSAL_STATE,
          truthy: 'text',
          falsy: ['null', 'emptyString'],
          nullCase: NULL_REFUSAL,
        }
    }
  }

  if (
    view.vueProps &&
    ts.isPropertyAccessExpression(value) &&
    value.name.text === 'value'
  )
    return canonicalValue(value.expression, state, seen)

  const binding = stateBinding(value, view)
  const input = canonicalAlias(binding, view, invoked)
  return binding && input && !seen.has(binding)
    ? canonicalValue(input, state, new Set(seen).add(binding))
    : undefined
}

function booleanComparison(
  value: ts.Expression,
  state: EmptyState,
  seen: Set<ts.Declaration>,
): Condition | undefined {
  if (!ts.isBinaryExpression(value)) return undefined
  const operator = value.operatorToken.kind
  const equal = [
    ts.SyntaxKind.EqualsEqualsEqualsToken,
    ts.SyntaxKind.EqualsEqualsToken,
  ].includes(operator)
  if (
    !equal &&
    ![
      ts.SyntaxKind.ExclamationEqualsEqualsToken,
      ts.SyntaxKind.ExclamationEqualsToken,
    ].includes(operator)
  )
    return undefined
  for (const [subject, literal] of [
    [value.left, value.right],
    [value.right, value.left],
  ] as const) {
    const compared = canonicalLiteral(literal)
    if (
      (compared === 'true' || compared === 'false') &&
      booleanExpression(subject, state, seen)
    )
      return condition(subject, equal === (compared === 'true'))
  }
  return undefined
}

function booleanExpression(
  value: ts.Expression,
  state: EmptyState,
  seen: Set<ts.Declaration>,
): boolean {
  value = transparent(value)
  const literal = canonicalLiteral(value)
  if (literal === 'true' || literal === 'false') return true
  if (
    ts.isPrefixUnaryExpression(value) &&
    value.operator === ts.SyntaxKind.ExclamationToken
  )
    return true
  if (ts.isBinaryExpression(value)) {
    const operator = value.operatorToken.kind
    if (
      [
        ts.SyntaxKind.EqualsEqualsEqualsToken,
        ts.SyntaxKind.EqualsEqualsToken,
        ts.SyntaxKind.ExclamationEqualsEqualsToken,
        ts.SyntaxKind.ExclamationEqualsToken,
      ].includes(operator)
    )
      return true
    if (
      [
        ts.SyntaxKind.AmpersandAmpersandToken,
        ts.SyntaxKind.BarBarToken,
      ].includes(operator)
    )
      return (
        booleanExpression(value.left, state, seen) &&
        booleanExpression(value.right, state, seen)
      )
  }
  const known = canonicalValue(value, state, seen)
  if (
    known?.truthy === 'boolean' &&
    known.falsy.every((value) => value === 'false')
  )
    return true
  if (
    ts.isCallExpression(value) &&
    unshadowed(value.expression, state.view, 'Boolean')
  )
    return true
  const subject =
    ts.isCallExpression(value) && !value.arguments.length
      ? value.expression
      : value
  const invoked = subject !== value
  const binding = stateBinding(subject, state.view)
  const alias =
    templateLocal(value)?.value ?? canonicalAlias(binding, state.view, invoked)
  if (!alias || (binding && seen.has(binding))) return false
  return booleanExpression(
    alias,
    state,
    binding ? new Set(seen).add(binding) : seen,
  )
}

function comparisonPaths(
  equal: boolean,
  guard: Condition,
  subcase?: Condition,
): Condition[][] {
  if (!subcase) return [[equal ? guard : opposite(guard)]]
  return equal
    ? [[guard, subcase]]
    : [[opposite(guard)], [guard, opposite(subcase)]]
}

function signedConditionKey(term: Condition): string {
  return `${term.truthy ? '+' : '-'}${conditionKey(term)}`
}

const guardPathKeys = new WeakMap<Condition[], string>()

function guardPathKey(path: Condition[]): string {
  const known = guardPathKeys.get(path)
  if (known !== undefined) return known
  const key = JSON.stringify([...new Set(path.map(signedConditionKey))].sort())
  guardPathKeys.set(path, key)
  return key
}

function distinctPaths(paths: Condition[][]): Condition[][] {
  return [...new Map(paths.map((path) => [guardPathKey(path), path])).values()]
}

function sharedOptions(options: Condition[][][]): Condition[][] | undefined {
  const [first, ...others] = options
  if (!first || !others.length) return undefined
  const shared = new Map(first.map((path) => [guardPathKey(path), path]))
  return others.every((paths) => {
    const keys = new Set(paths.map(guardPathKey))
    return (
      keys.size === shared.size && [...keys].every((key) => shared.has(key))
    )
  })
    ? [...shared.values()]
    : undefined
}

function guardAlternatives(
  guard: Condition,
  state: EmptyState,
  seen = new Set<ts.Declaration>(),
): Condition[][] {
  if (resolvedGuard(guard)) return [[guard]]
  const { value, truthy } = guard
  const local = templateLocal(value)
  const test = (value: ts.Expression): Condition => ({
    ...condition(value, truthy, guard.nullish),
    jsxOperand: guard.jsxOperand,
  })
  if (local?.value) return guardAlternatives(test(local.value), state, seen)
  if (state.inputs && jsxInputReference(value, state.view)) {
    return jsxValuePaths(value, state, []).flatMap(
      ({ guards, input, unresolved }) =>
        input && !unresolved
          ? guardAlternatives(test(input.value), input.state, seen)
              .map((terms) => [...guards, ...terms])
              .filter(possible)
          : [[...guards, { ...guard, view: state.view, mount: state.mount }]],
    )
  }
  const input = mountedValue(value, state)
  if (input) return guardAlternatives(test(input.value), input.state, seen)
  const choice =
    guard.jsxOperand && guard.nullish ? jsxValueChoice(value) : jsxChoice(value)
  if (choice) {
    const branches = choice.flatMap(([branch, arm]) => {
      const paths = guardAlternatives(
        guard.jsxOperand ? jsxSelectionTest(value, branch) : branch,
        state,
        seen,
      )
      if (!paths.length) return []
      return [{ paths, options: guardAlternatives(test(arm), state, seen) }]
    })
    const shared = sharedOptions(branches.map(({ options }) => options))
    if (shared) return shared
    const selected = branches.flatMap(({ paths, options }) =>
      paths.map((path) => {
        const facts = new Set(path.map(signedConditionKey))
        const remaining = options
          .filter((terms) => possible([...path, ...terms]))
          .map((terms) =>
            terms.filter((term) => !facts.has(signedConditionKey(term))),
          )
        return { path, remaining }
      }),
    )
    // Strip only facts implied by selection. Drop the selection itself when
    // every selection path leaves the same options in both arms.
    const equivalent =
      branches.length === choice.length &&
      sharedOptions(selected.map(({ remaining }) => remaining))
    return (
      equivalent ||
      selected.flatMap(({ path, remaining }) =>
        remaining.map((terms) => [...path, ...terms]),
      )
    )
  }
  const known = staticValue(value, state.view)
  let selected = value
  let functionDeclaration = false
  if (known === undefined && state.view.jsxResolve) {
    const visited = new Set<ts.Expression>([value])
    while (visited.size < 32) {
      const next = jsxReference(selected)
      if (!next) break
      if (ts.isFunctionDeclaration(next)) {
        functionDeclaration = true
        break
      }
      if (visited.has(next)) break
      visited.add(next)
      selected = next
    }
  }
  // A short-circuit operand uses the selected value of a const choice.
  // Ordinary render guards keep their original lexical condition.
  if (
    guard.jsxOperand &&
    selected !== value &&
    jsxValueChoice(selected) &&
    seen.size < 32
  ) {
    const binding = stateBinding(value, state.view)
    if (binding && !seen.has(binding))
      return guardAlternatives(
        test(selected),
        state,
        new Set(seen).add(binding),
      )
  }
  const jsxElement =
    ts.isJsxElement(selected) ||
    ts.isJsxSelfClosingElement(selected) ||
    ts.isJsxFragment(selected) ||
    Boolean(
      guard.jsxOperand && (functionDeclaration || isTruthyJsxValue(selected)),
    )
  const absent = unshadowed(selected, state.view, 'undefined')
  const constant = guard.nullish
    ? known !== undefined
      ? known === null
      : jsxElement
        ? false
        : absent || undefined
    : known === undefined
      ? jsxElement
        ? true
        : absent
          ? false
          : literalTruth(value)
      : Boolean(known)
  if (constant !== undefined) return truthy === constant ? [[]] : []
  if (guard.nullish) {
    const canonical = state.canonical && canonicalValue(value, state, seen)
    if (canonical) {
      const { falsy, nullCase } = canonical
      if (!falsy.some(nullish)) return truthy ? [] : [[]]
      const empty = opposite(canonical.guard)
      if (falsy.every(nullish)) return comparisonPaths(truthy, empty)
      if (nullCase)
        return comparisonPaths(
          truthy,
          empty,
          nullish(falsy[0]!) ? nullCase : opposite(nullCase),
        )
    }
    if (ts.isBinaryExpression(value)) {
      const operator = value.operatorToken.kind
      const and = operator === ts.SyntaxKind.AmpersandAmpersandToken
      if (and || operator === ts.SyntaxKind.BarBarToken) {
        const selected = guardAlternatives(
          condition(value.left, and),
          state,
          seen,
        )
        const result = guardAlternatives(test(value.right), state, seen)
        const stopped = guardAlternatives(
          condition(value.left, !and),
          state,
          seen,
        )
        const left = and
          ? guardAlternatives(test(value.left), state, seen)
          : truthy
            ? []
            : [[]]
        return [
          ...selected.flatMap((path) =>
            result.map((terms) => [...path, ...terms]),
          ),
          ...stopped.flatMap((path) =>
            left.map((terms) => [...path, ...terms]),
          ),
        ].filter(possible)
      }
    }
    const alias = jsxReference(value)
    const binding = alias && stateBinding(value, state.view)
    if (
      alias &&
      binding &&
      !seen.has(binding) &&
      !ts.isFunctionDeclaration(alias)
    )
      return guardAlternatives(test(alias), state, new Set(seen).add(binding))
    if (
      (alias && ts.isFunctionDeclaration(alias)) ||
      ts.isArrowFunction(value) ||
      ts.isFunctionExpression(value) ||
      ts.isObjectLiteralExpression(value) ||
      ts.isArrayLiteralExpression(value) ||
      ts.isJsxElement(value) ||
      ts.isJsxSelfClosingElement(value) ||
      ts.isJsxFragment(value) ||
      (ts.isPrefixUnaryExpression(value) &&
        value.operator === ts.SyntaxKind.ExclamationToken)
    )
      return truthy ? [] : [[]]
    return [[{ ...guard, view: state.view, mount: state.mount }]]
  }
  if (ts.isBinaryExpression(value)) {
    const operator = value.operatorToken.kind
    const and = operator === ts.SyntaxKind.AmpersandAmpersandToken
    if (and || operator === ts.SyntaxKind.BarBarToken) {
      const left = guardAlternatives(test(value.left), state, seen)
      const right = guardAlternatives(test(value.right), state, seen)
      return truthy === and
        ? left.flatMap((a) => right.map((b) => [...a, ...b]))
        : [...left, ...right]
    }
  }
  if (
    ts.isCallExpression(value) &&
    unshadowed(value.expression, state.view, 'Boolean')
  ) {
    const [argument] = value.arguments
    if (argument)
      return guardAlternatives(condition(argument, truthy), state, seen)
  }
  if (state.canonical) {
    const boolean = booleanComparison(value, state, seen)
    if (boolean)
      return guardAlternatives(
        condition(boolean.value, truthy === boolean.truthy),
        state,
        seen,
      )
    const compared = canonicalComparison(value, state, seen)
    if (compared)
      return comparisonPaths(
        truthy === compared.equal,
        compared.guard,
        compared.subcase,
      )
    const canonical = canonicalValue(value, state, seen)?.guard
    if (canonical) return [[truthy ? canonical : opposite(canonical)]]

    const subject =
      ts.isCallExpression(value) && !value.arguments.length
        ? value.expression
        : value
    const binding = stateBinding(subject, state.view)
    const input = canonicalAlias(binding, state.view, subject !== value)
    if (binding && input && !seen.has(binding))
      return guardAlternatives(
        condition(input, truthy),
        state,
        new Set(seen).add(binding),
      )
  }
  return [[{ ...guard, view: state.view, mount: state.mount }]]
}

function resolvedGuard(guard: Condition): boolean {
  return Boolean(guard.view) || CANONICAL_GUARDS.has(guard.value)
}

const guardOptions = new WeakMap<
  EmptyState,
  WeakMap<Condition, Condition[][]>
>()

function applyGuards(
  starting: Condition[],
  guards: Condition[],
  state: EmptyState,
): Condition[][] {
  if (!guards.length) return [starting]
  if (!starting.length && guards.every(resolvedGuard))
    return possible(guards) ? [guards] : []

  let cache = guardOptions.get(state)
  if (!cache) {
    cache = new WeakMap()
    guardOptions.set(state, cache)
  }
  let options = [starting]
  for (const guard of guards) {
    if (!options.length) break
    let alternatives = cache.get(guard)
    if (!alternatives) {
      alternatives = guardAlternatives(guard, state)
      if (guard.opening)
        alternatives = alternatives.map((path) =>
          path.map((term) => ({ ...term, opening: true })),
        )
      cache.set(guard, alternatives)
    }
    if (alternatives.length === 1 && !alternatives[0]!.length) {
      options = options.filter(possible)
      continue
    }
    const terms = alternatives
    options = options.flatMap((path) =>
      terms.map((added) => [...path, ...added]).filter(possible),
    )
  }
  return options
}

function withoutGuardOptions(
  starting: Condition[],
  options: Condition[][],
): Condition[][] {
  return options.reduce(
    (remaining, guards) =>
      remaining.flatMap((placed) => {
        if (!possible([...placed, ...guards])) return [placed]
        const known = new Set(placed.map(conditionKey))
        const prefix = [...placed]
        const uncovered: Condition[][] = []
        for (const guard of guards) {
          const key = conditionKey(guard)
          if (known.has(key)) continue
          known.add(key)
          const alternate = [...prefix, opposite(guard)]
          if (!stableCondition(guard) || possible(alternate))
            uncovered.push(alternate)
          prefix.push(guard)
        }
        return uncovered
      }),
    [starting],
  )
}

interface Appearance {
  fill: boolean
  border: boolean
  borderless: boolean
  icon: boolean
  hidden: boolean
  concealed: boolean
  revealed: boolean
  removed: boolean
  outOfFlow: boolean
  conditions: Condition[]
}

type AppearanceEffect = Exclude<keyof Appearance, 'conditions'>

const UNKNOWN_APPEARANCE = [
  'fill',
  'border',
  'icon',
  'hidden',
  'concealed',
  'removed',
  'outOfFlow',
] as const

function uncertainStyleEffects(fact: StyleFact): readonly AppearanceEffect[] {
  const { name, overrides, spreadKeys } = fact
  if (name === 'class') return UNKNOWN_APPEARANCE
  if (name === 'style' && !overrides) return UNKNOWN_APPEARANCE
  const effects: AppearanceEffect[] = []
  const property = (key: string): boolean =>
    name === 'style' ? mayWriteStyle(fact, key) : name === 'style.' + key
  if (property('display')) effects.push('removed')
  if (property('position')) effects.push('outOfFlow')
  if (property('visibility')) effects.push('hidden')
  if (property('opacity')) effects.push('concealed')
  if (
    name.startsWith('style.border') ||
    (name === 'style' &&
      (!spreadKeys ||
        [...spreadKeys].some(
          (key) => key.startsWith('border') && property(key),
        )))
  )
    effects.push('border')
  if (property('background') || property('background-color'))
    effects.push('fill')
  return effects
}

function mayWriteStyle(fact: StyleFact, key: string): boolean {
  return (
    fact.name === 'style' &&
    Boolean(fact.unresolved) &&
    !fact.overrides?.has(key) &&
    (!fact.spreadKeys || fact.spreadKeys.has(key))
  )
}

function appearanceEffects(fact: StyleFact): AppearanceEffect[] {
  const tokens = classTokens(fact)
  const effects: AppearanceEffect[] = []
  if (tokens.includes(NEUTRAL_FILL)) effects.push('fill')
  if (
    tokens.some((token) =>
      /^(?:alert|border(?:-(?:top|bottom|start|end|[1-5]))?)$/.test(token),
    )
  )
    effects.push('border')
  if (tokens.includes('border-0')) effects.push('borderless')
  if (tokens.some((token) => /^(?:bi-|spinner-)/.test(token)))
    effects.push('icon')
  if (tokens.includes('invisible')) effects.push('hidden')
  if (tokens.includes('opacity-0') || tokens.includes('visually-hidden'))
    effects.push('concealed')
  if (tokens.some((token) => /^d-(?:(?:sm|md|lg|xl|xxl)-)?none$/.test(token)))
    effects.push('removed')
  if (
    tokens.some((token) =>
      [
        'position-absolute',
        'position-fixed',
        'visually-hidden',
        'visually-hidden-focusable',
      ].includes(token),
    )
  )
    effects.push('outOfFlow')
  for (const [name, value] of cssDeclarations(fact)) {
    if (name === 'visibility' && value === 'hidden') effects.push('hidden')
    if (name === 'visibility' && value === 'visible') effects.push('revealed')
    if (name === 'opacity' && Number(value) === 0 && value !== '')
      effects.push('concealed')
    if (name === 'display' && value === 'none') effects.push('removed')
    if (name === 'position' && ['absolute', 'fixed'].includes(value))
      effects.push('outOfFlow')
    if (
      /^border(?:-(?:top|bottom|left|right|inline|block))?(?:-style|-width)?$/.test(
        name,
      )
    ) {
      effects.push(
        /^(?:0(?:px)?|none|hidden)$/.test(value) ? 'borderless' : 'border',
      )
    }
  }
  return effects
}

function removalGuards(attr: Attribute, view: View): Condition[][] {
  const { name, expression: value } = attr
  if (name === 'v-show' && value) return [[condition(value, false)]]
  if (name !== 'hidden') return []
  if (!value) return [[]]
  if (attr.bindingType !== BindingType.Attribute) return [[condition(value)]]

  const known = staticValue(value, view)
  if (known === null || canonicalLiteral(value) === 'undefined') return []
  if (known !== undefined) return [[]]
  const present = expression(
    '(' + value.getText() + ') != null',
    templateScopes.get(value.getSourceFile()),
  )
  return present ? [[condition(present)]] : []
}

function appearances(
  element: Element,
  conditions: Condition[],
  state: EmptyState,
  visibilityOnly = false,
): Appearance[] {
  let options: Appearance[] = [
    {
      fill: false,
      border: false,
      borderless: false,
      icon: false,
      hidden: false,
      concealed: false,
      revealed: false,
      removed: false,
      outOfFlow: false,
      conditions,
    },
  ]
  const apply = (effects: AppearanceEffect[], guards: Condition[]): void => {
    if (!effects.length) return
    options = options.flatMap((option) => {
      const enabled = applyGuards(option.conditions, guards, state)
      return [
        ...withoutGuardOptions(option.conditions, enabled).map(
          (conditions) => ({
            ...option,
            conditions,
          }),
        ),
        ...enabled.map((conditions) => {
          const current = { ...option, conditions }
          for (const effect of effects) current[effect] = true
          return current
        }),
      ]
    })
  }
  for (const fact of elementStyles(element, state)) {
    const effects = appearanceEffects(fact)
    apply(
      visibilityOnly
        ? effects.filter(
            (effect) =>
              effect === 'hidden' ||
              effect === 'concealed' ||
              effect === 'revealed' ||
              effect === 'removed',
          )
        : effects,
      fact.guards ?? [],
    )
  }
  for (const attr of element.attributes)
    for (const guards of removalGuards(attr, state.view))
      apply(['removed'], guards)
  for (const option of options)
    option.hidden = option.concealed || (option.hidden && !option.revealed)
  return options
}

interface DomVisibility {
  hidden?: boolean
  unresolved?: boolean
  uncertainVisibility?: boolean
}

interface DomPath extends DomVisibility {
  conditions: Condition[]
}

function domPaths(
  element: Element,
  paths: DomPath[],
  state: EmptyState,
): DomPath[] {
  if (element.target && state.view.jsxResolve) return paths
  const uncertain = elementStyles(element, state).flatMap((fact) => {
    if (!fact.unresolved) return []
    const effects = uncertainStyleEffects(fact)
    const canReveal = fact.name !== 'class' && effects.includes('hidden')
    const canRemove =
      effects.includes('removed') || effects.includes('concealed')
    return canReveal || canRemove ? [{ fact, canReveal, canRemove }] : []
  })
  return paths.flatMap(
    ({ conditions, hidden, unresolved, uncertainVisibility }) =>
      appearances(element, conditions, state, true).flatMap((look) => {
        if (look.removed || look.concealed) return []
        let paths: DomPath[] = [
          {
            conditions: look.conditions,
            hidden: look.hidden || (hidden && !look.revealed),
            unresolved,
            uncertainVisibility:
              uncertainVisibility && !look.hidden && !look.revealed,
          },
        ]
        for (const { fact, canReveal, canRemove } of uncertain) {
          paths = paths.flatMap((path) => {
            if (!canReveal && (!canRemove || path.unresolved)) return [path]
            const affected = applyGuards(
              path.conditions,
              fact.guards ?? [],
              state,
            )
            return [
              ...withoutGuardOptions(path.conditions, affected).map(
                (conditions) => ({ ...path, conditions }),
              ),
              ...affected.map((conditions) => ({
                conditions,
                hidden: !canReveal && path.hidden,
                unresolved: path.unresolved || canRemove,
                uncertainVisibility: path.uncertainVisibility || canReveal,
              })),
            ]
          })
        }
        return paths
      }),
  )
}

function visibleConditions(
  element: Element,
  conditions: Condition[],
  state: EmptyState,
  ancestors: PlacedElement[] = [],
  inheritedHidden = false,
): Condition[][] {
  const reachable = conditions.every(resolvedGuard)
    ? [conditions]
    : applyGuards([], conditions, state)
  const paths = [...ancestors.map(({ element }) => element), element].reduce(
    (scopes, current) => domPaths(current, scopes, state),
    reachable.map((conditions): DomPath => ({
      conditions,
      hidden: inheritedHidden,
    })),
  )
  return paths.flatMap(({ conditions, hidden }) => (hidden ? [] : [conditions]))
}

interface VisibleTextPath extends DomPath {
  text: Text
}

function visibleTextPaths(
  element: Element,
  paths: DomPath[],
  state: EmptyState,
): VisibleTextPath[] {
  const walk = (nodes: RenderNode[], paths: DomPath[]): VisibleTextPath[] =>
    nodes.flatMap((node) => {
      if (node.kind === 'text')
        return paths
          .filter((path) => !path.hidden)
          .map((path) => ({
            ...path,
            text: node,
          }))
      if (node.kind === 'branch')
        return walk(
          node.children,
          paths.flatMap((path) =>
            applyGuards(path.conditions, node.conditions, state).map(
              (conditions) => ({ ...path, conditions }),
            ),
          ),
        )
      return INLINE.has(node.name) ? visibleTextPaths(node, paths, state) : []
    })
  const binding = textBinding(element)
  return walk(
    binding
      ? [{ kind: 'text', value: '', bindings: [binding] }]
      : element.children,
    domPaths(element, paths, state),
  )
}

function visibleRoomPaths(
  { element, conditions }: PlacedElement,
  state: EmptyState,
  ancestors: PlacedElement[],
  dom: DomVisibility,
  matches?: ErrorCondition,
): EvidencePaths {
  const paths = ancestors.reduce(
    (paths, { element }) => domPaths(element, paths, state),
    applyGuards([], conditions, state).map((conditions): DomPath => ({
      ...dom,
      conditions,
    })),
  )
  const result: EvidencePaths = { known: [], possible: [] }
  for (const path of visibleTextPaths(element, paths, state))
    if (
      (path.text.bindings?.length || messageText([path.text])) &&
      (!matches ||
        isRefusalMessage(
          { element, conditions: path.conditions },
          matches,
          state,
          path.text,
        ))
    )
      (path.unresolved || path.uncertainVisibility
        ? result.possible
        : result.known
      ).push(path.conditions)
  return result
}

function hasTrueAttribute(
  element: Element,
  name: string,
  conditions?: Condition[],
  state?: EmptyState,
): boolean {
  return element.attributes.some((attr) => {
    if (attr.name !== name) return false
    if (!attr.expression) return name === 'inert' || attr.literal === 'true'
    return Boolean(
      conditions &&
      state &&
      !withoutGuardOptions(
        conditions,
        applyGuards(conditions, [condition(attr.expression)], state),
      ).length,
    )
  })
}

function inertPaths(
  element: Element,
  guards: Condition[],
  state: EmptyState,
): Condition[][] {
  const inert = element.attributes.find(({ name }) => name === 'inert')
  if (!inert) return []
  return inert.expression
    ? applyGuards(guards, [condition(inert.expression)], state)
    : [guards]
}

function interactive(element: Element): boolean {
  return (
    CONTROLS.has(element.name) ||
    element.attributes.some(
      ({ name, literal }) =>
        /^(?:on[A-Z]|contenteditable$|href$)/.test(name) ||
        (name.toLowerCase() === 'tabindex' && literal !== '-1'),
    ) ||
    Boolean(element.actions?.length) ||
    role(element) === 'button' ||
    role(element) === 'link'
  )
}

/** Reads a view's actual output, with its inputs bound in the caller. */
function decorationContent(
  element: Element,
  scope: Condition[],
  state: EmptyState,
): MountedContent | null | undefined {
  const target = element.target
  if (element.unresolvedMount || (target && state.rendering?.has(target)))
    return null
  if (!target) {
    if (
      element.component !== REACT_PROVIDER &&
      (element.component || /^[A-Z]/.test(element.name)) &&
      !/^(?:React\.)?(?:Fragment|StrictMode|Suspense)$/.test(element.name)
    )
      return null
    return undefined
  }
  const inputs = mountedInputValues(element, target, state, scope)
  if (target.jsxResolve) setExplicitJsxChildren(element, state, inputs)
  return {
    nodes: renderTree(target),
    state: {
      view: target,
      mountView: state.mountView,
      mount: state.mountView(target, state.mount, element),
      rendering: new Set(state.rendering ?? [state.view]).add(target),
      knownJsxInputs: !element.unknownInputs,
      inputs,
      outlets: mountedOutlets(element, target, state, scope),
      twin: state.twin,
    },
  }
}

function inDecorationScope(node: Element): boolean {
  return elementContext(node, 'modal') === 'modal' && node.component !== MODAL
}

function decorationOutput(
  node: Element | Text,
  guards: Condition[],
  state: EmptyState,
): RenderedPath[] | null | undefined {
  const withTwin = (origin: EmptyState): EmptyState =>
    origin.twin === state.twin ? origin : { ...origin, twin: state.twin }
  if (node.kind === 'text')
    return renderedJsxInput(node, state, guards)?.map(({ state, ...path }) => ({
      ...path,
      state: withTwin(state),
    }))
  if (isContentOutlet(node, state.view))
    return outletPaths(node, state, state.outlets, guards).map(
      ({ projected, guards, unresolved }) => ({
        nodes: projected?.nodes ?? node.children,
        state: projected ? withTwin(projected.state) : state,
        guards,
        unresolved,
      }),
    )
  const output = decorationContent(node, guards, state)
  return output && [{ ...output, guards }]
}

function interactivePaths(
  nodes: RenderNode[],
  conditions: Condition[],
  state: EmptyState,
): EvidencePaths {
  const result: EvidencePaths = { known: [], possible: [] }
  const walk = (
    nodes: RenderNode[],
    conditions: Condition[],
    state: EmptyState,
    uncertain: boolean,
  ): void => {
    for (const node of nodes) {
      if (node.kind === 'branch') {
        for (const scope of applyGuards(conditions, node.conditions, state))
          walk(node.children, scope, state, uncertain)
        continue
      }
      if (node.kind === 'element' && !inDecorationScope(node)) continue
      const content = decorationOutput(node, conditions, state)
      if (content !== undefined) {
        for (const path of content ?? [])
          walk(
            path.nodes,
            path.guards,
            path.state,
            uncertain || Boolean(path.unresolved),
          )
        continue
      }
      if (node.kind === 'text') continue
      for (const look of appearances(node, conditions, state)) {
        if (look.removed) continue
        const enabled = withoutGuardOptions(
          look.conditions,
          inertPaths(node, look.conditions, state),
        )
        const possibleControl =
          uncertain ||
          uncertainAppearance(node, look.conditions, state).has('removed')
        if (interactive(node))
          (possibleControl ? result.possible : result.known).push(...enabled)
        else if (!textBinding(node))
          for (const guards of enabled)
            walk(node.children, guards, state, possibleControl)
      }
    }
  }
  walk(nodes, conditions, state, false)
  return result
}

function boundText(element: Element, view: View): string | undefined {
  const value = textBinding(element)
  if (!value) return undefined
  const literal = staticValue(value, view)
  if (literal === null) return ''
  return literal === undefined ? undefined : String(literal)
}

function textPresencePaths(
  node: Text,
  conditions: Condition[],
  state: EmptyState,
  sizing = false,
): EvidencePaths {
  const hasText = (text: string): boolean =>
    sizing
      ? /[^ \t\r\n]/.test(text)
      : Boolean(text.replace(/(?:&nbsp;|&#160;)/gi, ' ').trim())
  if (hasText(node.literalText ?? (node.bindings ? '' : node.value)))
    return { known: [conditions], possible: [] }
  const result: EvidencePaths = { known: [], possible: [] }
  for (const binding of node.bindings ?? []) {
    const paths =
      state.inputs && jsxInputReference(binding, state.view)
        ? jsxValuePaths(binding, state, conditions)
        : [{ input: { value: binding, state }, guards: conditions }]
    for (const { input, guards, unresolved } of paths) {
      if (!input || unresolved) {
        result.possible.push(guards)
        continue
      }
      const value = mountedScalar(input.value, input.state)
      if (value === undefined) {
        if (fixedRefusalValue(input.value, input.state.view))
          result.known.push(guards)
        else if (!unshadowed(input.value, input.state.view, 'undefined'))
          result.possible.push(guards)
      } else if (
        value !== null &&
        (!input.state.view.jsxResolve || typeof value !== 'boolean') &&
        hasText(String(value))
      )
        result.known.push(guards)
    }
  }
  result.possible = result.possible.flatMap((path) =>
    withoutGuardOptions(path, result.known),
  )
  return result
}

function boundTextNode(element: Element): Text | undefined {
  const binding = textBinding(element)
  return binding && { kind: 'text', value: '', bindings: [binding] }
}

function uncertainAppearance(
  element: Element,
  scope: Condition[],
  state: EmptyState,
): Set<AppearanceEffect> {
  const effects = new Set<AppearanceEffect>(
    element.unknownInputs ? UNKNOWN_APPEARANCE : undefined,
  )
  for (const fact of elementStyles(element, state)) {
    if (
      !fact.unresolved ||
      !applyGuards(scope, fact.guards ?? [], state).length
    )
      continue
    for (const effect of uncertainStyleEffects(fact)) effects.add(effect)
  }
  return effects
}

function uncertainLayout(effects: Set<AppearanceEffect>): boolean {
  return effects.has('removed') || effects.has('outOfFlow')
}

interface EvidencePaths {
  known: Condition[][]
  possible: Condition[][]
}

function sizingContent(
  nodes: RenderNode[],
  conditions: Condition[],
  state: EmptyState,
): EvidencePaths {
  const result: EvidencePaths = { known: [], possible: [] }
  for (const node of nodes) {
    if (node.kind === 'branch') {
      for (const scope of applyGuards(conditions, node.conditions, state))
        add(sizingContent(node.children, scope, state), false)
      continue
    }
    if (node.kind === 'element' && !inDecorationScope(node)) continue
    const content = decorationOutput(node, conditions, state)
    if (content !== undefined) {
      if (content)
        for (const { nodes, guards, state, unresolved } of content)
          add(sizingContent(nodes, guards, state), Boolean(unresolved))
      else result.possible.push(conditions)
      continue
    }
    if (node.kind === 'text') {
      add(textPresencePaths(node, conditions, state, true), false)
      continue
    }
    for (const look of appearances(node, conditions, state)) {
      if (look.removed || look.outOfFlow) continue
      const scope = look.conditions
      const uncertain = uncertainLayout(uncertainAppearance(node, scope, state))
      const text = boundTextNode(node)
      if (text) add(textPresencePaths(text, scope, state, true), uncertain)
      else if (/^(?:br|img|svg)$/.test(node.name))
        (uncertain ? result.possible : result.known).push(scope)
      else add(sizingContent(node.children, scope, state), uncertain)
    }
  }
  return result

  function add(paths: EvidencePaths, uncertain: boolean): void {
    result.possible.push(...paths.possible)
    const known = uncertain ? result.possible : result.known
    known.push(...paths.known)
  }
}

interface Decoration {
  twins: EvidencePaths
  fills: Condition[][]
  misplacedFill: boolean
  furnished: boolean
  fillWithoutTwin: boolean
  unresolved: boolean
}

function decorationVisibility(
  look: Appearance,
  effects: Set<AppearanceEffect>,
  inherited: DomVisibility,
): DomVisibility {
  return {
    hidden:
      !effects.has('hidden') &&
      (look.hidden || (inherited.hidden && !look.revealed)),
    unresolved:
      inherited.unresolved ||
      effects.has('removed') ||
      effects.has('concealed'),
    uncertainVisibility:
      effects.has('hidden') ||
      (inherited.uncertainVisibility && !look.hidden && !look.revealed),
  }
}

function emptyDecoration(
  nodes: RenderNode[],
  conditions: Condition[],
  mounted: EmptyState,
  inFlow = true,
  inherited: DomVisibility = {},
): Decoration {
  const result: Decoration = {
    twins: { known: [], possible: [] },
    fills: [],
    misplacedFill: false,
    furnished: false,
    fillWithoutTwin: false,
    unresolved: false,
  }
  const recordText = (
    node: Text,
    scope: Condition[],
    dom: DomVisibility,
    state: EmptyState,
  ): void => {
    if (dom.hidden) return
    const presence = textPresencePaths(node, scope, state)
    const uncertain = dom.unresolved || dom.uncertainVisibility
    result.furnished ||= !uncertain && presence.known.length > 0
    result.unresolved ||=
      presence.possible.length > 0 ||
      Boolean(uncertain && presence.known.length)
  }
  for (const node of nodes) {
    const state = decorationState(node, mounted)
    if (node.kind === 'branch') {
      for (const scope of applyGuards(conditions, node.conditions, state))
        merge(emptyDecoration(node.children, scope, state, inFlow, inherited))
      continue
    }
    if (node.kind === 'element' && !inDecorationScope(node)) continue
    const content = decorationOutput(node, conditions, state)
    if (content !== undefined) {
      if (content)
        for (const { nodes, guards, state, unresolved } of content) {
          const output = emptyDecoration(
            nodes,
            guards,
            state,
            inFlow,
            inherited,
          )
          result.unresolved ||= Boolean(
            unresolved &&
            (output.furnished ||
              output.fills.length ||
              output.twins.known.length),
          )
          merge(output, unresolved, unresolved)
        }
      else if (!inherited.hidden) {
        result.unresolved = true
        if (inFlow && !state.twin) {
          result.fills.push(conditions)
          result.twins.possible.push(conditions)
        }
      }
      continue
    }
    if (node.kind === 'text') {
      recordText(node, conditions, inherited, state)
      continue
    }
    for (const look of appearances(node, conditions, state)) {
      const scope = look.conditions
      const flow = inFlow && !look.outOfFlow && !look.removed
      if (look.removed) continue
      const effects = uncertainAppearance(node, scope, state)
      const dom = decorationVisibility(look, effects, inherited)
      const { hidden } = dom
      const layoutUnknown = uncertainLayout(effects)
      const visibilityUnknown = dom.unresolved || dom.uncertainVisibility
      if (look.fill && (!flow || hidden) && !visibilityUnknown)
        result.misplacedFill = true
      const inert = !withoutGuardOptions(scope, inertPaths(node, scope, state))
        .length
      const qualifies =
        flow &&
        (!state.twin || state.twin === node) &&
        (inert ||
          (hasTrueAttribute(node, 'aria-hidden', scope, state) &&
            !interactive(node)))
      if (
        qualifies &&
        (hidden || effects.has('hidden') || effects.has('concealed'))
      ) {
        const controls = inert
          ? { known: [], possible: [] }
          : interactivePaths(node.children, scope, state)
        const safe = withoutGuardOptions(scope, controls.known)
        for (const path of safe) {
          const sizing = sizingContent([node], path, state)
          const uncertain = controls.possible.flatMap((guards) => {
            const combined = [...path, ...guards]
            return possible(combined) ? [combined] : []
          })
          if (layoutUnknown || !hidden)
            result.twins.possible.push(...sizing.known)
          else
            for (const sized of sizing.known) {
              result.twins.known.push(...withoutGuardOptions(sized, uncertain))
              for (const guards of uncertain) {
                const combined = [...sized, ...guards]
                if (possible(combined)) result.twins.possible.push(combined)
              }
            }
          result.twins.possible.push(...sizing.possible)
          result.unresolved ||=
            uncertain.length > 0 || sizing.possible.length > 0
        }
      }
      if (look.concealed) {
        result.unresolved ||= qualifies && layoutUnknown
        continue
      }

      if (!hidden) {
        const furnished =
          (look.border && !look.borderless && !effects.has('border')) ||
          (look.icon && !effects.has('icon')) ||
          /^(?:svg|img|canvas|hr)$/.test(node.name) ||
          interactive(node)
        const bound = boundTextNode(node)
        result.unresolved ||=
          [...effects].some((effect) => effect !== 'hidden') ||
          Boolean(visibilityUnknown && (look.fill || furnished))
        result.furnished ||= !visibilityUnknown && furnished
        if (bound) recordText(bound, scope, dom, state)
      }
      const children = emptyDecoration(
        textBinding(node) ? [] : node.children,
        scope,
        state,
        flow,
        dom,
      )
      if (flow && !hidden) {
        const twins = [...children.twins.known, ...children.twins.possible]
        if (look.fill) {
          result.fills.push(scope)
          if (!visibilityUnknown && !layoutUnknown)
            result.fillWithoutTwin ||=
              withoutGuardOptions(scope, twins).length > 0
        } else if (effects.has('fill')) result.fills.push(...twins)
      }
      merge(children, false, layoutUnknown)
    }
  }
  return result

  function merge(
    other: Decoration,
    uncertainVisibility = false,
    uncertainFlow = false,
  ): void {
    if (uncertainFlow) result.twins.possible.push(...other.twins.known)
    else result.twins.known.push(...other.twins.known)
    result.twins.possible.push(...other.twins.possible)
    result.fills.push(...other.fills)
    result.misplacedFill ||=
      !uncertainVisibility && !uncertainFlow && other.misplacedFill
    result.furnished ||= !uncertainVisibility && other.furnished
    result.fillWithoutTwin ||=
      !uncertainVisibility && !uncertainFlow && other.fillWithoutTwin
    result.unresolved ||= other.unresolved
  }
}

function decorationState(node: RenderNode, state: EmptyState): EmptyState {
  const origin = node.kind === 'element' && state.origins?.get(node)
  return origin
    ? { ...origin, origins: state.origins, twin: state.twin }
    : state
}

function hasNeutralFill(
  nodes: RenderNode[],
  conditions: Condition[],
  mounted: EmptyState,
  unresolved: () => void,
  inherited: DomVisibility = {},
): boolean {
  return nodes.some((node) => {
    const state = decorationState(node, mounted)
    if (node.kind === 'branch') {
      return applyGuards(conditions, node.conditions, state).some((scope) =>
        hasNeutralFill(node.children, scope, state, unresolved, inherited),
      )
    }
    if (node.kind === 'element' && !inDecorationScope(node)) return false
    const content = decorationOutput(node, conditions, state)
    if (content === null) {
      if (!inherited.hidden) unresolved()
      return false
    }
    if (content)
      return content.some(({ nodes, guards, state, unresolved: possible }) =>
        hasNeutralFill(nodes, guards, state, unresolved, {
          ...inherited,
          unresolved: inherited.unresolved || possible,
        }),
      )
    if (node.kind === 'text') return false
    return appearances(node, conditions, state).some((look) => {
      if (look.removed || look.concealed) return false
      const effects = uncertainAppearance(node, look.conditions, state)
      const dom = decorationVisibility(look, effects, inherited)
      const { hidden } = dom
      const uncertain = dom.unresolved || dom.uncertainVisibility
      // The canonical nonmodal twin must also leave the modal fill off its own row.
      const nonmodalTwin =
        !inherited.hidden &&
        state.canonical === 'form' &&
        conditions.some(
          ({ value, truthy }) => value === MODAL_STATE.value && !truthy,
        ) &&
        hasTrueAttribute(node, 'aria-hidden') &&
        elementStyles(node, state).some((fact) =>
          classTokens(fact).includes('invisible'),
        )
      if (look.fill && (!hidden || nonmodalTwin)) {
        if (!uncertain && !effects.has('fill')) return true
        unresolved()
      }
      return hasNeutralFill(
        textBinding(node) ? [] : node.children,
        look.conditions,
        state,
        unresolved,
        dom,
      )
    })
  })
}

function emptyViolation(
  nodes: RenderNode[],
  empty: Condition[][],
  active: Condition[][],
  state: EmptyState,
  unresolved: () => void,
  inherited: DomVisibility = {},
): string | undefined {
  if (
    active.some((scope) =>
      hasNeutralFill(nodes, scope, state, unresolved, inherited),
    )
  )
    return EMPTY_FILL
  for (const scope of empty) {
    const appearance = emptyDecoration(nodes, scope, state, true, inherited)
    if (appearance.unresolved) unresolved()
    if (
      appearance.misplacedFill ||
      withoutGuardOptions(scope, appearance.fills).length
    )
      return EMPTY_FILL
    if (appearance.furnished || appearance.fillWithoutTwin) return EMPTY_TWIN
  }
  return undefined
}

function canonicalEmptyViolation(
  view: View,
  mountView: MountView,
  unresolved: (offset: number) => void,
): { offset: number; message: string } | undefined {
  const roots = renderTree(view)
  const row = siblingElements(roots).find(
    ({ element }) => element.component !== MODAL,
  )
  const offset = row?.element.offset ?? view.offset
  const message = emptyViolation(
    roots,
    [[opposite(REFUSAL_STATE), MODAL_STATE]],
    [[REFUSAL_STATE], [opposite(MODAL_STATE)]],
    { view, mountView, mount: mountView(view), canonical: 'form' },
    () => unresolved(offset),
  )
  return message ? { offset, message } : undefined
}

function overlayAnchor(
  element: Element,
  conditions: Condition[],
  state: EmptyState,
): string {
  const anchor = new Set<string>()
  for (const fact of elementStyles(element, state)) {
    const enabled = applyGuards(conditions, fact.guards ?? [], state)
    if (withoutGuardOptions(conditions, enabled).length) continue
    for (const token of classTokens(fact)) {
      if (
        /^(?:position-(?:absolute|fixed)|(?:top|bottom|start|end)-\S+|translate-middle(?:-[xy])?)$/.test(
          token,
        )
      )
        anchor.add(token)
    }
    for (const [name, value] of cssDeclarations(fact)) {
      if (
        /^(?:top|bottom|left|right|inset(?:-[\w-]+)?|transform)$/.test(name) ||
        (name === 'position' && ['absolute', 'fixed'].includes(value))
      )
        anchor.add(name + '=' + value)
    }
  }
  return [...anchor].sort().join(' ')
}

function hintConditions(
  siblings: PlacedElement[],
  room: ReservedRoom,
  state: EmptyState,
  dom: DomVisibility,
): EvidencePaths {
  const hints: EvidencePaths = { known: [], possible: [] }
  if (!room.overlays?.length) return hints
  for (const { element, conditions, state: origin = state } of siblings) {
    if (
      element === room.reserve.element ||
      element === room.wrapper?.element ||
      room.peers.some((peer) => peer.element === element)
    )
      continue
    for (const scope of applyGuards([], conditions, origin)) {
      if (otherMessage(element, scope, origin)) continue
      for (const look of appearances(element, scope, origin)) {
        if (!look.outOfFlow || look.removed) continue
        const anchor = overlayAnchor(element, look.conditions, origin)
        if (
          !room.overlays.some(
            (overlay) =>
              anchor === overlay.anchor &&
              refusalTransition(
                look.conditions,
                overlay.conditions,
                overlay.refusal,
              )?.switches,
          )
        )
          continue
        for (const path of visibleTextPaths(
          element,
          [{ ...dom, conditions: look.conditions }],
          origin,
        )) {
          const text = textPresencePaths(path.text, path.conditions, origin)
          const evidence =
            path.unresolved || path.uncertainVisibility
              ? hints.possible
              : hints.known
          evidence.push(...text.known)
          hints.possible.push(...text.possible)
        }
      }
    }
  }
  return hints
}

function localEmptyViolation(
  room: ReservedRoom,
  siblings: PlacedElement[],
  parent: PlacedElement | undefined,
  mounted: EmptyState,
  domain: Condition[],
  unresolved: () => void,
  dom: DomVisibility,
  alternateDom?: DomPath[],
): string | undefined {
  const { reserve, wrapper } = room
  const origins = new Map(
    siblings.flatMap(({ element, state }) =>
      state && state !== mounted ? [[element, state] as const] : [],
    ),
  )
  const state = { ...mounted, twin: reserve.element, origins }
  const hints = hintConditions(siblings, room, state, dom)
  const occupied = distinctPaths([...room.active.known, ...hints.known])
  const uncertain = distinctPaths([...room.active.possible, ...hints.possible])
  const empty =
    room.vacancy ??
    room.service.flatMap((scope) => withoutGuardOptions(scope, occupied))
  // An alternate DOM path must establish its own reserve and refusal link.
  const alternateVacancy = alternateDom?.some(
    (path) =>
      !path.hidden &&
      (path.unresolved || path.uncertainVisibility) &&
      path.conditions !== domain &&
      withoutGuardOptions(path.conditions, occupied).some((scope) =>
        room.reserveIn?.(path.conditions, scope),
      ),
  )
  if (
    alternateVacancy ||
    empty.some((scope) =>
      uncertain.some((path) => possible([...scope, ...path])),
    )
  )
    unresolved()
  const checkable = empty.flatMap((scope) =>
    withoutGuardOptions(scope, uncertain),
  )

  const reserveNode = wrapper ?? reserve
  const members = new Set([
    reserveNode.element,
    ...room.peers.map(({ element }) => element),
  ])
  const painted =
    !wrapper && parent
      ? elementStyles(parent.element, parent.state ?? mounted)
          .filter((fact) => classTokens(fact).includes(NEUTRAL_FILL))
          .flatMap((fact) =>
            applyGuards(domain, fact.guards ?? [], parent.state ?? mounted),
          )
      : []
  const children = siblings.flatMap(
    ({ element, conditions, state = mounted }) =>
      members.has(element)
        ? [branch(conditions, [element])]
        : painted.flatMap((scope) =>
            applyGuards(scope, conditions, state).map((guards) =>
              branch(guards, [element]),
            ),
          ),
  )
  const container = parent?.element
  const withinWrapper =
    container &&
    !container.component &&
    !container.target &&
    /^(?:div|section|aside|article|p|span)$/.test(container.name)
  const slot = container && stableId(container)
  const dedicated =
    slot &&
    [reserve, ...room.peers].some(
      ({ element }) =>
        stableId(element) && roomId(stableId(element)) === roomId(slot),
    )
  if (parent?.state && parent.state !== mounted)
    origins.set(parent.element, parent.state)
  const nodes = withinWrapper
    ? [
        {
          ...container,
          children: dedicated && !origins.size ? container.children : children,
        },
      ]
    : children
  if (parent?.state && parent.state !== mounted && withinWrapper)
    origins.set(nodes[0] as Element, parent.state)
  return emptyViolation(nodes, checkable, occupied, state, unresolved, dom)
}

type RuleId = typeof MODAL_ERROR_ROOM_RULE_ID | typeof MODAL_ERROR_EMPTY_RULE_ID
type Locations = Map<string, Map<number, Map<RuleId, string>>>

interface ModalScan {
  violations: string[]
  manualReview: string[]
  modalBodyVisits: number
  reserveAnalyses: number
}

interface DomParent {
  parent?: PlacedElement
  regions: ReserveRegion[]
}

interface ReserveRegion {
  state: EmptyState
  siblings: PlacedElement[]
  batch: PlacedElement[]
  placement: DomParent
  guards: Condition[]
  dom: DomVisibility
  alternateDom?: DomPath[]
  unresolvedMount: boolean
}

type WalkNodes = (
  nodes: RenderNode[],
  context: Context,
  parent: Element | undefined,
  conditions: Condition[],
  visible: DomPath[],
  unresolvedMount?: boolean,
  placement?: DomParent,
) => void

interface ProjectedNodes extends MountedOutlet {
  walk: WalkNodes
}

const REACT_PROVIDER = 'React.Context.Provider'
const UNKNOWN_VUE_SLOT = Symbol()

type Projection = Map<string | number | symbol, ProjectedNodes>

interface SlotSelection {
  nodes: RenderNode[]
  guards: Condition[]
}

interface SlotContent {
  nodes: RenderNode[]
  present: Condition[][]
  possiblePresence?: Condition[][]
  possibleAbsence?: Condition[][]
  competitors?: ReadonlyMap<string, SlotContent>
  suppliers?: SlotSelection[]
}

interface NamedSlot<K> {
  key?: K
  guards: Condition[]
}

interface SlotDeclaration<K> extends NamedSlot<K> {
  nodes: RenderNode[]
  dynamic: boolean
}

function slotDeclarations<K extends string | symbol>(
  nodes: RenderNode[],
  name: (node: Element, guards: Condition[]) => NamedSlot<K>[],
): SlotDeclaration<K>[] {
  const declarations: SlotDeclaration<K>[] = []
  const collect = (items: RenderNode[], guards: Condition[]): void => {
    for (const item of items) {
      if (item.kind === 'branch') {
        collect(item.children, [...guards, ...item.conditions])
        continue
      }
      if (item.kind === 'text' && !/[^ \t\r\n]/.test(item.value)) continue
      const names = item.kind === 'element' ? name(item, guards) : [{ guards }]
      for (const entry of names) {
        const explicit = entry.key !== undefined && item.kind === 'element'
        declarations.push({
          ...entry,
          nodes: explicit ? item.children : [item],
          dynamic: explicit && guards.length > 0,
        })
      }
    }
  }
  collect(nodes, [])
  return declarations
}

function namedSlotContent<K extends string | symbol>(
  nodes: RenderNode[],
  name: (node: Element, guards: Condition[]) => NamedSlot<K>[],
): Map<K | 'default', SlotContent> {
  const result = new Map<K | 'default', SlotContent>()
  for (const { key, guards, nodes: children } of slotDeclarations(
    nodes,
    name,
  )) {
    const slotName = key ?? 'default'
    const content = result.get(slotName) ?? { nodes: [], present: [] }
    content.nodes.push(
      ...(guards.length ? [branch(guards, children)] : children),
    )
    content.present.push(guards)
    result.set(slotName, content)
  }
  return result
}

function slotNamePaths(
  value: ts.Expression,
  state: EmptyState,
  guards: Condition[],
  seen = new Set<ts.Expression>(),
): NamedSlot<string>[] {
  value = transparent(value)
  if (seen.has(value) || seen.size >= 32) return [{ guards }]
  seen = new Set(seen).add(value)
  const scalar = mountedScalar(value, state)
  if (typeof scalar === 'string') return [{ key: scalar, guards }]
  const input = mountedValue(value, state)
  if (input) return slotNamePaths(input.value, input.state, guards, seen)
  const choice = jsxChoice(value)
  if (choice) {
    const paths = choice.flatMap(([test, arm]) =>
      applyGuards(guards, [test], state).flatMap((path) =>
        slotNamePaths(arm, state, path, seen),
      ),
    )
    const key = paths[0]?.key
    return key !== undefined && paths.every((path) => path.key === key)
      ? [{ key, guards }]
      : paths
  }
  const alias =
    templateLocal(value)?.value ??
    immutableInitializer(stateBinding(value, state.view))
  return alias ? slotNamePaths(alias, state, guards, seen) : [{ guards }]
}

function vueSlotContent(
  nodes: RenderNode[],
  state: EmptyState,
  domain: Condition[],
): Map<string | typeof UNKNOWN_VUE_SLOT, SlotContent> {
  type Key = string | typeof UNKNOWN_VUE_SLOT
  const declarations = slotDeclarations<Key>(nodes, (node, guards) => {
    if (node.vueSlotName)
      return applyGuards([], guards, state).flatMap((path) =>
        slotNamePaths(node.vueSlotName!, state, path).flatMap(
          ({ key, guards }) =>
            key === '' ? [] : [{ key: key ?? UNKNOWN_VUE_SLOT, guards }],
        ),
      )
    return [
      {
        key: node.name.startsWith('#') ? node.name.slice(1) : undefined,
        guards,
      },
    ]
  })
  const selected = new Map<Key, SlotSelection[]>()
  const competitors = new Map<string, SlotSelection[]>()
  const replacements = new Map<string, Condition[][]>()
  const implicit = declarations.flatMap(({ key, guards, nodes }) =>
    key === undefined ? (guards.length ? [branch(guards, nodes)] : nodes) : [],
  )
  const named = declarations.filter(({ key }) => key !== undefined)
  // Unconditional names are properties in source order. Vue appends one
  // implicit default renderer before installing conditional slots.
  for (const { key, guards, nodes } of [
    ...named.filter(({ dynamic }) => !dynamic),
    ...(implicit.length
      ? [{ key: 'default', guards: [], nodes: implicit }]
      : []),
    ...named.filter(({ dynamic }) => dynamic),
  ]) {
    const name = key ?? 'default'
    const reachable = applyGuards(domain, guards, state)
    if (!reachable.length) continue
    const assignments = reachable.map((guards) => ({ nodes, guards }))
    const previous = selected.get(name) ?? []
    const unassigned = (parts: SlotSelection[]): SlotSelection[] =>
      parts.flatMap((part) =>
        withoutGuardOptions(part.guards, reachable).map((guards) => ({
          ...part,
          guards,
        })),
      )
    if (name === UNKNOWN_VUE_SLOT) {
      selected.set(name, [...previous, ...assignments])
      const empty = reachable.flatMap((path) =>
        withoutGuardOptions(path, vueSlotPresence(nodes, state, path).known),
      )
      for (const [known, prior] of competitors) {
        competitors.set(known, [...prior, ...assignments])
        if (empty.length)
          replacements.set(known, [
            ...(replacements.get(known) ?? []),
            ...empty,
          ])
      }
      continue
    }
    competitors.set(
      name,
      unassigned(competitors.get(name) ?? selected.get(UNKNOWN_VUE_SLOT) ?? []),
    )
    const replacement = replacements.get(name)
    if (replacement)
      replacements.set(
        name,
        replacement.flatMap((path) => withoutGuardOptions(path, reachable)),
      )
    selected.set(name, [...unassigned(previous), ...assignments])
  }

  const content = (parts: SlotSelection[]): SlotContent => {
    const children = parts.flatMap(({ nodes, guards }) =>
      guards.length === domain.length
        ? nodes
        : [branch(guards.slice(domain.length), nodes)],
    )
    const { known, possible } = vueSlotPresence(children, state, domain)
    const possiblePresence = possible.flatMap((guards) =>
      withoutGuardOptions(guards, known),
    )
    return { nodes: children, present: known, possiblePresence }
  }
  const unknownContent = (parts: SlotSelection[]): SlotContent => ({
    ...content(parts),
    suppliers: parts,
  })
  const slots = new Map<Key, SlotContent>()
  for (const [name, parts] of selected) {
    const slot = content(parts)
    if (name === UNKNOWN_VUE_SLOT) {
      if (slot.present.length || slot.possiblePresence?.length)
        slots.set(name, {
          ...slot,
          suppliers: parts,
          competitors: new Map(
            [...competitors].map(([key, parts]) => [
              key,
              unknownContent(parts),
            ]),
          ),
        })
    } else {
      const replacement = replacements.get(name)
      slots.set(name, {
        ...slot,
        possibleAbsence:
          replacement &&
          slot.present.flatMap((path) => coveredPaths(path, replacement)),
      })
    }
  }
  return slots
}

interface MountedOutlet extends Partial<Omit<SlotContent, 'nodes'>> {
  nodes: RenderNode[]
  state: EmptyState
}

function mountedOutlets(
  element: Element,
  target: View,
  state: EmptyState,
  domain: Condition[],
): Map<string | number | symbol, MountedOutlet> {
  const outlets = new Map<string | number | symbol, MountedOutlet>()
  if (target.path.endsWith('.vue')) {
    for (const [name, content] of vueSlotContent(
      element.children,
      state,
      domain,
    ))
      outlets.set(name, { ...content, state })
  } else if (target.contentOutlets) {
    renderTree(target)
    for (const [outlet, nodes] of angularProjection(
      element.children,
      target.contentOutlets,
    ))
      outlets.set(outlet, { nodes, state })
  }
  return outlets
}

function isContentOutlet(element: Element, view: View): boolean {
  return (
    element.name === 'ng-content' ||
    (element.name === 'slot' && Boolean(view.vueProps))
  )
}

function coveredPaths(
  domain: Condition[],
  occupied: Condition[][],
): Condition[][] {
  return withoutGuardOptions(domain, withoutGuardOptions(domain, occupied))
}

function slotCompetitor<T extends Partial<SlotContent>>(
  unknown: T | undefined,
  key: string,
): T | undefined {
  const competitor = unknown?.competitors?.get(key)
  return competitor && unknown ? { ...unknown, ...competitor } : unknown
}

function outletPaths<T extends MountedOutlet>(
  element: Element,
  state: EmptyState,
  projection: ReadonlyMap<string | number | symbol, T> | undefined,
  reachable: Condition[],
): { projected?: T; guards: Condition[]; unresolved?: true }[] {
  if (element.name === 'ng-content')
    return [{ projected: projection?.get(element.offset), guards: reachable }]
  const name = element.attributes.find(({ name }) => name === 'name')
  const names = name?.expression
    ? slotNamePaths(name.expression, state, reachable)
    : [{ key: name?.literal ?? 'default', guards: reachable }]
  return names.flatMap(({ key, guards }) => {
    type OutletPath = {
      projected?: T
      guards: Condition[]
      unresolved?: true
    }
    const available = (slot: T): Condition[][] => [
      ...(slot.present ?? []),
      ...(slot.possiblePresence ?? []),
    ]
    const candidates =
      key === undefined
        ? [...(projection?.values() ?? [])]
        : [slotCompetitor(projection?.get(UNKNOWN_VUE_SLOT), key || 'default')]
    const competitors = candidates.flatMap((projected): OutletPath[] =>
      projected
        ? coveredPaths(guards, available(projected)).map((guards) => ({
            projected,
            guards,
            unresolved: true,
          }))
        : [],
    )
    const select = (domain: Condition[], unresolved?: true): OutletPath[] => {
      const selected =
        key === undefined ? undefined : projection?.get(key || 'default')
      const present = selected?.present ?? []
      const possible = selected?.possiblePresence ?? []
      const possiblyEmpty = selected?.possibleAbsence ?? []
      const uncertain = coveredPaths(domain, [...possible, ...possiblyEmpty])
      return [
        ...coveredPaths(domain, present)
          .flatMap((path) => withoutGuardOptions(path, possiblyEmpty))
          .map((guards) => ({
            projected: selected,
            guards,
            unresolved,
          })),
        ...uncertain.flatMap((guards) => [
          { projected: selected, guards, unresolved: true as const },
          { guards, unresolved: true as const },
        ]),
        ...withoutGuardOptions(domain, [...present, ...possible]).map(
          (guards) => ({ guards, unresolved }),
        ),
      ]
    }
    const competingPaths = new Map(
      competitors.map(({ guards }) => [guardPathKey(guards), guards]),
    )
    return [
      ...withoutGuardOptions(guards, [...competingPaths.values()]).flatMap(
        (guards) => select(guards),
      ),
      ...competitors,
      ...[...competingPaths.values()].flatMap((guards) => select(guards, true)),
    ]
  })
}

function unresolvedProjection(
  nodes: RenderNode[],
  offset: number,
): RenderNode[] {
  return nodes.length
    ? [{ ...slot('unresolved-render', offset, nodes), unresolvedMount: true }]
    : []
}

function vueSlotPresence(
  nodes: RenderNode[],
  state: EmptyState,
  guards: Condition[],
): EvidencePaths {
  const result: EvidencePaths = { known: [], possible: [] }
  for (const node of nodes) {
    if (node.kind === 'branch') {
      for (const path of applyGuards(guards, node.conditions, state)) {
        const evidence = vueSlotPresence(node.children, state, path)
        result.known.push(...evidence.known)
        result.possible.push(...evidence.possible)
      }
    } else if (node.kind === 'element' && isContentOutlet(node, state.view)) {
      const occupied: Condition[][] = []
      const missing: Condition[][] = []
      for (const path of outletPaths(node, state, state.outlets, guards)) {
        // A supplier is selected only if its Fragment contains a valid VNode.
        const evidence = path.projected
          ? { known: [path.guards], possible: [] }
          : vueSlotPresence(node.children, state, path.guards)
        occupied.push(...evidence.known, ...evidence.possible)
        missing.push(...withoutGuardOptions(path.guards, evidence.known))
      }
      // The identity of the selected supplier can vary while occupancy is fixed.
      result.known.push(
        ...occupied.flatMap((path) => withoutGuardOptions(path, missing)),
      )
      result.possible.push(...occupied)
    } else {
      // Comments and formatting whitespace were removed by the compiler.
      result.known.push(guards)
    }
  }
  return result
}

function selectedSlot(
  body: SlotContent | undefined,
  fallback: RenderNode[],
  state: EmptyState,
): RenderNode[] {
  if (!body) return fallback
  const mounted = body.present.flatMap((guards) =>
    applyGuards([], guards, state),
  )
  return [
    ...body.nodes,
    ...withoutGuardOptions(
      [],
      [...mounted, ...(body.possiblePresence ?? [])],
    ).map((guards) => branch(guards, fallback)),
    ...(body.possiblePresence ?? []).flatMap((guards) =>
      fallback.length
        ? [branch(guards, unresolvedProjection(fallback, state.view.offset))]
        : [],
    ),
  ]
}

interface SlotPath {
  guards: Condition[]
  availability?: SlotAvailability
}

function isTruthyJsxValue(value: ts.Expression): boolean {
  return (
    ts.isJsxElement(value) ||
    ts.isJsxSelfClosingElement(value) ||
    ts.isJsxFragment(value) ||
    ts.isArrowFunction(value) ||
    ts.isFunctionExpression(value) ||
    ts.isArrayLiteralExpression(value) ||
    ts.isObjectLiteralExpression(value)
  )
}

function jsxSlotPaths(
  value: ts.Expression,
  state: EmptyState,
  domain: Condition[],
  seen = new Set<ts.Expression>(),
): SlotPath[] {
  value = transparent(value)
  if (seen.has(value) || seen.size >= 32) return [{ guards: domain }]
  seen = new Set(seen).add(value)
  const { view } = state
  if (state.inputs && jsxInputReference(value, view))
    return jsxValuePaths(value, state, domain).flatMap(
      ({ input, guards, unresolved }) => {
        if (unresolved || !input) return [{ guards }]
        return input.nodes
          ? [{ guards, availability: 'truthy' }]
          : jsxSlotPaths(input.value, input.state, guards, seen)
      },
    )
  const input = mountedValue(value, state)
  if (input)
    return input.availability
      ? [{ guards: domain, availability: input.availability }]
      : jsxSlotPaths(input.value, input.state, domain, seen)
  if (unshadowed(value, view, 'undefined'))
    return [{ guards: domain, availability: 'absent' }]
  if (isTruthyJsxValue(value))
    return [{ guards: domain, availability: 'truthy' }]
  const known = staticValue(value, view)
  if (known !== undefined)
    return [
      {
        guards: domain,
        availability: known === null ? 'null' : known ? 'truthy' : 'falsy',
      },
    ]
  const choice = jsxValueChoice(value)
  if (choice) {
    const paths = choice.flatMap(([guard, arm]) =>
      applyGuards(domain, [jsxSelectionTest(value, guard)], state).flatMap(
        (guards) => jsxSlotPaths(arm, state, guards, seen),
      ),
    )
    const [first, ...rest] = paths
    return first &&
      rest.every(({ availability }) => availability === first.availability)
      ? [{ guards: domain, availability: first.availability }]
      : paths
  }
  const alias = jsxReference(value)
  if (!alias) return [{ guards: domain }]
  return ts.isFunctionDeclaration(alias)
    ? [{ guards: domain, availability: 'truthy' }]
    : jsxSlotPaths(alias, state, domain, seen)
}

function jsxSlotAvailability(
  value: ts.Expression,
  state: EmptyState,
  domain: Condition[] = [],
): SlotAvailability | undefined {
  const [first, ...rest] = jsxSlotPaths(value, state, domain)
  if (
    first &&
    rest.every(({ availability }) => availability === first.availability)
  )
    return first.availability
}

function modalOpening(element: Element, view: View): Condition[] {
  const input = element.attributes.find(({ name, bindingType }) =>
    view.vueProps
      ? name === 'modelValue' || name === 'model-value'
      : name === 'open' && bindingType !== BindingType.Attribute,
  )?.expression
  // Opening describes which paths can mount, independently of their text.
  return input ? [{ ...condition(input), opening: true }] : []
}

function modalSlots(
  element: Element,
  state: EmptyState,
  domain: Condition[],
): [
  bodies: RenderNode[][],
  actions: RenderNode[],
  header: RenderNode[],
  possibleSlots?: SlotSelection[],
] {
  const { view } = state
  if (view.jsxResolve) {
    const slots = new Map(
      element.children.flatMap((node) =>
        node.kind === 'element' && node.jsxSlot
          ? [[node.name, node.children] as const]
          : [],
      ),
    )
    const body = slots.get('body')
    const value = element.attributes.find(
      ({ name }) => name === 'body',
    )?.expression
    const fallback = element.children.flatMap((node) =>
      node.kind === 'element' && node.jsxSlot
        ? node.name === 'children'
          ? node.children
          : []
        : [node],
    )
    const actions = slots.get('actions') ?? []
    const header = slots.get('header') ?? []
    if (!value) {
      const explicit = element.attributes.some(({ name }) => name === 'body')
      const nodes =
        element.unknownInputs && !explicit
          ? unresolvedProjection(fallback, element.offset)
          : fallback
      return [[nodes], actions, header]
    }

    const input = jsxInputReference(value, view)
    const mightSupplyBody =
      !input ||
      state.inputs?.has(input.name) ||
      (state.inputs && !state.knownJsxInputs)
    const selected: Condition[][] = []
    const defaulted: Condition[][] = []
    const unresolved: Condition[][] = []
    for (const { guards, availability } of jsxSlotPaths(value, state, domain)) {
      if (availability === 'truthy') selected.push(guards)
      else if (availability !== undefined || !mightSupplyBody)
        defaulted.push(guards)
      else {
        selected.push(guards)
        unresolved.push(guards)
      }
    }
    const onPaths = (
      nodes: RenderNode[],
      paths: Condition[][],
    ): RenderNode[] => {
      if (!nodes.length || !paths.length) return []
      return withoutGuardOptions(domain, paths).length
        ? paths.map((guards) => branch(guards.slice(domain.length), nodes))
        : nodes
    }
    return [
      [
        onPaths(body ?? [], selected),
        onPaths(fallback, defaulted),
        onPaths(unresolvedProjection(fallback, element.offset), unresolved),
      ],
      actions,
      header,
    ]
  }
  if (view.vueProps) {
    const slots = vueSlotContent(element.children, state, domain)
    const content = (slot: SlotContent | undefined): RenderNode[] => {
      if (!slot) return []
      const replacement = slot.possibleAbsence ?? []
      if (!replacement.length) return slot.nodes
      return [
        ...withoutGuardOptions(domain, replacement).map((guards) =>
          branch(guards, slot.nodes),
        ),
        ...replacement.map((guards) =>
          branch(guards, unresolvedProjection(slot.nodes, element.offset)),
        ),
      ]
    }
    const unknown = slots.get(UNKNOWN_VUE_SLOT)
    // One supplier can match several outlets under the same lexical guards.
    // Keep the body walk and schedule only additional paths from free slots.
    const visitedSuppliers = new Map<RenderNode[], Set<string>>()
    const possibleSlots: SlotSelection[] = []
    const withCompetition = (
      key: string,
      nodes: RenderNode[],
    ): RenderNode[] => {
      const competitor = slotCompetitor(unknown, key)
      const competition = [
        ...(competitor?.present ?? []),
        ...(competitor?.possiblePresence ?? []),
      ]
      if (!competition.length) return nodes
      const onPaths = (
        children: RenderNode[],
        paths: Condition[][],
      ): RenderNode[] =>
        children.length ? paths.map((path) => branch(path, children)) : []
      if (key !== 'default')
        for (const supplier of competitor?.suppliers ?? []) {
          if (!supplier.nodes.length) continue
          const visited = visitedSuppliers.get(supplier.nodes) ?? new Set()
          for (const guards of coveredPaths(supplier.guards, competition)) {
            const path = guardPathKey(guards)
            if (visited.has(path)) continue
            visited.add(path)
            if (key !== 'body')
              possibleSlots.push({ nodes: supplier.nodes, guards })
          }
          visitedSuppliers.set(supplier.nodes, visited)
        }
      const candidates =
        key === 'body' ? [...nodes, ...(competitor?.nodes ?? [])] : nodes
      return [
        ...onPaths(nodes, withoutGuardOptions(domain, competition)),
        ...onPaths(
          unresolvedProjection(candidates, element.offset),
          competition,
        ),
      ]
    }
    const body = slots.get('body')
    const selected = selectedSlot(
      body && {
        ...body,
        nodes: content(body),
        possiblePresence: [
          ...(body.possiblePresence ?? []),
          ...(body.possibleAbsence ?? []),
        ],
      },
      withCompetition('default', content(slots.get('default'))),
      state,
    )
    return [
      [withCompetition('body', selected)],
      withCompetition('actions', content(slots.get('actions'))),
      withCompetition('header', content(slots.get('header'))),
      possibleSlots,
    ]
  }
  const slots = namedSlotContent(element.children, (node, guards) => [
    {
      key: ['modalBody', 'modalActions'].includes(node.templateReference ?? '')
        ? node.templateReference
        : undefined,
      guards,
    },
  ])
  const header = { selector: '[modalHeader]', offset: 0 }
  const body = { selector: '*', offset: 1 }
  const rootNodes = (nodes: RenderNode[]): RenderNode[] =>
    nodes.flatMap<RenderNode>((node) => {
      if (node.kind === 'branch')
        return [{ ...node, children: rootNodes(node.children) }]
      return node.kind === 'element' && node.templateReference !== undefined
        ? []
        : [node]
    })
  const projected = angularProjection(rootNodes(element.children), [
    header,
    body,
  ])
  return [
    [
      selectedSlot(
        slots.get('modalBody'),
        projected.get(body.offset) ?? [],
        state,
      ),
    ],
    slots.get('modalActions')?.nodes ?? [],
    projected.get(header.offset) ?? [],
  ]
}

function angularProjection(
  nodes: RenderNode[],
  outlets: AngularOutlet[],
): Map<number, RenderNode[]> {
  const projected = new Map<number, RenderNode[]>()
  const matcher = new SelectorMatcher<number>()
  const selectors = outlets.map(({ selector }, index) => {
    if (selector === '*') return []
    const parsed = CssSelector.parse(selector)
    matcher.addSelectables(parsed, index)
    return parsed.map((entry) => entry.toString())
  })
  const fallback = [...outlets]
    .reverse()
    .find(({ selector }) => selector === '*')
  for (const item of nodes) {
    if (item.kind === 'text' && !item.value.trim()) continue
    const anchor =
      item.kind === 'element'
        ? item
        : item.kind === 'branch'
          ? item.insertion
          : undefined
    let index = outlets.length
    if (anchor) {
      const alias = anchor.attributes.find(
        ({ name, expression }) => name === 'ngProjectAs' && !expression,
      )?.literal
      if (alias !== undefined) {
        const literal = CssSelector.parse(alias)[0]?.toString()
        const exact =
          literal === undefined
            ? -1
            : selectors.findIndex((entries) => entries.includes(literal))
        if (exact >= 0) index = exact
      } else {
        const selector = new CssSelector()
        if (
          anchor.name &&
          !anchor.name.startsWith('#') &&
          anchor.name !== 'ng-template'
        )
          selector.setElement(anchor.name)
        for (const {
          name,
          literal,
          expression,
          bindingType,
        } of anchor.attributes) {
          if (
            bindingType === BindingType.Attribute ||
            bindingType === BindingType.Animation ||
            name === 'ngProjectAs'
          )
            continue
          selector.addAttribute(name, expression ? '' : literal)
          if (name === 'class' && !expression)
            literal
              ?.split(/\s+/)
              .filter(Boolean)
              .forEach((token) => selector.addClassName(token))
        }
        matcher.match(selector, (_, candidate) => {
          index = Math.min(index, candidate)
        })
      }
    }
    const outlet = outlets[index] ?? fallback
    if (!outlet) continue
    const children = projected.get(outlet.offset) ?? []
    children.push(item)
    projected.set(outlet.offset, children)
  }
  return projected
}

function inspectViews(modules: Iterable<[string, SourceModule]>) {
  const locations: Locations = new Map()
  const manual = new Map<string, Map<number, Set<string>>>()
  let modalBodyVisits = 0
  let reserveAnalyses = 0
  const active = new Map<View, Set<string | undefined>>()
  let nextMount = 0
  const decorationMounts = new WeakMap<
    Element,
    Map<ViewMount | undefined, ViewMount>
  >()
  const mountView: MountView = (view, sender, element) => {
    if (!element) return { id: nextMount++, view, events: [], sender }
    const mounts = decorationMounts.get(element) ?? new Map()
    const mounted = mounts.get(sender)
    if (mounted) return mounted
    const mount = { id: nextMount++, view, events: [], sender }
    mounts.set(sender, mount)
    decorationMounts.set(element, mounts)
    return mount
  }
  const regions: ReserveRegion[] = []
  const report = (
    path: string,
    offset: number,
    id: RuleId,
    message: string,
  ): void => {
    const offsets =
      locations.get(path) ?? new Map<number, Map<RuleId, string>>()
    const rules = offsets.get(offset) ?? new Map<RuleId, string>()
    if (!rules.has(id)) rules.set(id, message)
    offsets.set(offset, rules)
    locations.set(path, offsets)
  }
  const review = (path: string, offset: number, reason: string): void => {
    const offsets = manual.get(path) ?? new Map<number, Set<string>>()
    const reasons = offsets.get(offset) ?? new Set<string>()
    reasons.add(reason)
    offsets.set(offset, reasons)
    manual.set(path, offsets)
  }
  const reviewAppearance = (path: string, offset: number): void =>
    review(
      path,
      offset,
      'The empty branch depends on unresolved markup or styling.',
    )

  interface Delegation {
    owner: View
    covered: Condition[][]
  }

  interface MountOptions {
    projection?: Projection
    inputOutcome?: InputOutcome
    domain?: Condition[]
    visible?: DomPath[]
    delegation?: Delegation
    inputs?: ReadonlyMap<string, MountedValue | undefined>
    knownJsxInputs?: boolean
    sender?: ViewMount
    site?: string
    unresolvedMount?: boolean
  }

  // Visible paths extend the structural path with constraints from the DOM.
  // At a portal, only the structural prefix carries over.
  const within = (
    visible: DomPath[],
    domain: Condition[],
    reachable: Condition[],
  ): DomPath[] =>
    domain === reachable
      ? visible
      : visible.flatMap((path) => {
          if (path.conditions === domain)
            return [{ ...path, conditions: reachable }]
          const combined = [
            ...reachable,
            ...path.conditions.slice(domain.length),
          ]
          return possible(combined) ? [{ ...path, conditions: combined }] : []
        })

  const walks = new WeakMap<EmptyState, WalkNodes>()
  const visit = (
    view: View,
    context: Context,
    {
      projection,
      inputOutcome,
      domain = [],
      visible = [{ conditions: domain }],
      delegation,
      inputs,
      knownJsxInputs,
      sender,
      site,
      unresolvedMount = view.unresolvedMount ?? false,
    }: MountOptions = {},
  ): void => {
    const current = active.get(view) ?? new Set<string | undefined>()
    if (current.has(site)) return
    current.add(site)
    active.set(view, current)

    const mount = mountView(view, sender)
    const state: EmptyState = {
      view,
      mountView,
      mount,
      inputs,
      outlets: projection,
      inputOutcome,
      knownJsxInputs,
      canonical: delegation ? 'action' : undefined,
      owner: delegation?.owner,
    }
    const activeTemplates = new Set<Element>()
    const renderTemplate = (
      template: Element,
      scope: Context,
      guards: Condition[],
      visible: DomPath[],
      unresolved: boolean,
      parent: Element | undefined,
      placement: DomParent,
    ): void => {
      if (activeTemplates.has(template)) return
      activeTemplates.add(template)
      walk(
        template.children,
        scope,
        parent,
        guards,
        visible,
        unresolved,
        placement,
      )
      activeTemplates.delete(template)
    }
    const recordEvent = (
      event: ts.Expression,
      conditions: Condition[],
      origin: EmptyState,
      unresolvedMount: boolean,
    ): void => {
      event = transparent(event)
      const input = mountedValue(event, origin)
      if (input) {
        if (input.availability !== 'absent' && input.value !== UNDEFINED_INPUT)
          recordEvent(input.value, conditions, input.state, unresolvedMount)
        return
      }
      const choice = jsxChoice(event)
      if (choice) {
        for (const [guard, handler] of choice)
          for (const path of applyGuards(conditions, [guard], origin))
            recordEvent(handler, path, origin, unresolvedMount)
        return
      }
      const declaration = stateBinding(event, origin.view)
      const owner =
        (declaration && declarationMount(declaration, origin.mount)) ??
        origin.mount
      owner?.events.push({ event, conditions, unresolvedMount })
    }

    const walk: WalkNodes = (
      nodes,
      context,
      parent,
      guards,
      visible,
      unresolved = unresolvedMount,
      placement = {
        parent: parent && { element: parent, conditions: guards, state },
        regions: [],
      },
    ) => {
      const siblings = siblingElements(nodes).filter(({ element }) =>
        projectedTemplate(element, parent),
      )
      if (
        !delegation &&
        (context === 'modal' || (context === 'plain' && unresolved)) &&
        siblings.length
      )
        for (const { conditions: scope, ...dom } of visible) {
          const region = {
            state,
            siblings: siblings.map(({ element, conditions }) => ({
              element,
              conditions: [...scope, ...conditions],
              state,
              domPrefix: scope,
            })),
            batch: siblings,
            placement,
            guards: scope,
            dom,
            alternateDom: visible.length > 1 ? visible : undefined,
            unresolvedMount: unresolved,
          }
          regions.push(region)
          placement.regions.push(region)
        }
      if (view.jsxParameter && view.jsxResolve && state.inputs) {
        const batches = new Map<WalkNodes, RenderNode[]>()
        const project = (
          items: RenderNode[],
          conditions: Condition[],
        ): void => {
          for (const item of items) {
            if (item.kind === 'branch') {
              const next = [...conditions, ...item.conditions]
              if (applyGuards(guards, next, state).length)
                project(item.children, next)
              continue
            }
            if (item.kind !== 'text') continue
            for (const lexical of applyGuards([], conditions, state)) {
              for (const selected of renderedJsxInput(item, state, lexical) ??
                []) {
                const owner = walks.get(selected.state)
                if (!owner || !selected.nodes.length) continue
                const batch = batches.get(owner) ?? []
                batch.push(
                  branch(
                    selected.guards,
                    selected.unresolved
                      ? unresolvedProjection(
                          selected.nodes,
                          parent?.offset ?? view.offset,
                        )
                      : selected.nodes,
                  ),
                )
                batches.set(owner, batch)
              }
            }
          }
        }
        project(nodes, [])
        for (const [owner, batch] of batches)
          owner(batch, context, parent, guards, visible, unresolved, placement)
      }
      for (const { element, conditions } of siblings) {
        const scope = elementContext(element, context)
        const modal = element.component === MODAL
        const portal = modal && Boolean(view.jsxResolve || view.vueProps)
        const openings = modal ? modalOpening(element, view) : []
        const opaque =
          unresolved ||
          Boolean(
            element.unresolvedMount ||
            (view.vueProps &&
              element.name === 'component' &&
              element.attributes.some(
                ({ name, expression }) => name === 'is' && expression,
              )) ||
            (view.jsxResolve &&
              !element.component &&
              !element.target &&
              /^[A-Z]/.test(element.name) &&
              !/^(?:React\.)?(?:Fragment|StrictMode|Suspense)$/.test(
                element.name,
              )),
          )
        for (const reachable of applyGuards(
          guards,
          [...conditions, ...openings],
          state,
        )) {
          const paths = domPaths(
            element,
            portal
              ? [{ conditions: reachable }]
              : within(visible, guards, reachable),
            state,
          )
          if (!paths.length && !view.jsxResolve && !view.vueProps) continue

          if (element.component && SHARED.has(element.component)) {
            if (delegation && element.component === FORM_ERROR)
              for (const {
                conditions: path,
                hidden,
                unresolved,
                uncertainVisibility,
              } of paths) {
                if (hidden) continue
                const reviewDelivery = (): void =>
                  review(
                    view.path,
                    element.offset,
                    'The shared error room receives its refusal through an unresolved input or projection.',
                  )
                const delivered = delegatedMessage(
                  element,
                  path,
                  state,
                  reviewDelivery,
                )
                if (
                  opaque ||
                  (delivered.length && (unresolved || uncertainVisibility))
                )
                  reviewDelivery()
                delegation.covered.push(...delivered)
              }
            continue
          }
          if (modal) {
            const [bodies, actions, header, possibleSlots] = modalSlots(
              element,
              state,
              reachable,
            )
            for (const body of bodies) {
              if (!body.length) continue
              modalBodyVisits++
              walk(body, scope, element, reachable, paths, opaque)
            }
            for (const content of [actions, header])
              if (content.length)
                walk(content, scope, element, reachable, paths, opaque)
            for (const { nodes, guards } of possibleSlots ?? [])
              walk(
                nodes,
                scope,
                element,
                guards,
                within(paths, reachable, guards),
                true,
              )
            continue
          }
          const outlet = element.attributes.find(
            ({ name }) => name === 'ngTemplateOutlet',
          )?.expression
          const renderOutlet = (
            name: string | undefined,
            input: ts.Expression | undefined,
            guards: Condition[],
          ): void => {
            const visible = within(paths, reachable, guards)
            const local = name && view.templates?.get(name)
            if (local) {
              renderTemplate(
                local,
                scope,
                guards,
                visible,
                opaque,
                parent,
                placement,
              )
              return
            }
            const query =
              name && view.contentQueries?.get(name.replace(/\(\)$/, ''))
            const projected = query && projection?.get(query)
            if (projected) {
              projected.walk(
                projected.nodes,
                scope,
                parent,
                guards,
                visible,
                opaque,
                placement,
              )
              return
            }
            if (!input || !view.templates?.size) return
            const choice = jsxChoice(input)
            if (choice) {
              for (const [guard, arm] of choice)
                for (const path of applyGuards(guards, [guard], state))
                  renderOutlet(templateOutlet(arm, new Map()), arm, path)
              return
            }
            const templates = new Set<Element>()
            const references = (node: ts.Node): void => {
              if (ts.isIdentifier(node)) {
                const template = view.templates?.get(node.text)
                if (template) templates.add(template)
              }
              ts.forEachChild(node, references)
            }
            references(input)
            for (const template of templates)
              renderTemplate(
                template,
                scope,
                guards,
                visible,
                true,
                parent,
                placement,
              )
          }
          if (element.outlet || outlet)
            renderOutlet(element.outlet, outlet, reachable)
          if (isContentOutlet(element, view)) {
            for (const { projected, guards, unresolved } of outletPaths(
              element,
              state,
              projection,
              reachable,
            )) {
              const visible = within(paths, reachable, guards)
              if (projected)
                projected.walk(
                  projected.nodes,
                  scope,
                  parent,
                  guards,
                  visible,
                  opaque || unresolved,
                  placement,
                )
              else
                walk(
                  element.children,
                  scope,
                  parent,
                  guards,
                  visible,
                  opaque || unresolved,
                  placement,
                )
            }
            continue
          }
          if (element.target) {
            const target = element.target
            const projected: Projection = new Map()
            const outcomes = new Map<string, Outcome>()
            const resolve = view.jsxResolve
            const inputs = mountedInputValues(element, target, state, reachable)
            if (target.jsxResolve && resolve) {
              setExplicitJsxChildren(element, state, inputs)
              for (const [name, input] of inputs) {
                const outcome =
                  input && outcomeOf(input.value, view, inputOutcome)
                if (outcome) outcomes.set(name, outcome)
              }
            } else {
              for (const [name, outlet] of mountedOutlets(
                element,
                target,
                state,
                reachable,
              ))
                projected.set(name, { ...outlet, walk })
              if (target.contentOutlets)
                for (const { element: child, conditions } of siblingElements(
                  element.children,
                )) {
                  if (child.templateReference)
                    projected.set(child.templateReference, {
                      walk,
                      state,
                      nodes: applyGuards([], conditions, state).map((guards) =>
                        branch(guards, child.children),
                      ),
                    })
                }
            }
            visit(target, scope, {
              projection: projected,
              inputOutcome: outcomes.size
                ? (name) => outcomes.get(name)
                : undefined,
              domain: reachable,
              visible: paths,
              delegation,
              inputs,
              knownJsxInputs: !element.unknownInputs,
              sender: mount,
              site: JSON.stringify([view.path, element.offset]),
              unresolvedMount: opaque,
            })
            continue
          }
          if (
            !delegation &&
            !element.component &&
            element.actions?.length &&
            /^[a-z][a-z0-9]*$/.test(element.name)
          )
            for (const {
              conditions: path,
              hidden,
              unresolved,
              uncertainVisibility,
            } of paths)
              if (!hidden)
                for (const event of element.actions)
                  recordEvent(
                    event,
                    path,
                    state,
                    opaque || Boolean(unresolved || uncertainVisibility),
                  )
          walk(element.children, scope, element, reachable, paths, opaque)
        }
      }
    }
    walks.set(state, walk)
    walk(renderTree(view), context, undefined, domain, visible)
    current.delete(site)
  }

  for (const [path, { views, exported }] of modules) {
    for (const view of views) if (!view.mountedOnly) visit(view, 'plain')
    if (CANONICAL_FORM_ERRORS.has(path)) {
      const owner = exported(
        path.endsWith('.vue') ? 'default' : FORM_ERROR,
      )?.target
      const failure = owner
        ? canonicalEmptyViolation(owner, mountView, (offset) =>
            reviewAppearance(owner.path, offset),
          )
        : { offset: 0, message: EMPTY_TWIN }
      if (failure)
        report(
          owner?.path ?? path,
          failure.offset,
          MODAL_ERROR_EMPTY_RULE_ID,
          failure.message,
        )
    }
    if (CANONICAL_ACTION_ERRORS.has(path)) {
      const owner = views.find(
        (view) => view.actionError || path.endsWith('.vue'),
      )
      const needed = [REFUSAL_STATE, opposite(SUPPRESSED_STATE)]
      const covered: Condition[][] = []
      if (owner)
        visit(owner, 'plain', {
          domain: needed,
          delegation: { owner, covered },
        })
      if (withoutGuardOptions(needed, covered).length)
        report(path, owner?.offset ?? 0, MODAL_ERROR_ROOM_RULE_ID, ROOM_MESSAGE)
    }
  }

  // Read writes only after every projection has registered its native events
  // in the lexical mount that owns their handler.
  const sources = new Map<
    EmptyState,
    RefusalChecks & { possible: ErrorCondition }
  >()
  const sourceAt = (
    state: EmptyState,
  ): RefusalChecks & { possible: ErrorCondition } => {
    const cached = sources.get(state)
    if (cached) return cached
    const events = state.mount?.events ?? []
    let writes: ReturnType<typeof actionWrites> | undefined
    const readWrites = () => (writes ??= actionWrites(state, events))
    const readSource = (
      origin: EmptyState,
      unresolved: boolean,
    ): ErrorCondition =>
      unresolved ? sourceAt(origin).possible : sourceAt(origin).matches
    const matches = errorSource(state, readWrites, readSource)
    const possible = errorSource(state, readWrites, readSource, true)
    const checks = {
      matches,
      possible,
      unresolved: unresolvedRefusal(
        state,
        matches,
        possible,
        (origin) => sourceAt(origin).unresolved,
      ),
    }
    sources.set(state, checks)
    return checks
  }
  for (const region of regions) {
    const {
      state,
      siblings,
      placement,
      guards,
      dom,
      alternateDom,
      unresolvedMount,
    } = region
    const { view } = state
    const inModal = ({ element }: PlacedElement): boolean =>
      element.component !== MODAL &&
      elementContext(element, 'modal') === 'modal'
    const ownSiblings = siblings.filter(inModal)
    if (
      !ownSiblings.some(
        ({ conditions }) => applyGuards([], conditions, state).length,
      )
    )
      continue
    // An outlet's render guards belong to its supplied siblings. The
    // receiving siblings keep their own guards at the same DOM parent.
    const peers = placement.regions.flatMap((other) =>
      other.batch === region.batch ||
      other.unresolvedMount !== unresolvedMount ||
      other.dom.hidden !== dom.hidden ||
      Boolean(other.dom.unresolved) !== Boolean(dom.unresolved) ||
      Boolean(other.dom.uncertainVisibility) !==
        Boolean(dom.uncertainVisibility)
        ? []
        : other.siblings.filter(inModal),
    )
    const modalSiblings = [...ownSiblings, ...peers]
    // Either side of a projection can restore inherited visibility.
    if (
      dom.hidden &&
      !modalSiblings.some((row) => {
        const text = visibleRoomPaths(row, row.state ?? state, [], dom)
        return text.known.length || text.possible.length
      })
    )
      continue
    const candidates = new Set(ownSiblings)
    for (const room of reservedRooms(
      {
        siblings: modalSiblings,
        parent: placement.parent,
        state,
        domain: guards,
        dom,
        hasAlternateDom: Boolean(alternateDom),
        candidatesAt: candidates,
      },
      sourceAt,
      () => {
        reserveAnalyses++
      },
    )) {
      const offset = room.reserve.element.offset
      if (unresolvedMount) {
        review(
          view.path,
          offset,
          'The modal reserve is supplied through an unresolved mount or projection.',
        )
        continue
      }
      if (room.unresolvedSource) {
        review(
          view.path,
          offset,
          'A computation or callback obscures the source of the refusal.',
        )
        continue
      }
      if (room.unresolvedLayout) {
        reviewAppearance(view.path, offset)
        continue
      }
      if (room.requiresDelegation)
        report(view.path, offset, MODAL_ERROR_ROOM_RULE_ID, ROOM_MESSAGE)
      const message = localEmptyViolation(
        room,
        modalSiblings,
        placement.parent,
        state,
        guards,
        () => reviewAppearance(view.path, offset),
        dom,
        alternateDom,
      )
      if (message) report(view.path, offset, MODAL_ERROR_EMPTY_RULE_ID, message)
    }
  }
  return { locations, manual, modalBodyVisits, reserveAnalyses }
}

function delegatedMessage(
  element: Element,
  conditions: Condition[],
  state: EmptyState,
  unresolved: () => void,
): Condition[][] {
  const message = element.attributes.find(
    ({ name }) => name === 'message',
  )?.expression
  if (!message) {
    if (!element.unknownInputs) return []
    unresolved()
    return [conditions]
  }
  // Paths with a possible delivery are enough to leave ambiguous transforms
  // to review. Literal replacements and absent messages cover no path.
  const live = (
    value: ts.Expression,
    scope: Condition[],
    state: EmptyState,
    seen = new Set<ts.Declaration>(),
  ): Condition[][] => {
    value = transparent(value)
    const input = mountedValue(value, state)
    if (input) return live(input.value, scope, input.state, seen)
    const local = templateLocal(value)
    if (local?.value) return live(local.value, scope, state, seen)
    const choice = jsxChoice(value)
    if (choice)
      return choice.flatMap(([guard, result]) =>
        applyGuards(scope, [guard], state).flatMap((arm) =>
          live(result, arm, state, seen),
        ),
      )
    if (ts.isBinaryExpression(value)) {
      const operator = value.operatorToken.kind
      if (operator === ts.SyntaxKind.AmpersandAmpersandToken)
        // A short-circuited falsy operand cannot deliver a nonempty refusal.
        return applyGuards(scope, [condition(value.left)], state).flatMap(
          (path) => live(value.right, path, state, seen),
        )
      if (operator === ts.SyntaxKind.BarBarToken) {
        const test = condition(value.left)
        return [
          ...applyGuards(scope, [test], state).flatMap((path) =>
            live(value.left, path, state, seen),
          ),
          ...applyGuards(scope, [opposite(test)], state).flatMap((path) =>
            live(value.right, path, state, seen),
          ),
        ]
      }
    }

    const { view } = state
    const known = canonicalValue(value, state, seen)
    if (known)
      return known.truthy === 'text' && known.guard === REFUSAL_STATE
        ? applyGuards(scope, [known.guard], state)
        : []
    if (
      staticValue(value, view) !== undefined ||
      unshadowed(value, view, 'undefined')
    )
      return []

    if (ts.isCallExpression(value)) {
      const [argument] = value.arguments
      if (
        value.arguments.length === 1 &&
        argument &&
        unshadowed(value.expression, view, 'String')
      ) {
        const source = canonicalValue(argument, state, seen)
        if (source?.truthy === 'text' && source.guard === REFUSAL_STATE)
          return applyGuards(scope, [source.guard], state)
      }
      if (view.contentOutlets && !value.arguments.length) {
        const method = access(value.expression)
        if (method?.[1] === 'error') {
          const replacement = angularReceiverError(method[0], state)
          if (replacement)
            return live(replacement.value, scope, replacement.state, seen)
        }
      }
    }
    const subject =
      ts.isCallExpression(value) && !value.arguments.length
        ? value.expression
        : value
    const binding = stateBinding(subject, state.view)
    const alias = canonicalAlias(binding, state.view, subject !== value)
    if (binding && alias && !seen.has(binding))
      return live(alias, scope, state, new Set(seen).add(binding))
    unresolved()
    return [scope]
  }
  return live(message, conditions, state)
}

function sdkComponent(path: string): string | undefined {
  const [, framework, name, extension] =
    /^framework\/frontend\/(vue|react|angular)\/src\/(\w+)\.(vue|tsx|ts)$/.exec(
      path,
    ) ?? []
  const extensions: Record<string, string> = {
    vue: 'vue',
    react: 'tsx',
    angular: 'ts',
  }
  return extensions[framework] === extension && SDK_COMPONENTS.has(name)
    ? name
    : undefined
}

function sourceModules(
  sources: ReadonlyMap<string, string>,
): Map<string, SourceModule> {
  const modules = new Map<string, SourceModule>()
  const resolving = new Set<string>()

  const load = (path: string): SourceModule | undefined => {
    const existing = modules.get(path)
    if (existing) return existing
    const source = sources.get(path)
    if (source === undefined || path.endsWith('.html')) return undefined
    const resolve: ResolveImport = (specifier, name) => {
      if (
        specifier === '@angular/core' &&
        ['Component', 'contentChild'].includes(name)
      )
        return { component: name }
      if (
        specifier === '@angular/common' &&
        ['NgIf', 'CommonModule'].includes(name)
      )
        return { component: name }
      if (
        /^@hilos\/(?:angular|react|vue)(?:\/|$)/.test(specifier) &&
        SDK_COMPONENTS.has(name)
      ) {
        return { component: name }
      }
      const sdk = /^@hilos\/(angular|react|vue)(?:\/(.+))?$/.exec(specifier)
      const relative = sdk
        ? `${SDK_ROOT}/${sdk[1]}/src/${sdk[2] ?? 'base'}/index.ts`
        : posix.join(posix.dirname(path), specifier)
      if (!sdk && !specifier.startsWith('./') && !specifier.startsWith('../'))
        return undefined
      const stem = relative.replace(/\.[jt]sx?$/, '')
      const candidates = [relative, `${stem}.tsx`, `${stem}.ts`]
      if (relative === stem && !relative.endsWith('.vue')) {
        candidates.push(`${stem}.vue`, `${stem}/index.ts`, `${stem}/index.tsx`)
      }
      for (const candidate of candidates) {
        const component = sdkComponent(candidate)
        if (
          component &&
          (name === component ||
            (name === 'default' && candidate.endsWith('.vue')))
        ) {
          return { component }
        }
        const key = `${candidate}:${name}`
        if (resolving.has(key)) continue
        resolving.add(key)
        const found = load(candidate)?.exported(name)
        resolving.delete(key)
        if (found) return found
        if (sources.has(candidate)) break
      }
      return undefined
    }
    const module = path.endsWith('.vue')
      ? vueModule(path, source, resolve)
      : typescriptModule(path, source, resolve, sources)
    modules.set(path, module)
    return module
  }

  for (const path of sources.keys()) load(path)
  return modules
}

function reports(sources: ReadonlyMap<string, string>): ModalScan {
  const { locations, manual, modalBodyVisits, reserveAnalyses } = inspectViews(
    sourceModules(sources),
  )
  const format = <T>(
    findings: ReadonlyMap<string, ReadonlyMap<number, T>>,
    lines: (location: string, messages: T) => string[],
  ): string[] =>
    [...findings.keys()].sort().flatMap((path) => {
      const source = sources.get(path) ?? ''
      return [...findings.get(path)!]
        .sort(([a], [b]) => a - b)
        .flatMap(([offset, messages]) => {
          const line = source
            .slice(0, offset)
            .split(/\r\n|[\n\r\u2028\u2029]/).length
          return lines(`${path}:${line}`, messages)
        })
    })
  return {
    violations: format(locations, (location, rules) =>
      ([MODAL_ERROR_ROOM_RULE_ID, MODAL_ERROR_EMPTY_RULE_ID] as const).flatMap(
        (id) => {
          const message = rules.get(id)
          return message
            ? [`${id} ${location} — ${message} ${STYLE_RULES}`]
            : []
        },
      ),
    ),
    manualReview: format(manual, (location, reasons) =>
      [...reasons].sort().map((reason) => `${location} — ${reason}`),
    ),
    modalBodyVisits,
    reserveAnalyses,
  }
}

/**
 * Checks local and shared modal error rooms in the supplied source.
 *
 * @param relativePath Path of the file from the repository root, as it appears in the report
 * @param source Contents of the file
 * @returns Report lines in source order
 */
export function checkSource(relativePath: string, source: string): string[] {
  return inspectSource(relativePath, source).violations
}

/** Checks one file and returns the findings and traversal counts. */
export function inspectSource(relativePath: string, source: string): ModalScan {
  return reports(
    /\.(?:vue|tsx?)$/.test(relativePath)
      ? new Map([[relativePath, source]])
      : new Map(),
  )
}

function productionSources(repositoryRoot: string): Map<string, string> {
  const roots = SDK_PACKAGES.map((framework) => `${SDK_ROOT}/${framework}/src`)
  const demos = posix.join(repositoryRoot, 'demo')
  if (existsSync(demos)) {
    for (const entry of readdirSync(demos, { withFileTypes: true })) {
      if (entry.isDirectory()) roots.push(`demo/${entry.name}/frontend/src`)
    }
  }

  const sources = new Map<string, string>()
  const walk = (directory: string): void => {
    for (const entry of readdirSync(posix.join(repositoryRoot, directory), {
      withFileTypes: true,
    })) {
      const path = `${directory}/${entry.name}`
      if (entry.isDirectory() && !SKIPPED_DIRECTORIES.has(entry.name))
        walk(path)
      if (
        entry.isFile() &&
        /\.(?:vue|tsx?|html)$/.test(entry.name) &&
        !/\.(?:d|fixture|generated|spec|test)\.(?:vue|tsx?|html)$/.test(
          entry.name,
        )
      ) {
        sources.set(
          path,
          readFileSync(posix.join(repositoryRoot, path), 'utf8'),
        )
      }
    }
  }
  for (const root of roots) {
    if (existsSync(posix.join(repositoryRoot, root))) walk(root)
  }
  return sources
}

/**
 * Checks the three SDKs and each demo's production frontend.
 *
 * @param repositoryRoot Absolute path of the repository root
 * @returns Findings in path and source order, with traversal counts
 */
export function checkRepository(repositoryRoot: string): ModalScan {
  return reports(productionSources(repositoryRoot))
}
