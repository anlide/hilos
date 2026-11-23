import ts from 'typescript'

import {
  type BooleanAlias,
  booleanComparison,
  expressionCondition,
  type MarkupAttribute,
  type MarkupBindings,
  type MarkupNode,
  readMarkupExpression,
  unparenthesize,
} from './tableLiveRoomMarkup.js'

export type RoomSyntax = 'vue' | 'react' | 'angular'

export interface RoomState {
  syntax: RoomSyntax
  empty: boolean
  fields?: ReadonlyMap<string, ts.Expression>
  bindings?: MarkupBindings
}

export type Conditions = ReadonlyMap<string, boolean>

export const MUTABLE_PROPERTY = 'mutable-property:'

interface Variant {
  conditions: Conditions
}

interface ClassVariant extends Variant {
  tokens: ReadonlySet<string>
}

interface StringVariant extends Variant {
  text: string
}

const EMPTY: ClassVariant = { tokens: new Set(), conditions: new Map() }
// A token touching an unreadable string fragment is incomplete.
const STRING_GAP = '\0'
export const UNKNOWN_CLASS = STRING_GAP
const EMPTY_STRING: StringVariant = { conditions: EMPTY.conditions, text: '' }
const UNKNOWN_STRING: StringVariant = { ...EMPTY_STRING, text: STRING_GAP }

export function markupCondition(
  value: string,
  syntax?: RoomSyntax,
  empty = false,
  bindings?: MarkupBindings,
): boolean | undefined {
  const expression = readMarkupExpression(value)
  if (expression !== undefined)
    return condition(expression, syntax && { syntax, empty, bindings })
}

function condition(
  expression: ts.Expression,
  room: RoomState | undefined,
  bindings = room?.bindings,
): boolean | undefined {
  return expressionCondition(
    expression,
    (node) =>
      (room && roomCondition(node, room)) ?? conditionValue(node, bindings),
  )
}

function roomCondition(
  node: ts.Expression,
  { syntax, empty, bindings }: RoomState,
): boolean | undefined {
  if (bindings?.names.has(syntax === 'react' ? 'top' : 'live')) return undefined
  if (isTop(node, syntax)) return !empty
  if (!ts.isBinaryExpression(node)) return undefined

  const left = unparenthesize(node.left)
  const right = unparenthesize(node.right)
  const other = isTop(left, syntax)
    ? right
    : isTop(right, syntax)
      ? left
      : undefined
  if (other === undefined) return undefined
  const equal =
    other.kind === ts.SyntaxKind.NullKeyword
      ? empty
      : empty && ts.isStringLiteralLike(other)
        ? false
        : undefined
  switch (node.operatorToken.kind) {
    case ts.SyntaxKind.EqualsEqualsEqualsToken:
    case ts.SyntaxKind.EqualsEqualsToken:
      return equal
    case ts.SyntaxKind.ExclamationEqualsEqualsToken:
    case ts.SyntaxKind.ExclamationEqualsToken:
      return equal === undefined ? undefined : !equal
  }
}

function isTop(node: ts.Expression, syntax: RoomSyntax): boolean {
  if (syntax === 'react') return ts.isIdentifier(node) && node.text === 'top'
  if (!ts.isPropertyAccessExpression(node) || node.name.text !== 'top')
    return false
  let receiver = unparenthesize(node.expression)
  if (syntax === 'angular') {
    if (!ts.isCallExpression(receiver) || receiver.arguments.length !== 0)
      return false
    receiver = unparenthesize(receiver.expression)
  }
  return ts.isIdentifier(receiver) && receiver.text === 'live'
}

export function mergeClassConditions(
  first: Conditions,
  second: Conditions,
): Conditions | undefined {
  if (second.size === 0) return first
  if (first.size === 0 && second.size === 1) return second
  const merged = new Map(first)
  for (const [test, enabled] of second) {
    const existing = merged.get(test)
    if (existing !== undefined && existing !== enabled) return undefined
    merged.set(test, enabled)
  }
  for (const [test, enabled] of merged) {
    if (!test.includes('&&') && !test.includes('||')) continue
    const expression = readMarkupExpression(test)
    if (expression === undefined) continue
    const actual = expressionCondition(expression, (node) =>
      node === expression ? undefined : merged.get(node.getText()),
    )
    if (actual !== undefined && actual !== enabled) return undefined
  }
  return merged
}

/** Returns the conditions under which a markup test has the requested result. */
export function withMarkupCondition(
  conditions: Conditions,
  value: string,
  enabled: boolean,
  bindings?: MarkupBindings,
  room?: RoomState,
): Conditions | undefined {
  const expression = readMarkupExpression(value)
  if (expression === undefined) return conditions
  const result = expressionCondition(
    expression,
    (node) =>
      (room && roomCondition(node, room)) ??
      conditionValue(node, bindings, conditions),
  )
  return result === undefined
    ? mergeClassConditions(
        conditions,
        assumption(expression, enabled, bindings),
      )
    : result === enabled
      ? conditions
      : undefined
}

function conditionAtom(
  node: ts.Expression,
  bindings?: MarkupBindings,
): BooleanAlias {
  const key = conditionKey(node, bindings)
  if (
    ts.isPropertyAccessExpression(node) &&
    ts.isIdentifier(node.expression) &&
    bindings?.mutableObjects?.has(conditionKey(node.expression, bindings))
  )
    return { key: MUTABLE_PROPERTY + key, inverted: false }
  return bindings?.aliases?.get(key) ?? { key, inverted: false }
}

function conditionValue(
  node: ts.Expression,
  bindings?: MarkupBindings,
  conditions?: Conditions,
): boolean | undefined {
  const { key, inverted } = conditionAtom(node, bindings)
  const value = bindings?.literals?.get(key) ?? conditions?.get(key)
  return value === undefined ? undefined : value !== inverted
}

/** Resolves a direct Boolean template context field or its negation. */
export function booleanAlias(
  value: ts.Expression,
  bindings?: MarkupBindings,
): BooleanAlias | undefined {
  const expression = unparenthesize(value)
  if (
    ts.isPrefixUnaryExpression(expression) &&
    expression.operator === ts.SyntaxKind.ExclamationToken
  ) {
    const atom = booleanAlias(expression.operand, bindings)
    return atom && { ...atom, inverted: !atom.inverted }
  }
  if (
    ts.isIdentifier(expression) ||
    (ts.isPropertyAccessExpression(expression) &&
      (ts.isIdentifier(expression.expression) ||
        expression.expression.kind === ts.SyntaxKind.ThisKeyword))
  )
    return conditionAtom(expression, bindings)
}

function conditionKey(node: ts.Expression, bindings?: MarkupBindings): string {
  if (
    bindings === undefined ||
    (!bindings.angular && bindings.names.size === 0)
  )
    return node.getText()
  let text = ''
  let start = node.getStart()
  const source = node.getSourceFile().text
  function walk(part: ts.Node): void {
    if (
      bindings?.angular &&
      ts.isPropertyAccessExpression(part) &&
      part.expression.kind === ts.SyntaxKind.ThisKeyword
    ) {
      text += source.slice(start, part.getStart()) + part.name.text
      start = part.getEnd()
      return
    }
    if (
      ts.isIdentifier(part) &&
      !(ts.isPropertyAccessExpression(part.parent) && part.parent.name === part)
    ) {
      const binding = bindings?.names.get(part.text)
      if (binding !== undefined) {
        text += source.slice(start, part.getStart()) + binding
        start = part.getEnd()
      }
    }
    ts.forEachChild(part, walk)
  }
  walk(node)
  return text + source.slice(start, node.getEnd())
}

const BREAKPOINTS = ['sm', 'md', 'lg', 'xl', 'xxl']
export const ALL_DISPLAY_RANGES = (1 << (BREAKPOINTS.length + 1)) - 1
const DISPLAY_UTILITY =
  /^d-(?:(sm|md|lg|xl|xxl)-)?(none|inline|inline-block|block|grid|inline-grid|table|table-cell|table-row|flex|inline-flex)$/

export function isDisplayUtility(name: string): boolean {
  return DISPLAY_UTILITY.test(name)
}

/** Reads the viewport ranges in which Bootstrap displays the element. */
export function displayRanges(tokens: ReadonlySet<string>): number {
  const levels = new Map<number, boolean>()
  for (const token of tokens) {
    const utility = DISPLAY_UTILITY.exec(token)
    if (utility === null) continue
    const [, breakpoint, display] = utility
    const level =
      breakpoint === undefined ? 0 : BREAKPOINTS.indexOf(breakpoint) + 1
    // At the same breakpoint, Bootstrap emits d-none after the other utilities.
    levels.set(level, levels.get(level) !== false && display !== 'none')
  }
  let shown = true
  let ranges = 0
  for (let level = 0; level <= BREAKPOINTS.length; level++) {
    shown = levels.get(level) ?? shown
    if (shown) ranges |= 1 << level
  }
  return ranges
}

export function hiddenAttributes(
  attributes: readonly MarkupAttribute[],
): MarkupAttribute[] {
  return attributes.filter(({ name }) =>
    ['hidden', 'v-bind:hidden', '[hidden]', 'bind-hidden'].includes(name),
  )
}

export function staticHidden({
  name,
  value,
  literal,
}: MarkupAttribute): boolean {
  return name === 'hidden' && (literal !== undefined || value === '')
}

/** Reads the literal utility classes this binding can supply for one room state. */
export function roomClasses(
  attribute: MarkupAttribute,
  syntax: RoomSyntax,
  empty: boolean,
  fields: ReadonlyMap<string, ts.Expression> | undefined,
  accepts: (name: string) => boolean,
  bindings?: MarkupBindings,
): ReadonlyMap<string, boolean> {
  const variants = classVariants([attribute], accepts, {
    syntax,
    empty,
    fields,
    bindings,
  })
  const names = new Set(variants.flatMap(({ tokens }) => [...tokens]))
  return new Map(
    [...names].map((name) => [
      name,
      variants.every(({ tokens }) => tokens.has(name)),
    ]),
  )
}

/** Keeps classes together only when their conditions can hold together. */
export function classVariants(
  attributes: readonly MarkupAttribute[],
  accepts: (name: string) => boolean,
  room?: RoomState,
  bindings = room?.bindings,
): ClassVariant[] {
  return classGroups(attributes.map(readAttribute))

  function tokens(
    value: string,
    conditions: Conditions = EMPTY.conditions,
  ): ClassVariant[] {
    const names = value
      .split(/\s+/)
      .filter(
        (name) => name !== '' && !name.includes(STRING_GAP) && accepts(name),
      )
    if (value.includes(STRING_GAP) && accepts(UNKNOWN_CLASS))
      names.push(UNKNOWN_CLASS)
    if (names.length === 0 && conditions.size === 0) return [EMPTY]
    return [{ tokens: new Set(names), conditions }]
  }

  function classGroups(groups: ClassVariant[][]): ClassVariant[] {
    return together(groups, EMPTY, (first, second) => ({
      tokens: new Set([...first.tokens, ...second.tokens]),
      conditions: first.conditions,
    }))
  }

  function stringGroups(groups: StringVariant[][]): StringVariant[] {
    return together(groups, EMPTY_STRING, (first, second) => ({
      text: first.text + second.text,
      conditions: first.conditions,
    }))
  }

  function choose<T extends Variant>(
    test: ts.Expression | undefined,
    whenTrue: () => T[],
    whenFalse: () => T[],
  ): T[] {
    if (test === undefined) return whenFalse()
    const enabled = condition(test, room, bindings)
    if (enabled !== undefined) return enabled ? whenTrue() : whenFalse()
    const first = whenTrue()
    const second = whenFalse()
    if (first.length === 1 && second.length === 1 && first[0] === second[0])
      return first
    return [
      ...assuming(first, test, true, bindings),
      ...assuming(second, test, false, bindings),
    ]
  }

  function str(text: string): StringVariant[] {
    return [text === '' ? EMPTY_STRING : { ...EMPTY_STRING, text }]
  }

  function asClasses(strings: StringVariant[]): ClassVariant[] {
    const variants = strings.flatMap(({ text, conditions }) =>
      tokens(text, conditions),
    )
    return variants.every(({ tokens }) => tokens.size === 0)
      ? [EMPTY]
      : variants
  }

  function readAttribute({
    name,
    value,
    literal,
    interpolation,
  }: MarkupAttribute): ClassVariant[] {
    const toggle = /^(?:\[class\.|bind-class\.)([\w-]+)\]?$/.exec(name)
    if (toggle !== null)
      return accepts(toggle[1])
        ? choose(
            readMarkupExpression(value),
            () => tokens(toggle[1]),
            () => [EMPTY],
          )
        : [EMPTY]
    if (
      !/^(?:class|className|v-bind:class|\[class\]|\[ngClass\]|bind-class|bind-ngClass)$/.test(
        name,
      )
    )
      return [EMPTY]
    if (literal !== undefined) return tokens(literal)
    if (interpolation !== undefined) {
      const { strings, expressions } = interpolation
      return asClasses(
        stringGroups([
          str(strings[0] ?? ''),
          ...expressions.flatMap((expression, index) => [
            readString(bindingExpression(expression)),
            str(strings[index + 1] ?? ''),
          ]),
        ]),
      )
    }
    if (
      !accepts(UNKNOWN_CLASS) &&
      !/['"`{]/.test(value) &&
      room?.fields === undefined &&
      !bindings?.strings?.size
    )
      return [EMPTY]
    const expression = bindingExpression(value)
    return expression === undefined ? tokens(UNKNOWN_CLASS) : read(expression)
  }

  function bindingExpression(value: string): ts.Expression | undefined {
    const expression = readMarkupExpression(value)
    const field =
      expression !== undefined && ts.isIdentifier(expression)
        ? room?.fields?.get(expression.text)
        : undefined
    return field ?? expression
  }

  function read(value: ts.Expression): ClassVariant[] {
    const node = unparenthesize(value)
    if (ts.isStringLiteralLike(node)) return tokens(node.text)
    if (ts.isIdentifier(node)) {
      const text = bindings?.strings?.get(conditionKey(node, bindings))
      return tokens(text ?? UNKNOWN_CLASS)
    }
    if (ts.isConditionalExpression(node))
      return choose(
        node.condition,
        () => read(node.whenTrue),
        () => read(node.whenFalse),
      )
    if (ts.isTemplateExpression(node)) return asClasses(readString(node))
    if (ts.isArrayLiteralExpression(node))
      return classGroups(node.elements.map(read))
    if (ts.isObjectLiteralExpression(node))
      return classGroups(
        node.properties.flatMap((entry) => {
          if (ts.isShorthandPropertyAssignment(entry))
            return [
              choose(
                entry.name,
                () => tokens(entry.name.text),
                () => [EMPTY],
              ),
            ]
          if (
            !ts.isPropertyAssignment(entry) ||
            !(ts.isIdentifier(entry.name) || ts.isStringLiteral(entry.name))
          )
            return [tokens(UNKNOWN_CLASS)]
          const name = entry.name.text
          return [
            choose(
              entry.initializer,
              () => tokens(name),
              () => [EMPTY],
            ),
          ]
        }),
      )
    if (ts.isBinaryExpression(node)) {
      if (node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken)
        return choose(
          node.left,
          () => read(node.right),
          () => [EMPTY],
        )
      if (node.operatorToken.kind === ts.SyntaxKind.PlusToken)
        return asClasses(readString(node))
    }
    if (
      node.kind === ts.SyntaxKind.NullKeyword ||
      node.kind === ts.SyntaxKind.FalseKeyword ||
      node.kind === ts.SyntaxKind.TrueKeyword
    )
      return [EMPTY]
    return tokens(UNKNOWN_CLASS)
  }

  function readString(value: ts.Expression | undefined): StringVariant[] {
    if (value === undefined) return [UNKNOWN_STRING]
    const node = unparenthesize(value)
    if (ts.isStringLiteralLike(node)) return str(node.text)
    if (ts.isIdentifier(node)) {
      const text = bindings?.strings?.get(conditionKey(node, bindings))
      return text === undefined ? [UNKNOWN_STRING] : str(text)
    }
    if (ts.isConditionalExpression(node))
      return choose(
        node.condition,
        () => readString(node.whenTrue),
        () => readString(node.whenFalse),
      )
    if (ts.isTemplateExpression(node))
      return stringGroups([
        str(node.head.text),
        ...node.templateSpans.flatMap(({ expression, literal }) => [
          readString(expression),
          str(literal.text),
        ]),
      ])
    if (ts.isBinaryExpression(node)) {
      if (node.operatorToken.kind === ts.SyntaxKind.PlusToken)
        return stringGroups([readString(node.left), readString(node.right)])
      if (node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken)
        return choose(
          node.left,
          () => readString(node.right),
          () => [UNKNOWN_STRING],
        )
    }
    return [UNKNOWN_STRING]
  }
}

function together<T extends Variant>(
  groups: T[][],
  empty: T,
  combine: (first: T, second: T) => T,
): T[] {
  return groups.reduce(
    (left, right) =>
      left.flatMap((first) =>
        right.flatMap((second) => {
          if (first === empty) return [second]
          if (second === empty) return [first]
          const conditions = mergeClassConditions(
            first.conditions,
            second.conditions,
          )
          return conditions === undefined
            ? []
            : [{ ...combine(first, second), conditions }]
        }),
      ),
    [empty],
  )
}

function assumption(
  test: ts.Expression,
  enabled: boolean,
  bindings?: MarkupBindings,
): Conditions {
  const node = unparenthesize(test)
  if (
    ts.isPrefixUnaryExpression(node) &&
    node.operator === ts.SyntaxKind.ExclamationToken
  )
    return assumption(node.operand, !enabled, bindings)
  if (ts.isBinaryExpression(node)) {
    const comparison = booleanComparison(node)
    if (comparison !== undefined)
      return assumption(
        comparison.operand,
        enabled === comparison.equal,
        bindings,
      )
  }
  const atom = conditionAtom(node, bindings)
  const facts = new Map([[atom.key, enabled !== atom.inverted]])
  if (
    ts.isBinaryExpression(node) &&
    node.operatorToken.kind ===
      (enabled
        ? ts.SyntaxKind.AmpersandAmpersandToken
        : ts.SyntaxKind.BarBarToken)
  ) {
    for (const operand of [node.left, node.right]) {
      for (const [key, value] of assumption(operand, enabled, bindings)) {
        // A contradictory literal or operand is discovered when the compound
        // test is evaluated alongside its implied facts.
        if (!facts.has(key)) facts.set(key, value)
      }
    }
  }
  return facts
}

function assuming<T extends Variant>(
  variants: T[],
  test: ts.Expression,
  enabled: boolean,
  bindings?: MarkupBindings,
): T[] {
  const required = assumption(test, enabled, bindings)
  return variants.flatMap((variant) => {
    const conditions = mergeClassConditions(variant.conditions, required)
    return conditions === undefined ? [] : [{ ...variant, conditions }]
  })
}

export interface RenderTest {
  value: string
  enabled: boolean
  bindings?: MarkupBindings
}

/** Keeps the gates of earlier arms on each Vue and Angular else-if arm. */
export function* renderSiblings(nodes: readonly MarkupNode[]): Iterable<{
  node: MarkupNode
  tests: RenderTest[]
  alternative: boolean
  continuation: boolean
}> {
  let failures: RenderTest[] = []
  let previous: RenderTest | undefined
  for (const node of nodes) {
    if (node.kind === 'text') {
      if (node.text.trim() !== '') {
        failures = []
        previous = undefined
      }
      yield { node, tests: [], alternative: false, continuation: false }
      continue
    }
    const gate = renderTest(node)
    const inherited = gate?.continuation ? failures : []
    const alternative =
      (gate?.continuation === true && failures.length > 0) ||
      (gate?.test !== undefined &&
        previous !== undefined &&
        gate.test.enabled !== previous.enabled &&
        gate.test.value === previous.value &&
        conditionKeyForTest(gate.test) === conditionKeyForTest(previous))
    const test = gate?.test
    const tests = test === undefined ? inherited : [...inherited, test]
    failures =
      gate?.chain && test !== undefined
        ? [...inherited, { ...test, enabled: !test.enabled }]
        : []
    previous = test
    yield {
      node,
      tests,
      alternative,
      continuation: gate?.continuation === true,
    }
  }
}

function conditionKeyForTest({ value, bindings }: RenderTest): string {
  const expression = readMarkupExpression(value)
  return expression === undefined ? value : conditionKey(expression, bindings)
}

function renderTest(node: Exclude<MarkupNode, { kind: 'text' }>):
  | {
      test?: RenderTest
      chain?: boolean
      continuation?: boolean
    }
  | undefined {
  const test = (value: string, enabled: boolean): RenderTest => ({
    value: value.split(/;\s*as\s+/, 1)[0],
    enabled,
    bindings: 'gateBindings' in node ? node.gateBindings : node.bindings,
  })
  if (node.kind === 'branch') {
    switch (node.name) {
      case 'if':
        return { test: test(node.expression, true), chain: true }
      case 'else if':
        return {
          test: test(node.expression, true),
          chain: true,
          continuation: true,
        }
      case 'else':
        return node.expression === ''
          ? { continuation: true }
          : { test: test(node.expression, false) }
      case 'when':
      case 'unless':
        return { test: test(node.expression, node.name === 'when') }
    }
  } else {
    const gate = node.attributes.find(({ name }) =>
      ['v-if', 'v-else-if', 'v-else'].includes(name),
    )
    if (gate !== undefined)
      return {
        test: gate.name === 'v-else' ? undefined : test(gate.value, true),
        chain: gate.name !== 'v-else',
        continuation: gate.name !== 'v-if',
      }
  }
}

export function applyRenderTests(
  initial: Conditions,
  tests: readonly RenderTest[],
  room?: RoomState,
): Conditions | undefined {
  let conditions: Conditions | undefined = initial
  for (const { value, enabled, bindings } of tests) {
    conditions = withMarkupCondition(
      conditions,
      value,
      enabled,
      bindings,
      room && { ...room, bindings },
    )
    if (conditions === undefined) break
  }
  return conditions
}

/** Removes the facts that distinguish the two arms of the same rendered slot. */
export function withoutRenderTests(
  facts: Conditions,
  tests: readonly RenderTest[],
): Conditions {
  const remaining = new Map(facts)
  for (const { value, bindings } of tests) {
    const expression = readMarkupExpression(value)
    if (expression === undefined) continue
    for (const enabled of [true, false])
      for (const name of assumption(expression, enabled, bindings).keys())
        remaining.delete(name)
  }
  return remaining
}
