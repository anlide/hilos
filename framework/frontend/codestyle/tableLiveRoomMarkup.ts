import { posix } from 'node:path'

import {
  Block,
  Element as AngularElement,
  Expansion,
  ExpressionBinding,
  HtmlParser,
  LetDeclaration,
  Lexer,
  Parser,
  splitNsName,
  Text as AngularText,
  VariableBinding,
} from '@angular/compiler'
import {
  NodeTypes,
  parse as parseVue,
  type TemplateChildNode,
} from '@vue/compiler-dom'
import ts from 'typescript'

export interface BooleanAlias {
  key: string
  inverted: boolean
}

export interface MarkupBindings {
  names: ReadonlyMap<string, string>
  angular?: boolean
  literals?: ReadonlyMap<string, boolean>
  strings?: ReadonlyMap<string, string>
  aliases?: ReadonlyMap<string, BooleanAlias>
  mutableObjects?: ReadonlySet<string>
}

export interface MarkupAttribute {
  name: string
  value: string
  literal?: string
  jsxLiteralValues?: readonly string[]
  interpolation?: { strings: string[]; expressions: string[] }
  offset: number
}

export interface MarkupElement {
  kind: 'element'
  name: string
  attributes: MarkupAttribute[]
  children: MarkupNode[]
  offset: number
  sdkLive?: boolean
  sdkBoundary?: 'table' | 'modal'
  sdkViewport?: boolean
  jsxUses?: readonly number[]
  unknownJsxAttributes?: boolean
  bindings?: MarkupBindings
  gateBindings?: MarkupBindings
  ngIfBranches?: {
    condition: string
    then?: string
    else?: string
    aliases: string[]
  }
  ngTemplateOutlet?: { target: string; context?: string; shorthand: boolean }
}

export type MarkupNode =
  | MarkupElement
  | {
      kind: 'branch'
      name: string
      expression: string
      attribute?: string
      review?: string
      bindings?: MarkupBindings
      gateBindings?: MarkupBindings
      children: MarkupNode[]
      offset: number
    }
  | { kind: 'text'; text: string; offset: number }

export interface MarkupTemplate {
  nodes: MarkupNode[]
  offset: number
  viewport: boolean
  reservedOnly?: boolean
  classFields?: ReadonlyMap<string, ts.Expression>
}

const LIVE_COMPONENT = 'HilosTableLive'
const VIEWPORT_COMPONENT = 'HilosViewportTable'
const SELECTION_COMPONENT = 'HilosTableSelection'
const MODAL_COMPONENT = 'HilosModal'
const angularBindingParser = new Parser(new Lexer())
const JSX_TABLE_CONTENT = new Set([
  'cells',
  'details',
  'empty',
  'tableProgress',
  'tableProgressAction',
  'rowProgress',
  'bulkUntouched',
])

type AngularNode = ReturnType<HtmlParser['parse']>['rootNodes'][number]
type JsxBinding = (tag: ts.JsxTagNameExpression) => boolean
type JsxBoundary = (
  tag: ts.JsxTagNameExpression,
) => MarkupElement['sdkBoundary']

/**
 * Parses each template with offsets in the original file. Branches retain their
 * children so checks can distinguish shared markup from projected content.
 */
export function readTemplates(
  path: string,
  source: string,
  viewport: boolean,
  owner = false,
  external?: Map<string, ReadonlySet<string> | undefined>,
): MarkupTemplate[] {
  if (path.endsWith('.vue')) return vueTemplates(path, source, viewport)
  if (path.endsWith('.html')) {
    return [
      {
        nodes: angularNodes(source, 0, false, false, external?.get(path)),
        offset: 0,
        viewport: false,
      },
    ]
  }
  if (path.endsWith('.tsx')) return jsxTemplates(path, source, viewport, owner)
  if (path.endsWith('.ts'))
    return angularTemplates(path, source, viewport, owner, external)
  return []
}

/** Visits each element with its enclosing elements and control-flow branches. */
export function visitElements(
  nodes: readonly MarkupNode[],
  visit: (node: MarkupElement, ancestors: readonly MarkupNode[]) => void,
): void {
  function walk(node: MarkupNode, ancestors: readonly MarkupNode[]): void {
    if (node.kind === 'text') return
    if (node.kind === 'element') visit(node, ancestors)
    const path = [...ancestors, node]
    for (const child of node.children) walk(child, path)
  }
  for (const node of nodes) walk(node, [])
}

function vueTemplates(
  path: string,
  source: string,
  viewport: boolean,
): MarkupTemplate[] {
  const sfc = parseVue(source, {
    parseMode: 'sfc',
    comments: false,
    onError() {
      // Retain nodes recovered by the parser.
    },
  })
  const liveNames = new Set<string>()
  const tableNames = new Set<string>()
  const selectionNames = new Set<string>()
  const modalNames = new Set<string>()
  const strings = new Map<string, string>()
  for (const block of sfc.children) {
    if (block.type !== NodeTypes.ELEMENT || block.tag !== 'script') continue
    const script = block.children[0]
    if (script?.type !== NodeTypes.TEXT) continue
    if (
      !viewport &&
      !/(?:v-bind:class|:class)\s*=/.test(source) &&
      !/Hilos(?:ViewportTable|TableSelection|Modal)|@hilos\/vue/.test(
        script.content,
      )
    )
      continue
    const file = ts.createSourceFile(
      'component.ts',
      script.content,
      ts.ScriptTarget.Latest,
      true,
    )
    const setup = block.props.some(
      (prop) => prop.type === NodeTypes.ATTRIBUTE && prop.name === 'setup',
    )
    if (setup)
      for (const [name, value] of moduleStrings(file)) strings.set(name, value)
    const addNames = (names: Set<string>, imports: readonly string[]): void => {
      const bindings = new Set(imports)
      for (const name of setup ? bindings : vueRegisteredNames(file, bindings))
        names.add(name)
    }
    if (viewport)
      addNames(
        liveNames,
        importsFrom(file, './HilosTableLive.vue', 'default').map(
          ({ text }) => text,
        ),
      )
    for (const [names, component] of [
      [tableNames, VIEWPORT_COMPONENT],
      [selectionNames, SELECTION_COMPONENT],
      [modalNames, MODAL_COMPONENT],
    ] as const) {
      addNames(names, [
        ...sdkBindings(path, file, 'vue', component).map(({ text }) => text),
        ...sdkNamespaces(file, 'vue').map(({ text }) => `${text}.${component}`),
        ...importsFrom(file, sdkModule(path, 'vue', component), '*').map(
          ({ text }) => `${text}.default`,
        ),
      ])
    }
  }

  return sfc.children.flatMap((block) => {
    if (block.type !== NodeTypes.ELEMENT || block.tag !== 'template') return []
    const bindings =
      strings.size === 0
        ? undefined
        : { names: new Map<string, string>(), strings }
    const nodes = bindTemplateScopes(block.children.flatMap(read), bindings)
    return [{ nodes, offset: block.loc.start.offset, viewport }]
  })

  function read(node: TemplateChildNode): MarkupNode[] {
    const offset = node.loc.start.offset
    if (node.type === NodeTypes.TEXT)
      return [{ kind: 'text', text: node.content, offset }]
    if (node.type === NodeTypes.INTERPOLATION) {
      return [
        {
          kind: 'branch',
          name: 'expression',
          expression: node.content.loc.source,
          children: [],
          offset,
        },
      ]
    }
    if (
      node.type !== NodeTypes.ELEMENT ||
      ['script', 'style'].includes(node.tag)
    )
      return []

    const attributes = node.props.map((prop): MarkupAttribute => {
      if (prop.type === NodeTypes.ATTRIBUTE) {
        const value = prop.value?.content ?? ''
        return {
          name: prop.name,
          value,
          literal: value,
          offset: prop.loc.start.offset,
        }
      }
      const arg = prop.arg?.loc.source ?? ''
      const name = arg === '' ? `v-${prop.name}` : `v-${prop.name}:${arg}`
      const value = prop.exp?.loc.source ?? ''
      return {
        name,
        value,
        literal:
          name === 'v-bind:data-id' ? literalExpression(value) : undefined,
        offset: prop.loc.start.offset,
      }
    })
    const camel = node.tag.replace(/-([a-z])/g, (_, letter: string) =>
      letter.toUpperCase(),
    )
    const pascal = camel.charAt(0).toUpperCase() + camel.slice(1)
    const matches = (names: ReadonlySet<string>): boolean =>
      names.has(node.tag) || names.has(camel) || names.has(pascal)
    return [
      {
        kind: 'element',
        name: node.tag,
        attributes,
        children: node.children.flatMap(read),
        offset,
        sdkLive: matches(liveNames),
        sdkViewport: matches(tableNames),
        sdkBoundary:
          matches(tableNames) || matches(selectionNames)
            ? 'table'
            : matches(modalNames)
              ? 'modal'
              : undefined,
      },
    ]
  }
}

function vueRegisteredNames(
  file: ts.SourceFile,
  bindings: ReadonlySet<string>,
): string[] {
  const declaration = file.statements.find(ts.isExportAssignment)
  if (declaration === undefined) return []
  const options = ts.isCallExpression(declaration.expression)
    ? declaration.expression.arguments[0]
    : declaration.expression
  const components = property(options, 'components')
  if (components === undefined || !ts.isObjectLiteralExpression(components))
    return []
  return components.properties.flatMap((entry) => {
    if (
      ts.isShorthandPropertyAssignment(entry) &&
      bindings.has(entry.name.text)
    )
      return [entry.name.text]
    if (
      ts.isPropertyAssignment(entry) &&
      (ts.isIdentifier(entry.name) || ts.isStringLiteral(entry.name)) &&
      (ts.isIdentifier(entry.initializer) ||
        ts.isPropertyAccessExpression(entry.initializer)) &&
      bindings.has(entry.initializer.getText())
    )
      return [entry.name.text]
    return []
  })
}

function angularTemplates(
  path: string,
  source: string,
  viewport: boolean,
  owner: boolean,
  external?: Map<string, ReadonlySet<string> | undefined>,
): MarkupTemplate[] {
  if (!source.includes('template') || !source.includes('@angular/core'))
    return []
  const file = ts.createSourceFile(path, source, ts.ScriptTarget.Latest, true)
  const decorators = importsFrom(file, '@angular/core', 'Component')
  const namespaces = importsFrom(file, '@angular/core', '*')
  if (decorators.length === 0 && namespaces.length === 0) return []
  const checker = bindingChecker(file)
  const component = owner
    ? exportedComponent(file, checker, LIVE_COMPONENT)
    : viewport
      ? exportedComponent(file, checker, VIEWPORT_COMPONENT)
      : undefined
  const liveBindings = viewport
    ? importsFrom(file, './HilosTableLive.js', LIVE_COMPONENT)
    : []
  const templates: MarkupTemplate[] = []

  const definitions = file.statements
    .filter(ts.isClassDeclaration)
    .flatMap((statement) =>
      (ts.getDecorators(statement) ?? []).flatMap(({ expression }) =>
        ts.isCallExpression(expression) &&
        isComponentDecorator(expression.expression)
          ? [{ statement, metadata: expression.arguments[0] }]
          : [],
      ),
    )
  const selectors = new Map(
    definitions.flatMap(({ statement, metadata }) => {
      const selector = property(metadata, 'selector')
      const symbol =
        statement.name && checker.getSymbolAtLocation(statement.name)
      return symbol && selector && ts.isStringLiteralLike(selector)
        ? [[symbol, selector.text] as const]
        : []
    }),
  )
  for (const { statement, metadata } of definitions) {
    if (owner && statement !== component) continue
    const imports = property(metadata, 'imports')
    const registered =
      imports !== undefined && ts.isArrayLiteralExpression(imports)
        ? imports.elements.filter(ts.isIdentifier)
        : []
    const localSelectors = new Set(
      registered.flatMap((name) => {
        const symbol = checker.getSymbolAtLocation(name)
        const selector = symbol && selectors.get(symbol)
        return selector === undefined ? [] : [selector]
      }),
    )
    const template = property(metadata, 'template')
    if (template === undefined || !ts.isStringLiteralLike(template)) {
      const url = property(metadata, 'templateUrl')
      if (
        external &&
        url &&
        ts.isStringLiteralLike(url) &&
        !posix.isAbsolute(url.text)
      ) {
        const target = posix.join(posix.dirname(path), url.text)
        external.set(target, external.has(target) ? undefined : localSelectors)
      }
      continue
    }

    const offset = template.getStart(file) + 1
    const isViewport = viewport && statement === component
    const liveRegistered =
      isViewport &&
      registered.some((entry) => refersTo(checker, entry, liveBindings))
    const nodes = angularNodes(
      source.slice(offset, template.getEnd() - 1),
      offset,
      true,
      liveRegistered,
      localSelectors,
    )
    const classFields = owner ? angularClassFields(statement) : undefined
    templates.push({ nodes, offset, viewport: isViewport, classFields })
  }
  return templates

  function isComponentDecorator(expression: ts.Expression): boolean {
    if (ts.isIdentifier(expression))
      return refersTo(checker, expression, decorators)
    return (
      ts.isPropertyAccessExpression(expression) &&
      expression.name.text === 'Component' &&
      ts.isIdentifier(expression.expression) &&
      refersTo(checker, expression.expression, namespaces)
    )
  }
}

function angularClassFields(
  component: ts.ClassDeclaration,
): ReadonlyMap<string, ts.Expression> {
  const fields = new Map<string, ts.Expression>()
  const strings = moduleStrings(component.getSourceFile())
  for (const member of component.members) {
    if (
      ts.isPropertyDeclaration(member) &&
      ts.isIdentifier(member.name) &&
      member.initializer !== undefined &&
      member.modifiers?.some(
        ({ kind }) => kind === ts.SyntaxKind.ReadonlyKeyword,
      )
    ) {
      const text = immutableString(member.initializer, (name) =>
        strings.get(name.text),
      )
      fields.set(
        member.name.text,
        text === undefined
          ? member.initializer
          : ts.factory.createStringLiteral(text),
      )
    }
  }
  return fields
}

function moduleStrings(file: ts.SourceFile): ReadonlyMap<string, string> {
  const strings = new Map<string, string>()
  for (const statement of file.statements) {
    if (
      !ts.isVariableStatement(statement) ||
      (statement.declarationList.flags & ts.NodeFlags.Const) === 0
    )
      continue
    for (const { name, initializer } of statement.declarationList
      .declarations) {
      if (!ts.isIdentifier(name) || initializer === undefined) continue
      const value = immutableString(initializer, (part) =>
        strings.get(part.text),
      )
      if (value !== undefined) strings.set(name.text, value)
    }
  }
  return strings
}

function immutableString(
  expression: ts.Expression,
  lookup: (name: ts.Identifier) => string | undefined,
): string | undefined {
  const node = unparenthesize(expression)
  if (ts.isStringLiteralLike(node)) return node.text
  if (ts.isIdentifier(node)) return lookup(node)
  if (ts.isTemplateExpression(node)) {
    let text = node.head.text
    for (const span of node.templateSpans) {
      const part = immutableString(span.expression, lookup)
      if (part === undefined) return undefined
      text += part + span.literal.text
    }
    return text
  }
}

function angularNodes(
  markup: string,
  base: number,
  escapedString: boolean,
  liveRegistered = false,
  localSelectors?: ReadonlySet<string>,
): MarkupNode[] {
  const { rootNodes } = new HtmlParser().parse(markup, '', {
    escapedString,
    tokenizeBlocks: true,
    tokenizeLet: true,
    tokenizeExpansionForms: true,
  })
  const nodes = rootNodes.flatMap(read)
  return bindTemplateScopes(nodes, { names: new Map(), angular: true })

  function read(node: AngularNode): MarkupNode[] {
    const offset = base + node.sourceSpan.start.offset
    if (node instanceof AngularText)
      return [{ kind: 'text', text: node.value, offset }]
    if (node instanceof LetDeclaration)
      return [
        {
          kind: 'branch',
          name: '@let',
          attribute: node.name,
          expression: node.value,
          children: [],
          offset,
        },
      ]
    if (node instanceof Block) {
      if (node.name === 'switch') {
        const value = switchLiteral(node)
        const arms = node.children.filter(
          (child): child is Block => child instanceof Block,
        )
        const values = arms
          .filter(({ name }) => name === 'case')
          .map(switchLiteral)
        if (value !== undefined && values.every((arm) => arm !== undefined)) {
          const selected =
            arms.find(
              (arm) => arm.name === 'case' && switchLiteral(arm) === value,
            ) ?? arms.find(({ name }) => name === 'default')
          // Keep the unreachable arms available to the reserved ID check.
          return arms.map((arm) => ({
            kind: 'branch',
            name: 'when',
            expression: arm === selected ? 'true' : 'false',
            children: arm.children.flatMap(read),
            offset: base + arm.sourceSpan.start.offset,
          }))
        }
      }
      return [
        {
          kind: 'branch',
          name: node.name,
          expression: node.parameters
            .map((parameter) => parameter.expression)
            .join('; '),
          children: node.children.flatMap(read),
          offset,
        },
      ]
    }
    if (node instanceof Expansion) {
      return node.cases.map((arm) => ({
        kind: 'branch',
        name: arm.value,
        expression: node.switchValue,
        children: arm.expression.flatMap(read),
        offset: base + arm.sourceSpan.start.offset,
      }))
    }
    if (!(node instanceof AngularElement)) return []
    const name = splitNsName(node.name)[1]
    const attributes = node.attrs.map((attr): MarkupAttribute => {
      const binding = attr.name.startsWith('[') || attr.name.startsWith('bind-')
      const interpolation =
        attr.name === 'class' && attr.value.includes('{{')
          ? angularBindingParser.splitInterpolation(
              attr.value,
              attr.valueSpan ?? attr.sourceSpan,
              [],
              attr.valueTokens ?? null,
            )
          : undefined
      const literal = binding
        ? angularDataId(attr.name)
          ? literalExpression(attr.value)
          : undefined
        : attr.value.includes('{{')
          ? undefined
          : attr.value
      return {
        name: attr.name,
        value: attr.value,
        literal,
        interpolation: interpolation && {
          strings: interpolation.strings.map(({ text }) => text),
          expressions: interpolation.expressions.map(({ text }) => text),
        },
        offset: base + attr.sourceSpan.start.offset,
      }
    })
    const sdk = !localSelectors?.has(name)
    return [
      {
        kind: 'element',
        name,
        attributes,
        children: node.children.flatMap(read),
        offset,
        sdkLive: sdk && liveRegistered && name === 'hilos-table-live',
        sdkViewport: sdk && name === 'hilos-viewport-table',
        sdkBoundary:
          sdk &&
          (name === 'hilos-viewport-table' || name === 'hilos-table-selection')
            ? 'table'
            : sdk && name === 'hilos-modal'
              ? 'modal'
              : undefined,
        ngIfBranches: angularIfBranches(node),
        ngTemplateOutlet: angularTemplateOutlet(node),
      },
    ]
  }
}

function switchLiteral(block: Block): string | undefined {
  const expression = readMarkupExpression(block.parameters[0]?.expression ?? '')
  if (expression === undefined) return undefined
  if (ts.isStringLiteralLike(expression)) return JSON.stringify(expression.text)
  if (ts.isNumericLiteral(expression)) return String(Number(expression.text))
  if (
    expression.kind === ts.SyntaxKind.TrueKeyword ||
    expression.kind === ts.SyntaxKind.FalseKeyword ||
    expression.kind === ts.SyntaxKind.NullKeyword
  )
    return expression.getText()
}

function bindTemplateScopes(
  nodes: readonly MarkupNode[],
  outer?: MarkupBindings,
): MarkupNode[] {
  let inherited = outer
  const bound: MarkupNode[] = []
  for (const node of nodes) {
    if (
      node.kind === 'branch' &&
      node.name === '@let' &&
      node.attribute !== undefined
    ) {
      const key = scopedName(node.attribute, node.offset)
      const names = new Map(inherited?.names).set(node.attribute, key)
      const literal = booleanLiteral(readMarkupExpression(node.expression))
      const literals =
        literal === undefined
          ? inherited?.literals
          : new Map(inherited?.literals).set(key, literal)
      inherited = { ...inherited, names, literals }
      continue
    }
    bound.push(node)
    if (node.kind === 'text') continue
    const names: string[] = []
    if (node.kind === 'element') {
      for (const { name, value } of node.attributes) {
        if (name.startsWith('let-')) names.push(name.slice(4))
        else if (name.startsWith('v-slot:') || name === 'v-slot')
          names.push(...bindingNames(value))
        else if (name === 'v-for')
          names.push(...bindingNames(value.split(/\s+(?:in|of)\s+/, 1)[0]))
        else if (name.startsWith('*')) {
          for (const match of value.matchAll(
            /\blet\s+([\w$]+)|\bas\s+([\w$]+)/g,
          ))
            names.push(match[1] ?? match[2])
        }
      }
    } else {
      for (const match of node.expression.matchAll(
        /(?:^|;)\s*(?:as|let)\s+([\w$]+)|^\s*([\w$]+)\s+of\s+/g,
      ))
        names.push(match[1] ?? match[2])
    }
    node.gateBindings = inherited
    node.bindings =
      names.length === 0
        ? inherited
        : {
            ...inherited,
            names: new Map([
              ...(inherited?.names ?? []),
              ...names.map(
                (name) => [name, scopedName(name, node.offset)] as const,
              ),
            ]),
          }
    node.children = bindTemplateScopes(node.children, node.bindings)
  }
  return bound
}

function bindingNames(value: string): string[] {
  const expression = readMarkupExpression(
    `(${value.replace(/^\((.*)\)$/, '$1')}) => null`,
  )
  return expression !== undefined && ts.isArrowFunction(expression)
    ? expression.parameters.flatMap(({ name }) =>
        identifiers(name).map(({ text }) => text),
      )
    : []
}

function identifiers(name: ts.BindingName): ts.Identifier[] {
  return ts.isIdentifier(name)
    ? [name]
    : name.elements.flatMap((entry) =>
        ts.isBindingElement(entry) ? identifiers(entry.name) : [],
      )
}

function scopedName(name: string, offset: number): string {
  return `$scope${offset}$${name}`
}

function angularDirectiveBinding(
  element: AngularElement,
  directive: string,
  aliases: string[] = [],
): (key: string) => string | undefined {
  const shorthand = element.attrs.find(({ name }) => name === `*${directive}`)
  if (shorthand !== undefined) {
    const span = shorthand.valueSpan ?? shorthand.sourceSpan
    const { templateBindings } = angularBindingParser.parseTemplateBindings(
      directive,
      shorthand.value,
      span,
      shorthand.sourceSpan.start.offset,
      span.start.offset,
    )
    for (const binding of templateBindings)
      if (
        binding instanceof VariableBinding &&
        ['$implicit', directive].includes(binding.value?.source ?? '$implicit')
      )
        aliases.push(binding.key.source)
    return (key) => {
      const binding = templateBindings.find(
        ({ key: name }) => name.source === key,
      )
      return binding instanceof ExpressionBinding
        ? binding.value?.source?.trim()
        : undefined
    }
  }
  return (key) =>
    element.attrs
      .find(({ name }) => name === `[${key}]` || name === `bind-${key}`)
      ?.value.trim()
}

function angularIfBranches(
  element: AngularElement,
): MarkupElement['ngIfBranches'] {
  const aliases: string[] = []
  const binding = angularDirectiveBinding(element, 'ngIf', aliases)
  const condition = binding('ngIf')
  if (condition === undefined) return undefined
  return {
    condition,
    then: binding('ngIfThen'),
    else: binding('ngIfElse'),
    aliases,
  }
}

function angularTemplateOutlet(
  element: AngularElement,
): MarkupElement['ngTemplateOutlet'] {
  const binding = angularDirectiveBinding(element, 'ngTemplateOutlet')
  const target = binding('ngTemplateOutlet')
  if (target !== undefined)
    return {
      target,
      context: binding('ngTemplateOutletContext'),
      shorthand: element.attrs.some(({ name }) => name === '*ngTemplateOutlet'),
    }
}

function jsxTemplates(
  path: string,
  source: string,
  viewport: boolean,
  owner: boolean,
): MarkupTemplate[] {
  const file = ts.createSourceFile(
    path,
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  const liveBindings = viewport
    ? importsFrom(file, './HilosTableLive.js', LIVE_COMPONENT)
    : []
  const tableBindings = sdkBindings(path, file, 'react', VIEWPORT_COMPONENT)
  const selectionBindings = sdkBindings(
    path,
    file,
    'react',
    SELECTION_COMPONENT,
  )
  const modalBindings = sdkBindings(path, file, 'react', MODAL_COMPONENT)
  const namespaces = sdkNamespaces(file, 'react')
  const checker = bindingChecker(file)
  const component =
    viewport || owner
      ? exportedComponent(
          file,
          checker,
          owner ? LIVE_COMPONENT : VIEWPORT_COMPONENT,
        )
      : undefined
  const componentRoot =
    component === undefined ? undefined : componentBody(component)
  const templates: MarkupTemplate[] = []
  const matches = (
    tag: ts.JsxTagNameExpression,
    bindings: ts.Identifier[],
    exported?: string,
  ): boolean => {
    if (ts.isIdentifier(tag)) return refersTo(checker, tag, bindings)
    return (
      ts.isPropertyAccessExpression(tag) &&
      tag.name.text === exported &&
      ts.isIdentifier(tag.expression) &&
      (refersTo(checker, tag.expression, namespaces) ||
        (exported !== undefined &&
          refersTo(
            checker,
            tag.expression,
            importsFrom(file, sdkModule(path, 'react', exported), '*'),
          )))
    )
  }
  const isLive: JsxBinding = (tag) => matches(tag, liveBindings)
  const isViewport: JsxBinding = (tag) =>
    matches(tag, tableBindings, VIEWPORT_COMPONENT)
  const boundary: JsxBoundary = (tag) =>
    isViewport(tag) || matches(tag, selectionBindings, SELECTION_COMPONENT)
      ? 'table'
      : matches(tag, modalBindings, MODAL_COMPONENT)
        ? 'modal'
        : undefined
  const rendering = {
    checker,
    isViewport,
    component: owner ? componentBodyNode(component) : undefined,
    directMounts: viewport,
  }
  const read = (node: ts.Node): MarkupNode[] =>
    jsxNodes(node, file, isLive, boundary, rendering)
  if (componentRoot !== undefined) {
    const nodes = read(componentRoot)
    templates.push({
      nodes,
      offset: owner && nodes.length === 0 ? 0 : componentRoot.getStart(file),
      viewport,
    })
  }
  if (owner) return templates

  function visit(node: ts.Node): void {
    if (isComponentFunction(node)) {
      const body = node.body
      if (
        body !== undefined &&
        (componentRoot === undefined || node !== componentBodyNode(component))
      )
        templates.push({
          nodes: read(body),
          offset: body.getStart(file),
          viewport: false,
        })
    }
    if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
      templates.push({
        nodes: jsxNodes(node, file, isLive, boundary),
        offset: node.getStart(file),
        viewport: false,
        reservedOnly: true,
      })
    }
    ts.forEachChild(node, visit)
  }
  visit(file)
  return templates
}

function isComponentFunction(
  node: ts.Node,
): node is ts.FunctionLikeDeclaration {
  if (
    !ts.isFunctionDeclaration(node) &&
    !ts.isArrowFunction(node) &&
    !ts.isFunctionExpression(node)
  )
    return false
  let value: ts.Node = node
  for (let parent = node.parent; parent !== undefined; parent = parent.parent)
    if (ts.isFunctionLike(parent)) return false
  while (ts.isParenthesizedExpression(value.parent)) value = value.parent
  if (
    ts.isExportAssignment(value.parent) ||
    (ts.isFunctionDeclaration(node) &&
      node.modifiers?.some(({ kind }) => kind === ts.SyntaxKind.DefaultKeyword))
  )
    return true
  const name = ts.isVariableDeclaration(value.parent)
    ? value.parent.name
    : node.name
  return name !== undefined && ts.isIdentifier(name) && /^[A-Z]/.test(name.text)
}

function componentBodyNode(
  component: ts.Node | undefined,
): ts.Node | undefined {
  if (component === undefined) return undefined
  const body = componentBody(component)
  return body?.parent
}

function sdkNamespaces(
  file: ts.SourceFile,
  framework: 'vue' | 'react',
): ts.Identifier[] {
  return [
    ...importsFrom(file, `@hilos/${framework}`, '*'),
    ...importsFrom(file, `@hilos/${framework}/base`, '*'),
  ]
}

function sdkBindings(
  path: string,
  file: ts.SourceFile,
  framework: 'vue' | 'react',
  component: string,
): ts.Identifier[] {
  return [
    ...importsFrom(file, `@hilos/${framework}`, component),
    ...importsFrom(file, `@hilos/${framework}/base`, component),
    ...importsFrom(
      file,
      sdkModule(path, framework, component),
      framework === 'vue' ? 'default' : component,
    ),
  ]
}

function sdkModule(
  path: string,
  framework: 'vue' | 'react',
  component: string,
): string {
  const extension = framework === 'vue' ? 'vue' : 'js'
  const module = posix.relative(
    posix.dirname(path),
    `framework/frontend/${framework}/src/${component}.${extension}`,
  )
  return module.startsWith('../') ? module : `./${module}`
}

export function jsxTableContent(name: string): boolean {
  return JSX_TABLE_CONTENT.has(name)
}

function exportedComponent(
  file: ts.SourceFile,
  checker: ts.TypeChecker,
  component: string,
): ts.Node | undefined {
  const module = checker.getSymbolAtLocation(file)
  if (module === undefined) return undefined
  const exports = checker.getExportsOfModule(module)
  const symbol =
    exports.find(({ name }) => name === component) ??
    exports.find(({ name }) => name === 'default')
  if (symbol === undefined) return undefined
  const local =
    symbol.flags & ts.SymbolFlags.Alias
      ? checker.getAliasedSymbol(symbol)
      : symbol
  const declaration = local.valueDeclaration
  if (declaration?.getSourceFile() !== file) return undefined
  return ts.isExportAssignment(declaration)
    ? declaration.expression
    : declaration
}

function componentBody(component: ts.Node): ts.ConciseBody | undefined {
  if (ts.isFunctionDeclaration(component) || ts.isMethodDeclaration(component))
    return component.body
  const value = ts.isVariableDeclaration(component)
    ? component.initializer
    : component
  if (value === undefined) return undefined
  const expression = ts.isParenthesizedExpression(value)
    ? unparenthesize(value)
    : value
  if (ts.isArrowFunction(expression) || ts.isFunctionExpression(expression))
    return expression.body
}

interface JsxRendering {
  checker: ts.TypeChecker
  isViewport: JsxBinding
  component?: ts.Node
  directMounts: boolean
}

const JSX_VALUE_REVIEW = 'Check which React value renders this table content'
const JSX_BODY_REVIEW =
  'Check whether the React modal body renders this table content'
const JSX_RENDERER_REVIEW =
  'Check whether the React renderer supplies this table content'

function jsxAttributes(
  opening: ts.JsxOpeningLikeElement,
  file: ts.SourceFile,
  owner: boolean,
): Pick<MarkupElement, 'attributes' | 'unknownJsxAttributes'> {
  const attributes: MarkupAttribute[] = []
  let unknownJsxAttributes = false
  for (const attr of opening.attributes.properties) {
    if (ts.isJsxSpreadAttribute(attr)) {
      if (!owner) continue
      const value = unparenthesize(attr.expression)
      if (!ts.isObjectLiteralExpression(value)) {
        unknownJsxAttributes = true
        continue
      }
      for (const entry of value.properties) {
        if (
          !ts.isPropertyAssignment(entry) ||
          !(ts.isIdentifier(entry.name) || ts.isStringLiteral(entry.name))
        ) {
          unknownJsxAttributes = true
          continue
        }
        const literal = stringValue(entry.initializer)
        attributes.push({
          name: entry.name.text,
          value: literal ?? entry.initializer.getText(file),
          literal,
          offset: entry.getStart(file),
        })
      }
      continue
    }
    const name = attr.name.getText(file)
    const initializer = attr.initializer
    const expression =
      initializer && ts.isJsxExpression(initializer)
        ? initializer.expression
        : undefined
    const literal =
      initializer && ts.isStringLiteral(initializer)
        ? initializer.text
        : expression && stringValue(expression)
    attributes.push({
      name,
      value: expression?.getText(file) ?? literal ?? '',
      literal,
      jsxLiteralValues:
        name === 'data-id' && expression !== undefined
          ? jsxStringLeaves(expression)
          : undefined,
      offset: attr.getStart(file),
    })
  }
  return {
    attributes: owner
      ? [...new Map(attributes.map((attr) => [attr.name, attr])).values()]
      : attributes,
    unknownJsxAttributes,
  }
}

function jsxNodes(
  node: ts.Node,
  file: ts.SourceFile,
  isLive: JsxBinding,
  boundary: JsxBoundary,
  rendering?: JsxRendering,
  seen: ReadonlySet<ts.Node> = new Set(),
  uses: readonly number[] = [],
  captured = false,
): MarkupNode[] {
  if (seen.has(node)) return []
  const path = rendering ? new Set(seen).add(node) : seen
  const read = (child: ts.Node): MarkupNode[] =>
    jsxNodes(child, file, isLive, boundary, rendering, path, uses)
  if (ts.isJsxFragment(node)) return node.children.flatMap(read)
  if (ts.isJsxText(node))
    return [{ kind: 'text', text: node.text, offset: node.getFullStart() }]
  const offset = node.getStart(file)
  if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
    const opening = ts.isJsxElement(node) ? node.openingElement : node
    const children =
      rendering !== undefined && ts.isJsxElement(node)
        ? node.children.flatMap(read)
        : []
    const sdkLive = isLive(opening.tagName)
    const sdkViewport = rendering?.isViewport(opening.tagName)
    const sdkBoundary = boundary(opening.tagName)
    const projected: MarkupNode[] = []
    let fallback = children
    let body: JsxValueChoices | undefined
    for (const attr of opening.attributes.properties) {
      if (rendering === undefined) break
      if (ts.isJsxSpreadAttribute(attr)) {
        if (sdkBoundary === 'modal') body = UNKNOWN_JSX_VALUE
        continue
      }
      if (
        !(sdkViewport
          ? jsxTableContent(attr.name.getText(file))
          : sdkBoundary === 'modal' &&
            ['header', 'body', 'actions', 'children'].includes(
              attr.name.getText(file),
            ))
      )
        continue
      const name = attr.name.getText(file)
      const expression =
        attr.initializer && ts.isJsxExpression(attr.initializer)
          ? attr.initializer.expression
          : attr.initializer
      const content = expression ? read(expression) : []
      if (name === 'body')
        body =
          expression === undefined
            ? UNKNOWN_JSX_VALUE
            : jsxBodyChoices(expression, rendering)
      if (content.length === 0) continue
      const projection: MarkupNode = {
        kind: 'branch',
        name: 'attribute',
        expression: attr.getText(file),
        attribute: name,
        children: content,
        offset: attr.getStart(file),
      }
      if (name === 'children') fallback = [...fallback, projection]
      else projected.push(projection)
    }
    const defaultBody =
      body === undefined
        ? fallback
        : [
            ...jsxUnderGates(fallback, body.no),
            ...jsxUnderGates(
              reviewBranch(fallback, JSX_BODY_REVIEW, offset),
              body.unknown,
            ),
          ]
    return [
      {
        kind: 'element',
        name: opening.tagName.getText(file),
        ...jsxAttributes(opening, file, rendering?.component !== undefined),
        children: [...defaultBody, ...projected],
        offset,
        sdkLive,
        sdkBoundary,
        sdkViewport,
        jsxUses: sdkLive ? uses : undefined,
        bindings: jsxBindings(node, rendering),
      },
    ]
  }
  if (ts.isJsxExpression(node)) {
    if (node.expression === undefined) return []
    return rendered(node.expression, offset)
  }
  if (ts.isConditionalExpression(node)) {
    const arm = (value: ts.Expression): MarkupNode[] =>
      rendered(value, value.getStart(file), captured)
    // A later render test cannot select a branch of an earlier snapshot.
    if (
      captured &&
      rendering !== undefined &&
      readsMutableCondition(node.condition, rendering.checker)
    )
      return reviewBranch(
        [...arm(node.whenTrue), ...arm(node.whenFalse)],
        JSX_VALUE_REVIEW,
        offset,
      )
    const expression = jsxTest(node.condition, rendering)
    const fact = jsxCondition(node.condition, expression, rendering)
    if (fact !== undefined)
      return indirectMount(arm(fact ? node.whenTrue : node.whenFalse))
    return [
      {
        kind: 'branch',
        name: 'if',
        expression,
        bindings: jsxBindings(node.condition, rendering),
        children: arm(node.whenTrue),
        offset,
      },
      {
        kind: 'branch',
        name: 'else',
        expression,
        bindings: jsxBindings(node.condition, rendering),
        children: arm(node.whenFalse),
        offset: node.whenFalse.getStart(file),
      },
    ]
  }
  if (jsxLogical(node)) {
    const conjunction =
      node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
    const nonNull =
      node.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken
    const leftValue = resolveJsxValue(node.left, rendering?.checker)
    const directTest = !nonNull && jsxDirectTest(node.left, rendering)
    const choices =
      rendering &&
      !directTest &&
      (!conjunction ||
        (leftValue !== undefined &&
          (ts.isConditionalExpression(leftValue) ||
            (ts.isExpression(leftValue) &&
              jsxLiteralTest(leftValue, rendering.checker, nonNull) !==
                undefined)))) &&
      jsxValueChoices(node.left, rendering, (value) =>
        ts.isExpression(value)
          ? jsxLiteralTest(value, rendering.checker, nonNull)
          : undefined,
      )
    if (choices) {
      const left = conjunction ? choices.no : choices.yes
      const right = conjunction ? choices.yes : choices.no
      const fallback = rendered(node.right)
      return indirectMount([
        ...(left.length === 0 && choices.unknown.length === 0
          ? []
          : jsxUnderGates(rendered(node.left), [...left, ...choices.unknown])),
        ...(right.length === 0 ? [] : jsxUnderGates(fallback, right)),
        ...jsxUnderGates(
          reviewBranch(fallback, JSX_VALUE_REVIEW, offset),
          choices.unknown,
        ),
      ])
    }
    const expression = jsxTest(node.left, rendering, nonNull)
    const fact = jsxCondition(node.left, expression, rendering)
    if (fact !== undefined)
      return indirectMount(
        fact === conjunction ? rendered(node.right) : rendered(node.left),
      )
    const condition = readMarkupExpression(expression)
    const negated =
      condition !== undefined &&
      ts.isPrefixUnaryExpression(condition) &&
      condition.operator === ts.SyntaxKind.ExclamationToken
    return [
      ...(conjunction ? [] : read(node.left)),
      {
        kind: 'branch',
        name: negated === conjunction ? 'unless' : 'when',
        bindings: jsxBindings(node.left, rendering),
        expression: negated ? condition.operand.getText() : expression,
        children: rendered(node.right),
        offset,
      },
    ]
  }
  if (rendering !== undefined) {
    if (ts.isBlock(node)) return returns(node.statements)
    if (ts.isReturnStatement(node))
      return node.expression === undefined ? [] : rendered(node.expression)
    const body = componentBody(node)
    if (body !== undefined) return indirectMount(read(body))
    const value = ts.isIdentifier(node)
      ? resolveJsxValue(node, rendering.checker)
      : node
    if (value !== undefined && ts.isNumericLiteral(value))
      return [{ kind: 'text', text: String(Number(value.text)), offset }]
    if (value !== undefined && ts.isStringLiteralLike(value))
      return [{ kind: 'text', text: value.text, offset }]
    if (ts.isIdentifier(node)) {
      const declaration =
        rendering.checker.getSymbolAtLocation(node)?.valueDeclaration
      return readDeclaration(declaration, offset)
    }
    if (ts.isObjectLiteralExpression(node))
      return node.properties.flatMap((entry): MarkupNode[] => {
        const children = ts.isPropertyAssignment(entry)
          ? read(entry.initializer)
          : ts.isShorthandPropertyAssignment(entry)
            ? readDeclaration(
                rendering.checker.getShorthandAssignmentValueSymbol(entry)
                  ?.valueDeclaration,
                entry.name.getStart(file),
              )
            : ts.isMethodDeclaration(entry)
              ? read(entry)
              : []
        return children.length === 0
          ? []
          : [
              {
                kind: 'branch',
                name: 'render',
                expression: '',
                children,
                offset: entry.getStart(file),
              },
            ]
      })
    if (ts.isArrayLiteralExpression(node))
      return indirectMount(node.elements.flatMap(read))
    if (ts.isCallExpression(node))
      return reviewBranch(
        node.arguments.flatMap(read),
        JSX_RENDERER_REVIEW,
        offset,
      )
    if (
      ts.isParenthesizedExpression(node) ||
      ts.isAsExpression(node) ||
      ts.isSatisfiesExpression(node)
    )
      return read(node.expression)
    return []
  }
  const children: MarkupNode[] = []
  ts.forEachChild(node, (child) => {
    children.push(...read(child))
  })
  return children

  function indirectMount(children: MarkupNode[]): MarkupNode[] {
    return rendering?.directMounts
      ? [{ kind: 'branch', name: 'render', expression: '', children, offset }]
      : children
  }

  function readDeclaration(
    declaration: ts.Declaration | undefined,
    use: number,
  ): MarkupNode[] {
    const value = jsxDeclarationValue(declaration, file)
    return value === undefined
      ? []
      : jsxNodes(
          ts.isExpression(value) ? unparenthesize(value) : value,
          file,
          isLive,
          boundary,
          rendering,
          path,
          [...uses, use],
          ts.isExpression(value),
        )
  }

  function returns(
    statements: readonly ts.Statement[],
    fallback: () => MarkupNode[] = () => [],
  ): MarkupNode[] {
    for (let index = 0; index < statements.length; index++) {
      const statement = statements[index]
      if (!hasDirectReturn(statement)) continue
      let tail: MarkupNode[] | undefined
      const remaining = () =>
        (tail ??= returns(statements.slice(index + 1), fallback))
      return statementReturns(statement, remaining)
    }
    return fallback()
  }

  function statementReturns(
    statement: ts.Statement,
    remaining: () => MarkupNode[],
  ): MarkupNode[] {
    if (ts.isReturnStatement(statement)) return read(statement)
    if (ts.isBlock(statement)) return returns(statement.statements, remaining)
    if (ts.isIfStatement(statement)) {
      const arm = (branch: ts.Statement | undefined): MarkupNode[] =>
        branch === undefined ? remaining() : statementReturns(branch, remaining)
      const expression = jsxTest(statement.expression, rendering)
      const enabled = jsxCondition(statement.expression, expression, rendering)
      if (enabled !== undefined) {
        const selected = enabled
          ? statement.thenStatement
          : statement.elseStatement
        return selected === undefined
          ? remaining()
          : indirectMount(arm(selected))
      }
      return [true, false].map((enabled) => ({
        kind: 'branch',
        name: enabled ? 'when' : 'unless',
        expression,
        bindings: jsxBindings(statement.expression, rendering),
        offset: statement.getStart(file),
        children: arm(
          enabled ? statement.thenStatement : statement.elseStatement,
        ),
      }))
    }
    return remaining()
  }

  function rendered(
    value: ts.Expression,
    start = value.getStart(file),
    snapshot = false,
  ): MarkupNode[] {
    const expression = unparenthesize(value)
    const content = snapshot
      ? jsxNodes(
          expression,
          file,
          isLive,
          boundary,
          rendering,
          path,
          uses,
          true,
        )
      : read(expression)
    if (
      isJsxMarkup(expression) ||
      (content.length > 0 && rendering !== undefined)
    )
      return content
    const resolved = resolveJsxValue(expression, rendering?.checker)
    if (
      resolved !== undefined &&
      ts.isExpression(resolved) &&
      (isNullishJsx(resolved, rendering?.checker) ||
        booleanLiteral(resolved) !== undefined)
    )
      return []
    return [
      {
        kind: 'branch',
        name: 'expression',
        expression: expression.getText(file),
        children: content,
        offset: start,
      },
    ]
  }
}

function isJsxMarkup(node: ts.Node): boolean {
  return (
    ts.isJsxElement(node) ||
    ts.isJsxSelfClosingElement(node) ||
    ts.isJsxFragment(node) ||
    ts.isConditionalExpression(node) ||
    jsxLogical(node)
  )
}

function jsxLogical(node: ts.Node): node is ts.BinaryExpression {
  return (
    ts.isBinaryExpression(node) &&
    (node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken ||
      node.operatorToken.kind === ts.SyntaxKind.BarBarToken ||
      node.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken)
  )
}

function jsxDirectTest(
  value: ts.Expression,
  rendering: JsxRendering | undefined,
): boolean {
  const node = unparenthesize(value)
  if (ts.isPrefixUnaryExpression(node))
    return node.operator === ts.SyntaxKind.ExclamationToken
  if (!ts.isBinaryExpression(node)) return false
  const operator = node.operatorToken.kind
  return (
    (operator >= ts.SyntaxKind.LessThanToken &&
      operator <= ts.SyntaxKind.ExclamationEqualsEqualsToken) ||
    (operator === ts.SyntaxKind.AmpersandAmpersandToken &&
      jsxLiteralTest(unparenthesize(node.right), rendering?.checker, false) ===
        true)
  )
}

function jsxLiteralTest(
  node: ts.Expression,
  checker: ts.TypeChecker | undefined,
  nonNull: boolean,
): boolean | undefined {
  if (isNullishJsx(node, checker)) return false
  if (
    ts.isJsxElement(node) ||
    ts.isJsxFragment(node) ||
    ts.isJsxSelfClosingElement(node)
  )
    return true
  const literal = booleanLiteral(node)
  if (literal !== undefined) return nonNull || literal
  if (ts.isStringLiteralLike(node) || ts.isNumericLiteral(node))
    return (
      nonNull ||
      Boolean(ts.isNumericLiteral(node) ? Number(node.text) : node.text)
    )
}

function jsxTest(
  expression: ts.Expression,
  rendering: JsxRendering | undefined,
  nonNull = false,
): string {
  const checker = rendering?.checker
  const resolved = resolveJsxValue(expression, checker)
  const node =
    resolved && ts.isExpression(resolved)
      ? resolved
      : unparenthesize(expression)
  const literal = jsxLiteralTest(node, checker, nonNull)
  if (literal !== undefined) return String(literal)
  if (node !== unparenthesize(expression)) {
    // Only carry a Boolean proved at the initializer's own scope and time.
    const test = jsxTest(node, rendering, nonNull)
    const known = jsxCondition(node, test, rendering)
    if (known !== undefined) return String(known)
  } else if (!nonNull && jsxLogical(node)) {
    const operator = node.operatorToken.kind
    const left = jsxTest(
      node.left,
      rendering,
      operator === ts.SyntaxKind.QuestionQuestionToken,
    )
    const right = jsxTest(node.right, rendering)
    if (operator === ts.SyntaxKind.QuestionQuestionToken)
      return `(${left}) ? (${jsxTest(node.left, rendering)}) : (${right})`
    const neutral = String(operator === ts.SyntaxKind.AmpersandAmpersandToken)
    if (left === neutral) return right
    if (right === neutral) return left
    return `(${left}) ${node.operatorToken.getText()} (${right})`
  } else if (!nonNull && ts.isConditionalExpression(node)) {
    return `(${jsxTest(node.condition, rendering)}) ? (${jsxTest(node.whenTrue, rendering)}) : (${jsxTest(node.whenFalse, rendering)})`
  }
  const source = unparenthesize(expression).getText()
  return nonNull ? `(${source}) != null` : source
}

function jsxCondition(
  at: ts.Expression,
  test: string,
  rendering: JsxRendering | undefined,
): boolean | undefined {
  if (test === 'true' || test === 'false') return test === 'true'
  const expression =
    unparenthesize(at).getText() === test ? at : readMarkupExpression(test)
  if (expression === undefined) return undefined
  const bindings = jsxBindings(at, rendering)
  return expressionCondition(expression, (node) => {
    const root = ts.isPropertyAccessExpression(node) ? node.expression : node
    if (!ts.isIdentifier(root)) return undefined
    const key = bindings?.names.get(root.text)
    if (key === undefined) return undefined
    return bindings?.literals?.get(
      ts.isPropertyAccessExpression(node) ? `${key}.${node.name.text}` : key,
    )
  })
}

function hasDirectReturn(statement: ts.Statement): boolean {
  if (ts.isReturnStatement(statement)) return true
  if (ts.isBlock(statement)) return statement.statements.some(hasDirectReturn)
  return (
    ts.isIfStatement(statement) &&
    (hasDirectReturn(statement.thenStatement) ||
      (statement.elseStatement !== undefined &&
        hasDirectReturn(statement.elseStatement)))
  )
}

function jsxDeclarationValue(
  declaration: ts.Declaration | undefined,
  file: ts.SourceFile,
): ts.Expression | ts.FunctionDeclaration | undefined {
  if (declaration === undefined || declaration.getSourceFile() !== file)
    return undefined
  if (ts.isFunctionDeclaration(declaration)) return declaration
  if (
    ts.isVariableDeclaration(declaration) &&
    ts.isVariableDeclarationList(declaration.parent) &&
    (declaration.parent.flags & ts.NodeFlags.Const) !== 0
  )
    return declaration.initializer
}

function resolveJsxValue(
  value: ts.Expression,
  checker: ts.TypeChecker | undefined,
): ts.Expression | ts.FunctionDeclaration | undefined {
  const seen = new Set<ts.Expression>()
  let expression = unparenthesize(value)
  while (ts.isIdentifier(expression) && checker !== undefined) {
    if (seen.has(expression)) return undefined
    seen.add(expression)
    const declaration =
      checker.getSymbolAtLocation(expression)?.valueDeclaration
    const resolved = jsxDeclarationValue(
      declaration,
      expression.getSourceFile(),
    )
    if (resolved === undefined) break
    if (ts.isFunctionDeclaration(resolved)) return resolved
    if (resolved.getEnd() > expression.getStart()) return undefined
    expression = unparenthesize(resolved)
  }
  return expression
}

function isNullishJsx(
  value: ts.Expression,
  checker: ts.TypeChecker | undefined,
): boolean {
  return (
    value.kind === ts.SyntaxKind.NullKeyword ||
    (ts.isIdentifier(value) &&
      value.text === 'undefined' &&
      checker?.getSymbolAtLocation(value)?.valueDeclaration === undefined)
  )
}

interface JsxGate {
  expression: string
  bindings?: MarkupBindings
  enabled: boolean
  offset: number
}

interface JsxValueChoices {
  yes: JsxGate[][]
  no: JsxGate[][]
  unknown: JsxGate[][]
}

const UNKNOWN_JSX_VALUE: JsxValueChoices = { yes: [], no: [], unknown: [[]] }

function reviewBranch(
  children: MarkupNode[],
  review: string,
  offset: number,
): MarkupNode[] {
  return children.length === 0
    ? []
    : [
        {
          kind: 'branch',
          name: 'review',
          expression: '',
          review,
          children,
          offset,
        },
      ]
}

function jsxUnderGates(nodes: MarkupNode[], paths: JsxGate[][]): MarkupNode[] {
  if (nodes.length === 0) return []
  return paths.flatMap((path) =>
    path.reduceRight<MarkupNode[]>(
      (children, { enabled, ...gate }) => [
        {
          kind: 'branch',
          name: enabled ? 'when' : 'unless',
          ...gate,
          children,
        },
      ],
      nodes,
    ),
  )
}

function jsxBodyChoices(
  value: ts.Expression,
  rendering: JsxRendering,
): JsxValueChoices {
  return jsxValueChoices(value, rendering, (selected) => {
    if (
      ts.isFunctionDeclaration(selected) ||
      ts.isArrowFunction(selected) ||
      ts.isFunctionExpression(selected)
    )
      return true
    if (isNullishJsx(selected, rendering.checker)) return false
  })
}

function jsxValueChoices(
  value: ts.Expression,
  rendering: JsxRendering,
  classify: (
    value: ts.Expression | ts.FunctionDeclaration,
  ) => boolean | undefined,
  captured = false,
): JsxValueChoices {
  const selected = resolveJsxValue(value, rendering.checker)
  if (selected === undefined) return UNKNOWN_JSX_VALUE
  if (!ts.isConditionalExpression(selected)) {
    const result = classify(selected)
    return result === undefined
      ? UNKNOWN_JSX_VALUE
      : {
          yes: result ? [[]] : [],
          no: result ? [] : [[]],
          unknown: [],
        }
  }
  const { condition, whenTrue, whenFalse } = selected
  const snapshot = captured || selected !== unparenthesize(value)
  if (snapshot && readsMutableCondition(condition, rendering.checker))
    return UNKNOWN_JSX_VALUE
  const expression = jsxTest(condition, rendering)
  const fact = jsxCondition(condition, expression, rendering)
  if (fact !== undefined)
    return jsxValueChoices(
      fact ? whenTrue : whenFalse,
      rendering,
      classify,
      snapshot,
    )
  const yes = jsxValueChoices(whenTrue, rendering, classify, snapshot)
  const no = jsxValueChoices(whenFalse, rendering, classify, snapshot)
  const gate = {
    expression,
    bindings: jsxBindings(condition, rendering),
    offset: condition.getStart(),
  }
  return {
    yes: join(yes.yes, no.yes),
    no: join(yes.no, no.no),
    unknown: join(yes.unknown, no.unknown),
  }

  function join(first: JsxGate[][], second: JsxGate[][]): JsxGate[][] {
    if (first[0]?.length === 0 && second[0]?.length === 0) return [[]]
    return [
      ...first.map((path) => [{ ...gate, enabled: true }, ...path]),
      ...second.map((path) => [{ ...gate, enabled: false }, ...path]),
    ]
  }
}

function readsMutableCondition(
  node: ts.Node,
  checker: ts.TypeChecker,
): boolean {
  if (ts.isCallExpression(node)) return true
  if (ts.isPropertyAccessExpression(node) && ts.isIdentifier(node.expression)) {
    const receiver = resolveJsxValue(node.expression, checker)
    if (receiver !== undefined && ts.isObjectLiteralExpression(receiver))
      return true
  }
  if (ts.isIdentifier(node)) {
    const declaration = checker.getSymbolAtLocation(node)?.valueDeclaration
    if (
      declaration !== undefined &&
      ts.isVariableDeclaration(declaration) &&
      ts.isVariableDeclarationList(declaration.parent) &&
      (declaration.parent.flags & ts.NodeFlags.Const) === 0
    )
      return true
  }
  return (
    ts.forEachChild(
      node,
      (child) => readsMutableCondition(child, checker) || undefined,
    ) === true
  )
}

function jsxBindings(
  node: ts.Node,
  rendering: JsxRendering | undefined,
): MarkupBindings | undefined {
  if (rendering === undefined) return undefined
  const { checker, component } = rendering
  const names = new Map<string, string>()
  const literals = new Map<string, boolean>()
  const strings = new Map<string, string>()
  const mutableObjects = new Set<string>()
  for (let owner = node.parent; owner !== undefined; owner = owner.parent) {
    if (ts.isFunctionLike(owner) && owner !== component) {
      for (const parameter of owner.parameters) add(parameter.name)
    } else if (ts.isBlock(owner) || ts.isSourceFile(owner)) {
      for (const statement of owner.statements) {
        if (!ts.isVariableStatement(statement)) continue
        const immutable =
          (statement.declarationList.flags & ts.NodeFlags.Const) !== 0
        for (const declaration of statement.declarationList.declarations)
          add(declaration.name, immutable ? declaration.initializer : undefined)
      }
    }
  }
  return names.size === 0
    ? undefined
    : { names, literals, strings, mutableObjects }

  function add(binding: ts.BindingName, initializer?: ts.Expression): void {
    for (const name of identifiers(binding)) {
      if (names.has(name.text)) continue
      if (
        name.text === 'top' &&
        component !== undefined &&
        enclosingFunction(name) === component
      )
        continue
      const key = scopedName(name.text, name.getStart())
      names.set(name.text, key)
      if (
        name !== binding ||
        initializer === undefined ||
        initializer.getEnd() > node.getStart()
      )
        continue
      const value = resolveJsxValue(initializer, checker)
      if (value === undefined || ts.isFunctionDeclaration(value)) continue
      const literal = booleanLiteral(value)
      if (literal !== undefined) literals.set(key, literal)
      const text = immutableString(value, (reference) => {
        const resolved = resolveJsxValue(reference, checker)
        return resolved !== undefined && ts.isStringLiteralLike(resolved)
          ? resolved.text
          : undefined
      })
      if (text !== undefined) strings.set(key, text)
      if (ts.isObjectLiteralExpression(value)) mutableObjects.add(key)
    }
  }
}

function enclosingFunction(node: ts.Node): ts.Node | undefined {
  for (let parent = node.parent; parent !== undefined; parent = parent.parent)
    if (ts.isFunctionLike(parent)) return parent
}

function booleanLiteral(
  expression: ts.Expression | undefined,
): boolean | undefined {
  const kind = expression && unparenthesize(expression).kind
  if (kind === ts.SyntaxKind.TrueKeyword) return true
  if (kind === ts.SyntaxKind.FalseKeyword) return false
}

function property(
  node: ts.Node | undefined,
  name: string,
): ts.Expression | undefined {
  if (node === undefined || !ts.isObjectLiteralExpression(node))
    return undefined
  const entry = node.properties.find(
    (item): item is ts.PropertyAssignment =>
      ts.isPropertyAssignment(item) &&
      (ts.isIdentifier(item.name) || ts.isStringLiteral(item.name)) &&
      item.name.text === name,
  )
  return entry?.initializer
}

function importsFrom(
  file: ts.SourceFile,
  module: string,
  exported: string,
): ts.Identifier[] {
  const bindings: ts.Identifier[] = []
  const relative = /^\.\.?\//.test(module)
  for (const statement of file.statements) {
    if (
      !ts.isImportDeclaration(statement) ||
      !ts.isStringLiteral(statement.moduleSpecifier)
    )
      continue
    const name = statement.moduleSpecifier.text
    const matches = relative
      ? /^\.\.?\//.test(name) &&
        posix.normalize(name) === posix.normalize(module)
      : name === module
    const clause = statement.importClause
    if (!matches || clause === undefined || clause.isTypeOnly) continue
    if (exported === 'default' && clause.name !== undefined)
      bindings.push(clause.name)
    if (
      exported === '*' &&
      clause.namedBindings !== undefined &&
      ts.isNamespaceImport(clause.namedBindings)
    )
      bindings.push(clause.namedBindings.name)
    if (
      clause.namedBindings !== undefined &&
      ts.isNamedImports(clause.namedBindings)
    ) {
      for (const binding of clause.namedBindings.elements) {
        if (
          !binding.isTypeOnly &&
          (binding.propertyName ?? binding.name).text === exported
        )
          bindings.push(binding.name)
      }
    }
  }
  return bindings
}

function bindingChecker(file: ts.SourceFile): ts.TypeChecker {
  // Binding needs only this file. Resolving dependencies would pull in the SDK
  // just to tell an import from a local declaration shadowing its name.
  const options: ts.CompilerOptions = {
    noResolve: true,
    noLib: true,
    types: [],
    jsx: ts.JsxEmit.Preserve,
  }
  const host = ts.createCompilerHost(options)
  host.getSourceFile = (name) => (name === file.fileName ? file : undefined)
  return ts.createProgram([file.fileName], options, host).getTypeChecker()
}

function refersTo(
  checker: ts.TypeChecker,
  name: ts.Identifier,
  bindings: readonly ts.Identifier[],
): boolean {
  const symbol = checker.getSymbolAtLocation(name)
  return (
    symbol !== undefined &&
    bindings.some((binding) => checker.getSymbolAtLocation(binding) === symbol)
  )
}

export function unparenthesize(expression: ts.Expression): ts.Expression {
  while (ts.isParenthesizedExpression(expression))
    expression = expression.expression
  return expression
}

function jsxStringLeaves(expression: ts.Expression): string[] {
  const value = unparenthesize(expression)
  if (ts.isStringLiteralLike(value)) return [value.text]
  if (ts.isConditionalExpression(value))
    return [
      ...jsxStringLeaves(value.whenTrue),
      ...jsxStringLeaves(value.whenFalse),
    ]
  return []
}

function stringValue(expression: ts.Expression): string | undefined {
  const value = unparenthesize(expression)
  if (ts.isStringLiteralLike(value)) return value.text
  if (ts.isConditionalExpression(value)) {
    const enabled = expressionCondition(value.condition)
    if (enabled !== undefined)
      return stringValue(enabled ? value.whenTrue : value.whenFalse)
  }
}

function literalExpression(value: string): string | undefined {
  const expression = readMarkupExpression(value)
  return expression && stringValue(expression)
}

/** Reads one markup binding expression without executing the source. */
export function readMarkupExpression(value: string): ts.Expression | undefined {
  const file = ts.createSourceFile(
    'binding.ts',
    `(${value})`,
    ts.ScriptTarget.Latest,
    true,
  )
  const [statement] = file.statements
  if (file.statements.length === 1 && ts.isExpressionStatement(statement))
    return unparenthesize(statement.expression)
}

/** Resolves a simple Boolean expression from literals and known values. */
export function expressionCondition(
  expression: ts.Expression,
  known?: (node: ts.Expression) => boolean | undefined,
): boolean | undefined {
  const node = unparenthesize(expression)
  const value = known?.(node)
  if (value !== undefined) return value
  if (node.kind === ts.SyntaxKind.TrueKeyword) return true
  if (
    node.kind === ts.SyntaxKind.FalseKeyword ||
    node.kind === ts.SyntaxKind.NullKeyword
  )
    return false
  if (
    ts.isPrefixUnaryExpression(node) &&
    node.operator === ts.SyntaxKind.ExclamationToken
  ) {
    const operand = expressionCondition(node.operand, known)
    return operand === undefined ? undefined : !operand
  }
  if (ts.isConditionalExpression(node)) {
    const test = expressionCondition(node.condition, known)
    if (test !== undefined)
      return expressionCondition(test ? node.whenTrue : node.whenFalse, known)
  }
  if (ts.isBinaryExpression(node)) {
    const comparison = booleanComparison(node)
    if (comparison !== undefined) {
      const operand = expressionCondition(comparison.operand, known)
      return operand === undefined ? undefined : operand === comparison.equal
    }
    const operator = node.operatorToken.kind
    if (
      operator === ts.SyntaxKind.AmpersandAmpersandToken ||
      operator === ts.SyntaxKind.BarBarToken
    ) {
      const shortCircuit = operator === ts.SyntaxKind.BarBarToken
      const left = expressionCondition(node.left, known)
      if (left === shortCircuit) return shortCircuit
      const right = expressionCondition(node.right, known)
      if (right === shortCircuit) return shortCircuit
      if (left !== undefined && right !== undefined) return !shortCircuit
    }
  }
}

/** Reduces a comparison with a Boolean literal to the tested operand. */
export function booleanComparison(
  node: ts.BinaryExpression,
): { operand: ts.Expression; equal: boolean } | undefined {
  const operator = node.operatorToken.kind
  const equality =
    operator === ts.SyntaxKind.EqualsEqualsToken ||
    operator === ts.SyntaxKind.EqualsEqualsEqualsToken
  if (
    !equality &&
    operator !== ts.SyntaxKind.ExclamationEqualsToken &&
    operator !== ts.SyntaxKind.ExclamationEqualsEqualsToken
  )
    return undefined
  const left = booleanLiteral(node.left)
  if (left !== undefined)
    return { operand: node.right, equal: left === equality }
  const right = booleanLiteral(node.right)
  if (right !== undefined)
    return { operand: node.left, equal: right === equality }
}

export function angularDataId(name: string): boolean {
  return [
    '[attr.data-id]',
    'bind-attr.data-id',
    '[data-id]',
    'bind-data-id',
  ].includes(name)
}
