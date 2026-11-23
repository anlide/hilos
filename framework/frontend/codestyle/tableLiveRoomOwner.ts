import {
  ALL_DISPLAY_RANGES,
  applyRenderTests,
  classVariants,
  type Conditions,
  displayRanges,
  hiddenAttributes,
  isDisplayUtility,
  markupCondition,
  mergeClassConditions,
  renderSiblings,
  roomClasses,
  type RoomSyntax,
  staticHidden,
  UNKNOWN_CLASS,
  withMarkupCondition,
} from './tableLiveRoomClasses.js'
import {
  angularTemplateReferences,
  projectAngularIf,
  projectAngularTemplate,
} from './tableLiveRoomLocal.js'
import {
  type MarkupElement,
  type MarkupNode,
  type MarkupTemplate,
  visitElements,
} from './tableLiveRoomMarkup.js'

const FILL_REASON =
  'Show the neutral fill only while the table live-message room is empty'
const DECORATIVE_REASON =
  'Keep the invisible sizing twin and the empty room decorative'

export const SLOT_ID = 'hilos-table-live-slot'
export const IDLE_ID = 'hilos-table-live-idle'
const FILL = 'bg-body-tertiary'
const ROUNDING = 'rounded'
const HIDDEN = /^(?:invisible|opacity-0|visually-hidden|d-none)$/
const OUT_OF_FLOW =
  /^(?:position-(?:absolute|fixed)|fixed-(?:top|bottom)|float(?:-\w+)?-(?:start|end)|visually-hidden(?:-focusable)?|d-(?:(?:sm|md|lg|xl|xxl)-)?(?:none|contents))$/
const DECORATION =
  /^(?:bg-.+|text-bg-.+|alert-.+|border(?:-.+)?|shadow(?:-.+)?|bi(?:-.+)?|btn(?:-.+)?|(?:spinner|animation|animated|placeholder|progress|transition|animate)(?:-.+)?)$/
const CLEAR_DECORATION =
  /^(?:bg-transparent|border(?:-(?:top|end|bottom|start))?-0|shadow-none)$/
const VISIBLE_TAG =
  /^(?:button|input|select|textarea|img|svg|canvas|hr|progress|meter|i|slot)$/

interface Violation {
  offset: number
  reason: string
}

interface RoomNode {
  node: MarkupElement
  ancestors: readonly MarkupNode[]
  template: MarkupTemplate
}

/** Checks the live room's owner against its statically declared empty state. */
export function ownerViolations(
  path: string,
  templates: readonly MarkupTemplate[],
  dataId: (name: string) => boolean,
): Violation[] {
  const syntax: RoomSyntax = path.endsWith('.vue')
    ? 'vue'
    : path.endsWith('.tsx')
      ? 'react'
      : 'angular'
  const slots: RoomNode[] = []
  const twins: RoomNode[] = []
  for (const template of templates) {
    visitElements(template.nodes, (node, ancestors) => {
      const id = node.attributes.find(({ name }) => dataId(name))?.literal
      const matches =
        id === SLOT_ID ? slots : id === IDLE_ID ? twins : undefined
      matches?.push({ node, ancestors, template })
    })
  }
  const slot =
    slots.find(({ node }) =>
      twins.some(({ ancestors }) => ancestors.includes(node)),
    ) ?? slots[0]
  if (slot === undefined) {
    const root = templates[0]
    return [
      {
        offset:
          root?.nodes.find(({ kind }) => kind === 'element')?.offset ??
          root?.offset ??
          0,
        reason: DECORATIVE_REASON,
      },
    ]
  }

  const violations = new Map<number, Violation>()
  const say = (
    node: MarkupNode,
    reason: string,
    offset = node.offset,
  ): void => {
    if (!violations.has(node.offset))
      violations.set(node.offset, { offset, reason })
  }
  const twin = twins.find(({ ancestors }) => ancestors.includes(slot.node))
  for (const entry of slots)
    if (entry !== slot) say(entry.node, DECORATIVE_REASON)
  for (const entry of twins)
    if (entry !== twin) say(entry.node, DECORATIVE_REASON)
  if (twins.length === 0) say(slot.node, DECORATIVE_REASON)

  const { classFields } = slot.template
  const monitoredClass = (name: string): boolean =>
    name === FILL ||
    name === ROUNDING ||
    name === UNKNOWN_CLASS ||
    name === 'visible' ||
    HIDDEN.test(name) ||
    OUT_OF_FLOW.test(name) ||
    isDisplayUtility(name) ||
    isDecoration(name)
  const classes = (node: MarkupElement, empty: boolean) =>
    node.attributes.map((attribute) => ({
      offset: attribute.offset,
      tokens: roomClasses(
        attribute,
        syntax,
        empty,
        classFields,
        monitoredClass,
        node.bindings,
      ),
    }))
  const emptyClasses = classes(slot.node, true)
  const activeClasses = classes(slot.node, false)
  for (const name of [FILL, ROUNDING]) {
    const onActive = activeClasses.find(({ tokens }) => tokens.has(name))
    const onEmpty = emptyClasses.find(({ tokens }) => tokens.get(name) === true)
    if (onActive !== undefined || onEmpty === undefined) {
      const declared = emptyClasses.find(({ tokens }) => tokens.has(name))
      say(slot.node, FILL_REASON, (onActive ?? declared)?.offset)
    }
  }
  const decoration = emptyClasses.find(({ tokens }) =>
    [...tokens.keys()].some((name) => name !== FILL && isDecoration(name)),
  )
  if (decoration !== undefined || interactive(slot.node, syntax, true))
    say(slot.node, DECORATIVE_REASON, decoration?.offset)
  for (const node of [...slot.ancestors, slot.node]) {
    if (node.kind !== 'element') continue
    const hidden =
      classes(node, true).find(({ tokens }) =>
        [...tokens.keys()].some(
          (name) => HIDDEN.test(name) || name === UNKNOWN_CLASS,
        ),
      ) ??
      node.attributes.find(
        (entry) =>
          attributeName(entry.name) === 'hidden' &&
          booleanAttribute(entry, syntax, true),
      )
    if (hidden !== undefined) say(node, DECORATIVE_REASON, hidden.offset)
  }

  if (twin !== undefined) {
    const pathToTwin = [...twin.ancestors, twin.node]
    const unstable = pathToTwin.find((node) => {
      if (node.kind !== 'element') return true
      return (
        node.unknownJsxAttributes === true ||
        /^(?:template|ng-template)$/.test(node.name) ||
        node.attributes.some(({ name }) =>
          /^(?:v-if|v-else(?:-if)?|v-for|v-show|hidden|v-bind:hidden|\[hidden\]|\[ngIf\]|\*)/.test(
            name,
          ),
        ) ||
        [true, false].some((empty) =>
          classes(node, empty).some(({ tokens }) =>
            [...tokens.keys()].some(
              (name) => OUT_OF_FLOW.test(name) || name === UNKNOWN_CLASS,
            ),
          ),
        )
      )
    })
    if (unstable !== undefined)
      say(unstable.kind === 'element' ? unstable : twin.node, DECORATIVE_REASON)
    const hidden = [true, false].every((empty) =>
      classes(twin.node, empty).some(
        ({ tokens }) =>
          tokens.get('invisible') === true || tokens.get('opacity-0') === true,
      ),
    )
    const ariaHidden = twin.node.attributes.some(
      ({ name, value }) =>
        attributeName(name) === 'aria-hidden' && value.trim() === 'true',
    )
    if (!hidden || !ariaHidden) say(twin.node, DECORATIVE_REASON)
    visitElements([twin.node], (node) => {
      const decorativeClasses = [true, false].flatMap((empty) =>
        classes(node, empty),
      )
      const fill = decorativeClasses.find(({ tokens }) => tokens.has(FILL))
      const visible = decorativeClasses.find(({ tokens }) =>
        tokens.has('visible'),
      )
      if (fill !== undefined) say(node, FILL_REASON, fill.offset)
      if (
        visible !== undefined ||
        [true, false].some((empty) => interactive(node, syntax, empty))
      )
        say(node, DECORATIVE_REASON, visible?.offset)
    })
  }

  const templateReference = angularTemplateReferences(slot.template.nodes)
  checkEmpty(slot.node.children, new Map(), ALL_DISPLAY_RANGES, true, new Set())
  return [...violations.values()]

  function checkEmpty(
    nodes: readonly MarkupNode[],
    inherited: Conditions,
    ranges: number,
    visible: boolean,
    outlets: ReadonlySet<number>,
  ): void {
    for (const { node, tests } of renderSiblings(nodes)) {
      const room = { syntax, empty: true, fields: classFields }
      const conditions = applyRenderTests(inherited, tests, room)
      if (conditions === undefined) continue
      if (node.kind === 'text') {
        const firstVisible = node.text.search(/\S/)
        if (visible && firstVisible >= 0)
          say(node, DECORATIVE_REASON, node.offset + firstVisible)
        continue
      }
      if (node.kind === 'branch') {
        if (node.name === 'attribute') continue
        if (
          visible &&
          node.name === 'expression' &&
          node.children.length === 0 &&
          !/^(?:null|undefined|true|false|(['"`])\s*\1)$/.test(
            node.expression.trim(),
          )
        )
          say(node, DECORATIVE_REASON)
        checkEmpty(node.children, conditions, ranges, visible, outlets)
        continue
      }
      if (node.offset === twin?.node.offset || node.sdkBoundary === 'modal')
        continue

      if (node.ngIfBranches !== undefined) {
        projectAngularIf(node, conditions, {
          reference: templateReference,
          room,
          unknown: () => {
            if (visible) say(node, DECORATIVE_REASON)
            return []
          },
          render: (definition, active) => {
            stamp(definition, active, ranges, visible, outlets)
            return []
          },
        })
      } else {
        checkElement(node, conditions, ranges, visible, outlets)
      }
    }
  }

  function stamp(
    node: MarkupElement,
    conditions: Conditions,
    ranges: number,
    visible: boolean,
    outlets: ReadonlySet<number>,
  ): void {
    if (outlets.has(node.offset)) return
    const path = new Set(outlets).add(node.offset)
    if (node.name === 'ng-template')
      checkEmpty(node.children, conditions, ranges, visible, path)
    else checkElement(node, conditions, ranges, visible, path)
  }

  function checkElement(
    node: MarkupElement,
    inherited: Conditions,
    ranges: number,
    visible: boolean,
    outlets: ReadonlySet<number>,
  ): void {
    const room = { syntax, empty: true, fields: classFields }
    let conditions: Conditions | undefined = inherited
    if (node.ngTemplateOutlet !== undefined)
      projectAngularTemplate(node, conditions, {
        reference: templateReference,
        room,
        unknown: () => {
          if (visible) say(node, DECORATIVE_REASON)
          return []
        },
        render: (definition, active) => {
          stamp(definition, active, ranges, visible, outlets)
          return []
        },
      })
    if (node.ngTemplateOutlet?.shorthand) return
    if (node.name === 'template' || node.name === 'ng-container') {
      checkEmpty(node.children, conditions, ranges, visible, outlets)
      return
    }
    if (node.name === 'ng-template') return

    if (node.unknownJsxAttributes) say(node, DECORATIVE_REASON)
    const state = { ...room, bindings: node.bindings }
    const hidden = hiddenAttributes(node.attributes)
    if (hidden.some(staticHidden)) return
    for (const { value } of hidden) {
      conditions = withMarkupCondition(
        conditions,
        value,
        false,
        node.bindings,
        state,
      )
      if (conditions === undefined) break
    }
    if (conditions === undefined) return
    const show = node.attributes.find(({ name }) => name === 'v-show')
    if (show !== undefined)
      conditions = withMarkupCondition(
        conditions,
        show.value,
        true,
        node.bindings,
        state,
      )
    if (conditions === undefined) return

    for (const variant of classVariants(
      node.attributes,
      monitoredClass,
      state,
    )) {
      const active = mergeClassConditions(conditions, variant.conditions)
      const { tokens } = variant
      const displayed = ranges & displayRanges(tokens)
      if (
        active === undefined ||
        displayed === 0 ||
        tokens.has('opacity-0') ||
        tokens.has('visually-hidden')
      )
        continue
      const shown =
        tokens.has('visible') || (visible && !tokens.has('invisible'))
      if (shown) {
        if (tokens.has(FILL)) {
          const fill = classes(node, true).find((entry) =>
            entry.tokens.has(FILL),
          )
          say(node, FILL_REASON, fill?.offset)
          continue
        }
        const decoration = [...tokens].find(isDecoration)
        if (
          decoration !== undefined ||
          interactive(node, syntax, true) ||
          VISIBLE_TAG.test(node.name)
        ) {
          const ornament =
            decoration === undefined
              ? undefined
              : classes(node, true).find((entry) =>
                  entry.tokens.has(decoration),
                )
          say(node, DECORATIVE_REASON, ornament?.offset)
          continue
        }
      }
      checkEmpty(node.children, active, displayed, shown, outlets)
    }
  }
}

function isDecoration(name: string): boolean {
  return (
    name === UNKNOWN_CLASS ||
    (DECORATION.test(name) && !CLEAR_DECORATION.test(name))
  )
}

function attributeName(name: string): string {
  return name
    .replace(/^(?:v-bind:|bind-|\[)(?:attr\.)?/, '')
    .replace(/\]$/, '')
    .toLowerCase()
}

function booleanAttribute(
  entry: MarkupElement['attributes'][number],
  syntax: RoomSyntax,
  empty: boolean,
): boolean {
  const { name, literal, value } = entry
  return (
    (name === attributeName(name) && (literal !== undefined || value === '')) ||
    markupCondition(value, syntax, empty) === true
  )
}

function interactive(
  node: MarkupElement,
  syntax: RoomSyntax,
  empty: boolean,
): boolean {
  if (node.unknownJsxAttributes) return true
  const tag = node.name.toLowerCase()
  const attribute = (name: string) =>
    node.attributes.find((entry) => attributeName(entry.name) === name)
  const present = (name: string): boolean => {
    const entry = attribute(name)
    return (
      entry !== undefined &&
      !(entry.literal === undefined && entry.value === 'false')
    )
  }
  if (/^(?:button|input|select|textarea)$/.test(tag)) {
    const disabled = attribute('disabled')
    return disabled === undefined || !booleanAttribute(disabled, syntax, empty)
  }
  return (
    /^(?:summary|iframe|embed|object)$/.test(tag) ||
    (/^(?:a|area)$/.test(tag) && present('href')) ||
    (/^(?:audio|video)$/.test(tag) && present('controls')) ||
    present('tabindex') ||
    (present('contenteditable') &&
      attribute('contenteditable')?.value !== 'false') ||
    (present('focusable') && attribute('focusable')?.value !== 'false') ||
    node.attributes.some(({ name }) =>
      /^(?:on|\(|v-on:)(?:click|key(?:up|down|press))/i.test(name),
    )
  )
}
