import ts from 'typescript'

import {
  ALL_DISPLAY_RANGES,
  applyRenderTests,
  booleanAlias,
  classVariants,
  type Conditions,
  displayRanges,
  hiddenAttributes,
  isDisplayUtility,
  mergeClassConditions,
  MUTABLE_PROPERTY,
  renderSiblings,
  type RenderTest,
  type RoomState,
  type RoomSyntax,
  staticHidden,
  withMarkupCondition,
  withoutRenderTests,
} from './tableLiveRoomClasses.js'
import {
  type BooleanAlias,
  jsxTableContent,
  type MarkupBindings,
  type MarkupElement,
  type MarkupNode,
  readMarkupExpression,
  unparenthesize,
  visitElements,
} from './tableLiveRoomMarkup.js'

type TableScope = MarkupElement | 'shared' | undefined

const TABLE_TEMPLATES = new Set([
  'empty',
  'bulkUntouched',
  'tableProgress',
  'tableProgressAction',
  'rowProgress',
])
const OTHER_TABLE_SPACE = new Set([
  'hilos-table-marks',
  'hilos-table-placeholder',
  'hilos-table-placeholder-cell',
  'hilos-table-record-space',
  'hilos-table-work-space',
  'hilos-table-work-reserve',
])
const SIZE =
  /(?:^|[;,{\s])(?:min-?)?(?:height|block-?size)\s*:\s*['"]?(?:[1-9]\d*(?:\.\d+)?|0?\.\d*[1-9]\d*)(?:px|rem|em|vh|dvh|ch)(?![\w-])/i
const JS_SIZE =
  /[{,]\s*(?:minHeight|minBlockSize|height|blockSize)\s*:\s*[1-9]\d*(?:\.\d+)?\s*(?=[,}])/
const SIZE_BINDING =
  /^(?:\[style\.|bind-style\.)(?:(?:min-)?height|(?:min-)?block-size)(?:\.[\w-]+)?\]?$/
const SIZE_VALUE =
  /^\s*['"]?[1-9]\d*(?:\.\d+)?(?:px|rem|em|vh|dvh|ch)?['"]?\s*$/
const TABLE_RESERVE = /^hilos-table-(?:[\w-]+-)?reserve$/
const FIXED_HEIGHT = /^(?:h-(?:25|50|75|100)|(?:min-)?vh-100)$/
const CLIPPED = /^visually-hidden(?:-focusable)?$/
const OUT_OF_FLOW =
  /^(?:position-(?:absolute|fixed)|fixed-(?:top|bottom)|float(?:-\w+)?-(?:start|end))$/
const REFUSAL_COLOR = /^(?:border|(?:text-)?bg)-(?:danger|warning)(?:-subtle)?$/
const REF_NAME = /^[a-zA-Z_$][\w$]*$/

type Projection =
  'angular-table' | 'modal-default' | 'vue-table' | 'react-table'

interface TableContext {
  scope: TableScope
  conditions: Conditions
  inFlow: boolean
  ranges: number
  visible: boolean
  opaque: boolean
  review?: string
}

interface Placement {
  node: MarkupNode
  context: TableContext
  tokens?: ReadonlySet<string>
  children: Placement[]
}

export interface LocalTableReserves {
  violations: ReadonlySet<number>
  review: ReadonlyMap<number, string>
}

const EMPTY_REVIEW = 'Check whether the fixed table message room can be empty'
const MODAL_REVIEW =
  'Check which Angular modal template supplies this table content'
const OUTLET_CONTEXT_REVIEW = 'Check the context of this Angular table template'
const OUTLET_REVIEW =
  'Check which Angular outlet template supplies this table content'
const MUTABLE_PROPERTY_REVIEW =
  'Check the React property that controls this table content'
const UNKNOWN_CONTEXT = 'outlet-context:'
const UNKNOWN_OUTLET = 'outlet-selection:'

function relevantClass(name: string): boolean {
  return (
    [
      'alert',
      'border',
      'rounded',
      'modal',
      'invisible',
      'visible',
      'opacity-0',
      'd-contents',
    ].includes(name) ||
    OTHER_TABLE_SPACE.has(name) ||
    isDisplayUtility(name) ||
    CLIPPED.test(name) ||
    OUT_OF_FLOW.test(name) ||
    REFUSAL_COLOR.test(name) ||
    TABLE_RESERVE.test(name) ||
    FIXED_HEIGHT.test(name)
  )
}

/** Checks each rendered use while reporting its original markup node once. */
export function localTableReserves(
  nodes: readonly MarkupNode[],
  shared: boolean,
  syntax: RoomSyntax,
): LocalTableReserves {
  const reference = angularTemplateReferences(nodes)
  const classes = new Map<MarkupElement, ReturnType<typeof classVariants>>()
  const violations = new Set<number>()
  const review = new Map<number, string>()
  const root: TableContext = {
    scope: shared ? 'shared' : undefined,
    conditions: new Map(),
    inFlow: true,
    ranges: ALL_DISPLAY_RANGES,
    visible: true,
    opaque: true,
  }

  walk(nodes, root, new Set())
  return { violations, review }

  function candidate(node: MarkupElement, reason: string | undefined): void {
    if (reason === undefined) {
      violations.add(node.offset)
      review.delete(node.offset)
    } else if (!violations.has(node.offset) && !review.has(node.offset)) {
      review.set(node.offset, reason)
    }
  }

  function reviewReason(context: TableContext): string | undefined {
    if (context.review !== undefined) return context.review
    for (const key of context.conditions.keys()) {
      if (key.startsWith(UNKNOWN_CONTEXT)) return OUTLET_CONTEXT_REVIEW
      if (key.startsWith(UNKNOWN_OUTLET)) return OUTLET_REVIEW
      if (key.startsWith(MUTABLE_PROPERTY)) return MUTABLE_PROPERTY_REVIEW
    }
  }

  function project(
    node: MarkupElement,
    context: TableContext,
    path: ReadonlySet<number>,
    projection?: Projection,
    directive: 'if' | 'outlet' = 'outlet',
  ): Placement[] {
    const selection: AngularTemplateProjection<Placement> = {
      reference,
      render: (definition, conditions) =>
        path.has(definition.offset)
          ? []
          : renderElement(
              definition,
              { ...context, conditions },
              new Set(path).add(definition.offset),
              projection,
              true,
            ),
      unknown: (conditions) => [
        {
          node: {
            kind: 'branch',
            name: 'expression',
            expression: node.ngTemplateOutlet?.target ?? '',
            offset: node.offset,
            children: [],
          },
          context: { ...context, conditions },
          children: [],
        },
      ],
      compare: (yes, no, test) => {
        if (projection === undefined) checkPair(yes, no, context.scope, [test])
      },
    }
    return directive === 'if'
      ? projectAngularIf(node, context.conditions, selection)
      : projectAngularTemplate(node, context.conditions, selection)
  }

  function walk(
    children: readonly MarkupNode[],
    inherited: TableContext,
    path: ReadonlySet<number>,
    projection?: Projection,
  ): Placement[] {
    const rendered: Placement[] = []
    let previous: { uses: Placement[]; tests: RenderTest[] }[] = []
    for (const { node, tests, alternative, continuation } of renderSiblings(
      children,
    )) {
      if (node.kind === 'text' && projection !== undefined) continue
      const conditions = applyRenderTests(inherited.conditions, tests)
      const uses =
        conditions === undefined
          ? []
          : place(node, { ...inherited, conditions }, path, projection)
      if (alternative && projection === undefined)
        for (const arm of previous)
          checkPair(arm.uses, uses, inherited.scope, [...arm.tests, ...tests])
      if (node.kind !== 'text' || node.text.trim() !== '')
        previous = continuation
          ? [...previous, { uses, tests }]
          : [{ uses, tests }]
      rendered.push(...uses)
    }
    return rendered
  }

  function place(
    node: MarkupNode,
    context: TableContext,
    path: ReadonlySet<number>,
    projection?: Projection,
    stamped = false,
  ): Placement[] {
    if (node.kind === 'text') return [{ node, context, children: [] }]
    if (node.kind === 'branch') {
      const content = node.name === 'attribute'
      if (
        projection === 'react-table' &&
        (!content || !jsxTableContent(node.attribute ?? ''))
      )
        return []
      if (
        content &&
        projection !== 'react-table' &&
        !['header', 'body', 'actions', 'children'].includes(
          node.attribute ?? '',
        )
      )
        return []
      const branch =
        node.review === undefined
          ? context
          : { ...context, review: context.review ?? node.review }
      return [
        {
          node,
          context: branch,
          children: walk(
            node.children,
            branch,
            path,
            projection === 'react-table' ? undefined : projection,
          ),
        },
      ]
    }

    if (node.ngIfBranches !== undefined && !stamped)
      return project(node, context, path, projection, 'if')
    return renderElement(node, context, path, projection, stamped)
  }

  function renderElement(
    node: MarkupElement,
    inherited: TableContext,
    path: ReadonlySet<number>,
    projection: Projection | undefined,
    stamped: boolean,
  ): Placement[] {
    if (node.ngTemplateOutlet?.shorthand && !stamped)
      return project(node, inherited, path, projection)
    const isTemplate = node.name === 'ng-template'
    const names = isTemplate ? referenceNames(node) : []
    if (
      projection === 'modal-default' &&
      modalSlotNames(node, syntax).length > 0
    )
      return []
    if (
      projection === 'angular-table' &&
      !(isTemplate && isTableTemplate(node, names))
    ) {
      if ((isTemplate && !stamped) || node.sdkBoundary !== undefined) return []
      // The viewport queries its descendant declarations without rendering
      // the ordinary Angular content that surrounds them.
      return walk(node.children, inherited, path, projection)
    }
    if (
      isTemplate &&
      node.ngTemplateOutlet === undefined &&
      !stamped &&
      projection !== 'angular-table'
    )
      return []
    if (projection === 'react-table') return []
    if (projection === 'vue-table' && !isVueTableSlot(node)) return []

    let context = inherited
    const sdkModal = node.sdkBoundary === 'modal'
    if (sdkModal || node.name === 'dialog') {
      const open = node.attributes.find(({ name }) =>
        sdkModal && syntax === 'vue'
          ? [
              'v-model',
              'v-model:modelValue',
              'v-model:model-value',
              'v-bind:model-value',
              'v-bind:modelValue',
            ].includes(name)
          : syntax === 'vue'
            ? name === 'v-bind:open'
            : syntax === 'angular'
              ? ['[open]', 'bind-open', '[(open)]'].includes(name)
              : name === 'open',
      )
      if (
        sdkModal &&
        syntax === 'angular' &&
        open === undefined &&
        attribute(node, 'open') === undefined
      )
        return []
      if (
        open !== undefined &&
        open.literal === undefined &&
        open.value !== ''
      ) {
        const conditions = withMarkupCondition(
          context.conditions,
          open.value,
          true,
          node.bindings,
        )
        if (conditions === undefined) return []
        context = { ...context, conditions }
      }
      if (sdkModal && syntax !== 'angular')
        context = {
          ...root,
          conditions: context.conditions,
          review: context.review,
        }
    }

    const boxless =
      isTemplate ||
      node.name === 'ng-container' ||
      (syntax === 'vue' && node.name === 'template')
    const table = node.sdkBoundary === 'table' || node.name === 'table'
    const modal =
      sdkModal ||
      node.name === 'dialog' ||
      attribute(node, 'role')?.literal === 'dialog'
    const childProjection = node.sdkViewport
      ? syntax === 'vue'
        ? 'vue-table'
        : syntax === 'react'
          ? 'react-table'
          : 'angular-table'
      : undefined
    if (table && node.sdkBoundary !== undefined && !node.sdkViewport) return []
    const variants = boxless
      ? [{ tokens: new Set<string>(), conditions: root.conditions }]
      : nodeClasses(node)
    const result: Placement[] = []
    for (const variant of variants) {
      const conditions = mergeClassConditions(
        context.conditions,
        variant.conditions,
      )
      if (conditions === undefined) continue
      const { tokens } = variant
      const clipped = [...tokens].some((token) => CLIPPED.test(token))
      const excluded =
        modal ||
        tokens.has('modal') ||
        [...tokens].some((token) => OTHER_TABLE_SPACE.has(token))
      const local: TableContext = {
        conditions,
        review: context.review,
        scope: excluded ? undefined : table ? node : context.scope,
        inFlow:
          context.inFlow &&
          !clipped &&
          ![...tokens].some((token) => OUT_OF_FLOW.test(token)),
        ranges: context.ranges & displayRanges(tokens),
        visible:
          tokens.has('visible') ||
          (context.visible && !tokens.has('invisible')),
        opaque: context.opaque && !tokens.has('opacity-0') && !clipped,
      }
      for (const state of boxless ? [local] : displayStates(node, local)) {
        const inside = table ? { ...state, inFlow: true } : state
        const children =
          sdkModal && syntax !== 'react'
            ? renderModal(node.children, inside, path)
            : walk(node.children, inside, path, childProjection)
        if (node.ngTemplateOutlet !== undefined && (!isTemplate || !stamped))
          children.push(...project(node, inside, path))
        const placement = {
          node,
          context: state,
          tokens: boxless || tokens.has('d-contents') ? undefined : tokens,
          children,
        }
        if (
          state.scope !== undefined &&
          state.ranges !== 0 &&
          state.inFlow &&
          placement.tokens !== undefined &&
          isInvisible(tokens)
        )
          for (const frame of messageFrames(placement, state.scope, 'hidden'))
            candidate(node, reviewReason(frame.context))
        result.push(placement)
      }
    }
    return result
  }

  function renderModal(
    nodes: readonly MarkupNode[],
    context: TableContext,
    path: ReadonlySet<number>,
  ): Placement[] {
    const selected: Placement[] = []
    const slots = [...modalSlots(nodes, context.conditions, syntax, reference)]
    const unmatched = {
      body: [context.conditions],
      actions: [context.conditions],
    }
    const uncertainBody: Conditions[] = []
    for (const entry of slots) {
      const { node, slot, conditions } = entry
      const ambiguous =
        entry.indirect ||
        (syntax === 'angular' &&
          modalSlotNames(node, syntax).length > 1 &&
          slots.some(
            (other) =>
              other.node.offset !== node.offset &&
              modalSlotNames(node, syntax).includes(other.slot) &&
              mergeClassConditions(conditions, other.conditions) !== undefined,
          ))
      if (ambiguous) {
        if (slot === 'body')
          uncertainBody.push(entry.availability ?? conditions)
        selected.push(
          ...renderElement(
            node,
            { ...context, conditions, review: context.review ?? MODAL_REVIEW },
            path,
            undefined,
            true,
          ),
        )
        continue
      }
      const query = syntax === 'angular' && slot !== 'header' ? slot : undefined
      const valid =
        query === undefined
          ? slot === 'body' && syntax === 'vue'
            ? vueSlotContent(node.children, conditions)
            : [conditions]
          : unmatched[query].flatMap((facts) => {
              const active = mergeClassConditions(facts, conditions)
              return active === undefined ? [] : [active]
            })
      for (const active of valid) {
        selected.push(
          ...renderElement(
            node,
            { ...context, conditions: active },
            path,
            undefined,
            slot !== 'header' || node.name === 'ng-template',
          ),
        )
      }
      if (query !== undefined)
        unmatched[query] = unmatched[query].flatMap((facts) =>
          excludeConditions(facts, entry.availability ?? conditions),
        )
      else if (slot === 'body')
        unmatched.body = valid.reduce<Conditions[]>(
          (remaining, active) =>
            remaining.flatMap((facts) => excludeConditions(facts, active)),
          unmatched.body,
        )
    }
    return [
      ...selected,
      ...unmatched.body.flatMap((conditions) => {
        const certain = uncertainBody.reduce(
          (remaining, possible) =>
            remaining.flatMap((facts) => excludeConditions(facts, possible)),
          [conditions],
        )
        const rendered = certain.flatMap((facts) =>
          walk(nodes, { ...context, conditions: facts }, path, 'modal-default'),
        )
        for (const possible of uncertainBody) {
          const facts = mergeClassConditions(conditions, possible)
          if (facts !== undefined)
            rendered.push(
              ...walk(
                nodes,
                {
                  ...context,
                  conditions: facts,
                  review: context.review ?? MODAL_REVIEW,
                },
                path,
                'modal-default',
              ),
            )
        }
        return rendered
      }),
    ]
  }

  function nodeClasses(node: MarkupElement) {
    let variants = classes.get(node)
    if (variants === undefined) {
      variants = classVariants(
        node.attributes,
        relevantClass,
        undefined,
        node.bindings,
      )
      classes.set(node, variants)
    }
    return variants
  }

  function checkPair(
    first: Placement[],
    second: Placement[],
    scope: TableScope,
    tests: RenderTest[],
  ): void {
    if (scope === undefined || first.length === 0 || second.length === 0) return
    for (const [live, idle] of [
      [first, second],
      [second, first],
    ]) {
      const frames = live.flatMap((arm) => [
        ...messageFrames(arm, scope, 'live'),
      ])
      if (frames.length === 0) continue
      for (const arm of idle)
        for (const { node, tokens, context, children } of inScope(arm, scope)) {
          if (
            node.kind !== 'element' ||
            tokens === undefined ||
            !context.inFlow
          )
            continue
          for (const { context: liveContext } of frames) {
            const ranges = context.ranges & liveContext.ranges
            if (ranges === 0) continue
            const facts = mergeClassConditions(
              context.conditions,
              withoutRenderTests(liveContext.conditions, tests),
            )
            if (facts === undefined) continue
            const reason = reviewReason(context) ?? reviewReason(liveContext)
            if (isInvisible(tokens)) candidate(node, reason)
            else
              for (const sized of roomSizes(node, tokens, facts)) {
                const empty = emptyRange(children, sized, ranges)
                if (empty !== 'occupied')
                  candidate(
                    node,
                    reason ?? (empty === 'unknown' ? EMPTY_REVIEW : undefined),
                  )
              }
          }
        }
    }
  }
}

function displayStates(
  node: MarkupElement,
  initial: TableContext,
): TableContext[] {
  const hidden = hiddenAttributes(node.attributes)
  if (hidden.some(staticHidden)) return [{ ...initial, ranges: 0 }]
  const show = attribute(node, 'v-show')
  const tests = [
    ...hidden.map(({ value }) => ({ value, displayed: false })),
    ...(show ? [{ value: show.value, displayed: true }] : []),
  ]
  let states = [initial]
  for (const { value, displayed } of tests)
    states = states.flatMap((state) =>
      [true, false].flatMap((enabled) => {
        const conditions = withMarkupCondition(
          state.conditions,
          value,
          enabled,
          node.bindings,
        )
        return conditions === undefined
          ? []
          : [
              {
                ...state,
                conditions,
                ranges: enabled === displayed ? state.ranges : 0,
              },
            ]
      }),
    )
  return states
}

function* inScope(
  placement: Placement,
  scope: TableScope,
): Iterable<Placement> {
  if (placement.context.scope !== scope || placement.context.ranges === 0)
    return
  yield placement
  for (const child of placement.children) yield* inScope(child, scope)
}

function* messageFrames(
  placement: Placement,
  scope: TableScope,
  mode: 'live' | 'hidden',
): Generator<Placement> {
  for (const entry of inScope(placement, scope)) {
    const { node, context, tokens } = entry
    if (node.kind !== 'element' || tokens === undefined) continue
    const visible = context.visible && context.opaque
    if (mode === 'live' ? !visible : visible || !context.inFlow) continue
    if (
      tokens.has('alert') ||
      ((tokens.has('border') ||
        tokens.has('rounded') ||
        attribute(node, 'role')?.literal === 'alert') &&
        [...tokens].some((token) => REFUSAL_COLOR.test(token)))
    )
      yield entry
  }
}

function isInvisible(tokens: ReadonlySet<string>): boolean {
  return tokens.has('invisible') || tokens.has('opacity-0')
}

function roomSizes(
  node: MarkupElement,
  tokens: ReadonlySet<string>,
  conditions: Conditions,
): Conditions[] {
  if (
    [...tokens].some(
      (token) => TABLE_RESERVE.test(token) || FIXED_HEIGHT.test(token),
    )
  )
    return [conditions]
  return node.attributes.flatMap(({ name, value, literal }) => {
    const property = SIZE_BINDING.test(name)
    if (
      !property &&
      !['style', 'v-bind:style', '[style]', 'bind-style'].includes(name)
    )
      return []
    if (literal !== undefined) return SIZE.test(literal) ? [conditions] : []
    const expression = readMarkupExpression(value)
    return expression === undefined
      ? []
      : sized(expression, conditions, property)
  })

  function sized(
    value: ts.Expression,
    active: Conditions,
    property: boolean,
  ): Conditions[] {
    const expression = unparenthesize(value)
    if (ts.isConditionalExpression(expression))
      return [true, false].flatMap((enabled) => {
        const conditions = withMarkupCondition(
          active,
          expression.condition.getText(),
          enabled,
          node.bindings,
        )
        return conditions === undefined
          ? []
          : sized(
              enabled ? expression.whenTrue : expression.whenFalse,
              conditions,
              property,
            )
      })
    const text = expression.getText()
    const hasSize = property
      ? (ts.isNumericLiteral(expression) ||
          ts.isStringLiteralLike(expression)) &&
        SIZE_VALUE.test(text)
      : (ts.isObjectLiteralExpression(expression) ||
          ts.isStringLiteralLike(expression)) &&
        (SIZE.test(text) || JS_SIZE.test(text))
    return hasSize ? [active] : []
  }
}

function emptyRange(
  children: readonly Placement[],
  facts: Conditions,
  ranges: number,
): 'empty' | 'unknown' | 'occupied' {
  let result: 'unknown' | 'occupied' = 'occupied'
  while (ranges !== 0) {
    const range = ranges & -ranges
    const occupied = children.flatMap((child) => visibleContent(child, range))
    let empty = [facts]
    for (const shown of occupied.filter(({ known }) => known))
      empty = empty.flatMap((active) => excludeConditions(active, shown.facts))
    if (empty.length > 0) {
      result = 'unknown'
      for (const shown of occupied.filter(({ known }) => !known))
        empty = empty.flatMap((active) =>
          excludeConditions(active, shown.facts),
        )
      if (empty.length > 0) return 'empty'
    }
    ranges &= ~range
  }
  return result
}

function visibleContent(
  { node, context, tokens, children }: Placement,
  range: number,
): { facts: Conditions; known: boolean }[] {
  if ((context.ranges & range) === 0 || !context.opaque) return []
  if (context.visible) {
    if (
      tokens !== undefined ||
      (node.kind === 'text' && node.text.trim() !== '')
    )
      return [
        { facts: context.conditions, known: context.review === undefined },
      ]
    if (node.kind === 'branch' && node.name === 'expression')
      return [{ facts: context.conditions, known: false }]
  }
  return children.flatMap((child) => visibleContent(child, range))
}

function excludeConditions(
  facts: Conditions,
  excluded: Conditions,
): Conditions[] {
  if (mergeClassConditions(facts, excluded) === undefined) return [facts]
  const remaining: Conditions[] = []
  let prior: Conditions | undefined = facts
  for (const [test, enabled] of excluded) {
    if (prior.has(test)) continue
    const opposite = mergeClassConditions(prior, new Map([[test, !enabled]]))
    if (opposite !== undefined) remaining.push(opposite)
    prior = mergeClassConditions(prior, new Map([[test, enabled]]))
    if (prior === undefined) break
  }
  return remaining
}

function attribute(element: MarkupElement, name: string) {
  return element.attributes.find((attr) => attr.name === name)
}

function referenceNames(element: MarkupElement): string[] {
  return element.attributes.flatMap(({ name }) =>
    name.startsWith('#') || name.startsWith('ref-')
      ? [name.replace(/^(?:#|ref-)/, '')]
      : [],
  )
}

function templateContext(
  definition: MarkupElement,
  inherited: Conditions,
  values: ReadonlyMap<string, boolean>,
  implicit: boolean,
): Conditions {
  const conditions = new Map(inherited)
  for (const { name, value } of definition.attributes) {
    if (!name.startsWith('let-')) continue
    const key = definition.bindings?.names.get(name.slice(4))
    const fact = values.get(value || '$implicit')
    if (key !== undefined && fact !== undefined) conditions.set(key, fact)
  }
  if (implicit)
    for (const alias of definition.ngIfBranches?.aliases ?? []) {
      const key = definition.bindings?.names.get(alias)
      const fact = values.get('ngIf')
      if (key !== undefined && fact !== undefined) conditions.set(key, fact)
    }
  return conditions
}

/** Indexes Angular template references in their declaring embedded views. */
export function angularTemplateReferences(nodes: readonly MarkupNode[]) {
  const references = new Map<
    MarkupNode | undefined,
    Map<string, MarkupElement | undefined>
  >()
  const views = new Map<number, readonly MarkupNode[]>()
  visitElements(nodes, (element, ancestors) => {
    const enclosing = ancestors.filter(isEmbeddedView).reverse()
    views.set(element.offset, enclosing)
    const names = referenceNames(element)
    if (names.length === 0) return
    const [view] = enclosing
    const declarations = references.get(view) ?? new Map()
    for (const name of names)
      declarations.set(
        name,
        declarations.has(name) || element.name !== 'ng-template'
          ? undefined
          : element,
      )
    references.set(view, declarations)
  })

  return (use: MarkupElement, name: string): MarkupElement | undefined => {
    const binding = use.gateBindings?.names.get(name)
    for (const view of [...(views.get(use.offset) ?? []), undefined]) {
      const outer = view && view.kind !== 'text' ? view.gateBindings : undefined
      if (binding !== outer?.names.get(name)) return undefined
      const declarations = references.get(view)
      if (declarations?.has(name)) return declarations.get(name)
    }
  }
}

interface AngularTemplateProjection<T> {
  reference: ReturnType<typeof angularTemplateReferences>
  render: (definition: MarkupElement, conditions: Conditions) => T[]
  unknown?: (conditions: Conditions) => T[]
  compare?: (yes: T[], no: T[], test: RenderTest) => void
  room?: RoomState
  outlet?: boolean
}

/** Reads both NgIf inputs in the outer view and stamps the selected body. */
export function projectAngularIf<T>(
  use: MarkupElement,
  conditions: Conditions,
  projection: AngularTemplateProjection<T>,
): T[] {
  const branches = use.ngIfBranches
  if (branches === undefined) return []
  const test = { value: branches.condition, bindings: use.gateBindings }
  const [yes, no] = [true, false].map((enabled) => {
    const active = applyRenderTests(
      conditions,
      [{ ...test, enabled }],
      projection.room,
    )
    if (active === undefined) return undefined
    const target = enabled ? branches.then : branches.else
    const values = new Map([
      ['$implicit', enabled],
      ['ngIf', enabled],
    ])
    if (target === undefined)
      return enabled
        ? projection.render(use, templateContext(use, active, values, true))
        : []
    return selectAngularTemplate(use, target, active, {
      ...projection,
      render: (definition, selected) =>
        projection.render(
          definition,
          templateContext(definition, selected, values, false),
        ),
    })
  })
  projection.compare?.(yes ?? [], no ?? [], { ...test, enabled: true })
  return [...(yes ?? []), ...(no ?? [])]
}

/** Follows a readable Angular outlet from its use to the selected definition. */
export function projectAngularTemplate<T>(
  use: MarkupElement,
  conditions: Conditions,
  projection: AngularTemplateProjection<T>,
): T[] {
  const outlet = use.ngTemplateOutlet
  if (outlet === undefined) return []
  const expression =
    outlet.context === undefined
      ? undefined
      : readMarkupExpression(outlet.context)
  const fields = new Map<string, ts.Expression>()
  const unknownFields =
    outlet.context !== undefined &&
    (expression === undefined ||
      !ts.isObjectLiteralExpression(expression) ||
      expression.properties.some(ts.isSpreadAssignment))
  if (expression !== undefined && ts.isObjectLiteralExpression(expression))
    for (const field of expression.properties)
      if (
        ts.isPropertyAssignment(field) &&
        (ts.isIdentifier(field.name) || ts.isStringLiteral(field.name))
      )
        fields.set(field.name.text, field.initializer)
  return selectAngularTemplate(use, outlet.target, conditions, {
    ...projection,
    outlet: true,
    render: (definition, selected) => {
      const aliases = new Map(use.gateBindings?.aliases)
      const facts = new Map(selected)
      for (const { name, value: input } of definition.attributes) {
        if (!name.startsWith('let-')) continue
        const key = definition.bindings?.names.get(name.slice(4))
        const value = fields.get(input || '$implicit')
        if (key === undefined) continue
        const unknown = {
          key: `${UNKNOWN_CONTEXT}${use.offset}:${key}`,
          inverted: false,
        }
        if (value === undefined) {
          if (unknownFields) aliases.set(key, unknown)
          continue
        }
        const test = {
          value: value.getText(),
          bindings: use.gateBindings,
        }
        const yes = applyRenderTests(
          selected,
          [{ ...test, enabled: true }],
          projection.room,
        )
        const no = applyRenderTests(
          selected,
          [{ ...test, enabled: false }],
          projection.room,
        )
        const alias = booleanAlias(value, use.gateBindings)
        if (yes === undefined || no === undefined)
          facts.set(key, no === undefined)
        else aliases.set(key, alias ?? unknown)
      }
      const bound =
        aliases.size === 0 ? definition : bindOutlet(definition, aliases)
      return projection.render(bound, facts)
    },
  })
}

function bindOutlet<T extends MarkupNode>(
  node: T,
  aliases: ReadonlyMap<string, BooleanAlias>,
): T {
  const bind = (bindings?: MarkupBindings): MarkupBindings | undefined =>
    bindings === undefined ? undefined : { ...bindings, aliases }
  return node.kind === 'text'
    ? node
    : {
        ...node,
        bindings: bind(node.bindings),
        gateBindings: bind(node.gateBindings),
        children: node.children.map((child) => bindOutlet(child, aliases)),
      }
}

function selectAngularTemplate<T>(
  use: MarkupElement,
  expression: string,
  conditions: Conditions,
  {
    reference,
    render,
    unknown,
    compare,
    room,
    outlet,
  }: AngularTemplateProjection<T>,
): T[] {
  const target = readMarkupExpression(expression)
  return target === undefined
    ? (unknown?.(conditions) ?? [])
    : select(target, conditions)

  function select(value: ts.Expression, current: Conditions): T[] {
    const ref = unparenthesize(value)
    if (ref.kind === ts.SyntaxKind.NullKeyword) return []
    if (ts.isIdentifier(ref) && REF_NAME.test(ref.text)) {
      const definition = reference(use, ref.text)
      if (definition !== undefined) return render(definition, current)
      if (ref.text === 'undefined' && !use.gateBindings?.names.has(ref.text))
        return []
    }
    if (!ts.isConditionalExpression(ref)) return unknown?.(current) ?? []
    const test = { value: ref.condition.getText(), bindings: use.gateBindings }
    const yes = applyRenderTests(current, [{ ...test, enabled: true }], room)
    const no = applyRenderTests(current, [{ ...test, enabled: false }], room)
    const unresolved = outlet && yes !== undefined && no !== undefined
    const choose = (value: ts.Expression, active?: Conditions): T[] =>
      active === undefined
        ? []
        : select(
            value,
            unresolved
              ? new Map(active).set(UNKNOWN_OUTLET + use.offset, true)
              : active,
          )
    const first = choose(ref.whenTrue, yes)
    const second = choose(ref.whenFalse, no)
    compare?.(first, second, { ...test, enabled: true })
    return [...first, ...second]
  }
}

function isEmbeddedView(node: MarkupNode): boolean {
  return (
    node.kind === 'branch' ||
    (node.kind === 'element' &&
      (node.name === 'ng-template' ||
        node.attributes.some(({ name }) => name.startsWith('*'))))
  )
}

function isTableTemplate(
  element: MarkupElement,
  names: readonly string[],
): boolean {
  return (
    names.some((name) => TABLE_TEMPLATES.has(name)) ||
    element.attributes.some(({ name }) =>
      /^\[?hilosTable(?:Cell|Detail)\]?$/.test(name),
    )
  )
}

function isVueTableSlot(element: MarkupElement): boolean {
  return element.attributes.some(({ name }) =>
    /^v-slot:(?:cell-.+|detail-.+|empty|bulk-untouched|table-progress(?:-action|-announcement)?|row-progress)$/.test(
      name,
    ),
  )
}

type ModalSlot = 'body' | 'header' | 'actions'

function modalSlotNames(node: MarkupElement, syntax: RoomSyntax): ModalSlot[] {
  if (syntax === 'vue' && node.name === 'template')
    return (['body', 'header', 'actions'] as const).filter(
      (name) => attribute(node, `v-slot:${name}`) !== undefined,
    )
  if (syntax === 'angular') {
    if (attribute(node, 'modalHeader') !== undefined) return ['header']
    if (node.name === 'ng-template')
      return (['body', 'actions'] as const).filter((name) =>
        referenceNames(node).includes(
          name === 'body' ? 'modalBody' : 'modalActions',
        ),
      )
  }
  return []
}

interface ModalSlotCandidate {
  node: MarkupElement
  slot: ModalSlot
  conditions: Conditions
  availability?: Conditions
  indirect?: boolean
}

function* modalSlots(
  nodes: readonly MarkupNode[],
  conditions: Conditions,
  syntax: RoomSyntax,
  reference: ReturnType<typeof angularTemplateReferences>,
  direct = true,
  indirect = false,
  outlets: ReadonlySet<number> = new Set(),
): Generator<ModalSlotCandidate> {
  for (const { node, tests } of renderSiblings(nodes)) {
    if (node.kind === 'text') continue
    const active = applyRenderTests(conditions, tests)
    if (active === undefined) continue
    if (node.kind === 'branch') {
      if (syntax === 'angular')
        yield* modalSlots(
          node.children,
          active,
          syntax,
          reference,
          direct,
          indirect || !['if', 'else if', 'else', 'when'].includes(node.name),
          outlets,
        )
      continue
    }
    if (node.ngTemplateOutlet !== undefined)
      yield* projectAngularTemplate(node, active, {
        reference,
        render: (definition, conditions) =>
          outlets.has(definition.offset)
            ? []
            : [
                ...modalSlots(
                  definition.children,
                  conditions,
                  syntax,
                  reference,
                  false,
                  true,
                  new Set(outlets).add(definition.offset),
                ),
              ],
      })
    if (node.ngTemplateOutlet?.shorthand) continue
    if (node.ngIfBranches && attribute(node, '*ngIf') !== undefined) {
      const aliases = node.ngIfBranches.aliases
      yield* projectAngularIf(node, active, {
        reference,
        render: (definition, shown) => {
          // Named templates have their own declaration positions in the query.
          if (definition !== node) return []
          return [...candidate(node, shown)].map((entry) => {
            const availability = new Map(entry.availability ?? entry.conditions)
            for (const alias of aliases) {
              const key = node.bindings?.names.get(alias)
              if (key !== undefined) availability.delete(key)
            }
            return { ...entry, availability }
          })
        },
      })
      continue
    }
    yield* candidate(node, active)
  }

  function* candidate(
    node: MarkupElement,
    active: Conditions,
  ): Generator<ModalSlotCandidate> {
    const names = modalSlotNames(node, syntax)
    if (names.length > 0) {
      // Only actual template conditions can filter the default projection.
      const availability = indirect
        ? new Map(
            [...active].filter(([key]) => !key.startsWith(UNKNOWN_OUTLET)),
          )
        : undefined
      for (const slot of names)
        if (direct || slot !== 'header')
          yield { node, slot, conditions: active, availability, indirect }
    } else if (
      syntax === 'angular' &&
      node.name !== 'ng-template' &&
      node.sdkBoundary === undefined
    ) {
      yield* modalSlots(
        node.children,
        active,
        syntax,
        reference,
        false,
        indirect,
        outlets,
      )
    }
  }
}

function vueSlotContent(
  nodes: readonly MarkupNode[],
  conditions: Conditions,
): Conditions[] {
  return [...renderSiblings(nodes)].flatMap(({ node, tests }) => {
    const active = applyRenderTests(conditions, tests)
    if (active === undefined) return []
    if (node.kind === 'text') return node.text.trim() === '' ? [] : [active]
    if (node.kind === 'branch')
      return node.name === 'expression'
        ? [active]
        : vueSlotContent(node.children, active)
    if (node.name === 'template' && tests.length > 0)
      return vueSlotContent(node.children, active)
    return [active]
  })
}
