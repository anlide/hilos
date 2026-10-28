import { useId, useLayoutEffect, useState, type RefObject } from 'react'
import type {
  ReadonlySignal,
  TableGeometryRecord,
  TableProjection,
  TableViewportController,
} from '@hilos/core/base'
import { useSignal } from './useSignal.js'

/** Only the existing text-intent seam; never a second geometry registration. */
export type TableTextOverflowController = Pick<
  TableViewportController<unknown>,
  'expandedText' | 'expandText'
>

/** One current live value in one mounted projection. */
export interface TableTextOverflowScope {
  readonly controller: TableTextOverflowController
  /** Exact owner-issued object, never reconstructed from a row key. */
  readonly record: TableGeometryRecord
  readonly columnKey: string
  readonly projection: TableProjection
}

export interface TableTextOverflow {
  readonly expanded: boolean
  /** Last usable collapsed measurement, or unknown for a fresh binding. */
  readonly overflow: boolean | null
  readonly contentId: string
  readonly setExpanded: (expanded: boolean) => void
  readonly requestMeasure: () => void
  readonly dispose: () => void
  /** Internal focus identity, local to this committed DOM binding. */
  readonly binding: object | null
  readonly isCurrent: () => boolean
}

interface Inputs {
  readonly scope: () => TableTextOverflowScope | null
  readonly target: RefObject<HTMLElement | null>
  readonly content: RefObject<HTMLElement | null>
  readonly limited: () => boolean
}

interface Lifetime {
  active: boolean
  inputs: Inputs | null
  binding: Binding | null
}

interface Binding {
  owner: Lifetime | null
  scope: TableTextOverflowScope | null
  node: HTMLElement | null
  content: HTMLElement | null
  limited: boolean
  /** Intent at the last DOM commit, as well as the fresh core verdict. */
  expanded: boolean
  overflow: boolean | null
  ancestors: HTMLElement[]
  observing: boolean
  frame: number | null
  resize?: ResizeObserver
  mutations?: MutationObserver
  cleanups: (() => void)[]
  publish: (() => void) | null
}

const EMPTY_INTENT: ReadonlySignal<ReadonlyMap<number, ReadonlySet<string>>> = {
  get: () => EMPTY_EXPANDED,
}
const EMPTY_EXPANDED: ReadonlyMap<number, ReadonlySet<string>> = new Map()

function sameScope(
  left: TableTextOverflowScope | null,
  right: TableTextOverflowScope | null,
): boolean {
  return (
    left?.controller === right?.controller &&
    left?.record === right?.record &&
    left?.columnKey === right?.columnKey &&
    left?.projection === right?.projection
  )
}

function intent(scope: TableTextOverflowScope): boolean {
  return (
    scope.controller.expandedText
      .get()
      .get(scope.record.occurrence)
      ?.has(scope.columnKey) ?? false
  )
}

function release(state: Binding): void {
  if (state.owner?.binding === state) state.owner.binding = null
  state.owner = null
  if (state.frame !== null) cancelAnimationFrame(state.frame)
  state.resize?.disconnect()
  state.mutations?.disconnect()
  for (const cleanup of state.cleanups) cleanup()
  // Late fonts.ready/observer/RAF callbacks retain only this emptied ticket.
  state.frame = null
  state.resize = undefined
  state.mutations = undefined
  state.cleanups.length = 0
  state.ancestors.length = 0
  state.node = null
  state.content = null
  state.scope = null
  state.publish = null
}

/** Re-read the captured owner reader even before a pending React update. */
function current(state: Binding | null): state is Binding {
  if (!state) return false
  const owner = state.owner
  const inputs = owner?.inputs
  if (
    owner?.active &&
    owner.binding === state &&
    inputs &&
    sameScope(state.scope, inputs.scope()) &&
    state.limited === inputs.limited() &&
    state.node === inputs.target.current &&
    state.content === inputs.content.current &&
    state.content?.parentElement === state.node
  )
    return true
  release(state)
  return false
}

function observeAncestors(state: Binding): void {
  const node = state.node
  if (!node) return
  const ancestors: HTMLElement[] = []
  for (let parent = node.parentElement; parent; parent = parent.parentElement)
    ancestors.push(parent)
  if (
    state.observing &&
    state.ancestors.length === ancestors.length &&
    state.ancestors.every((parent, index) => parent === ancestors[index])
  )
    return
  state.resize?.disconnect()
  state.mutations?.disconnect()
  state.ancestors = ancestors
  state.observing = true
  state.resize?.observe(node)
  // Typography may resize the natural text while its rem cap stays fixed.
  if (state.content) state.resize?.observe(state.content)
  state.mutations?.observe(node, {
    attributes: true,
    childList: true,
    characterData: true,
    subtree: true,
  })
  for (const parent of ancestors) {
    state.resize?.observe(parent)
    state.mutations?.observe(parent, {
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden', 'inert', 'open', 'dir'],
      childList: true,
    })
  }
}

function measure(state: Binding): void {
  if (
    !current(state) ||
    !state.scope ||
    !state.limited ||
    state.expanded ||
    intent(state.scope)
  )
    return
  const node = state.node
  if (!node?.isConnected || node.classList.contains('is-open')) return
  observeAncestors(state)
  if (node.ownerDocument.visibilityState === 'hidden') return
  for (const element of [node, ...state.ancestors]) {
    const style = getComputedStyle(element)
    if (
      element.hidden ||
      element.hasAttribute('inert') ||
      style.display === 'none' ||
      style.visibility === 'hidden' ||
      style.visibility === 'collapse' ||
      style.contentVisibility === 'hidden'
    )
      return
  }
  const rect = node.getBoundingClientRect()
  const { clientWidth, clientHeight, scrollWidth, scrollHeight } = node
  if (
    rect.width <= 0 ||
    rect.height <= 0 ||
    clientWidth <= 0 ||
    clientHeight <= 0 ||
    ![
      rect.width,
      rect.height,
      clientWidth,
      clientHeight,
      scrollWidth,
      scrollHeight,
    ].every(Number.isFinite)
  )
    return
  if (!current(state) || !state.scope || intent(state.scope)) return
  const overflow = scrollHeight > clientHeight || scrollWidth > clientWidth
  if (overflow === state.overflow) return
  state.overflow = overflow
  state.publish?.()
}

function schedule(state: Binding): void {
  if (!current(state) || !state.limited || !state.node || state.frame !== null)
    return
  const frame = requestAnimationFrame(() => {
    if (!current(state) || state.frame !== frame) return
    state.frame = null
    measure(state)
  })
  state.frame = frame
}

/** This closure captures a ticket only, including while fonts.ready is pending. */
function changed(state: Binding): () => void {
  return () => schedule(state)
}

function observe(state: Binding): void {
  const node = state.node
  if (!node || !state.limited) return
  const notify = changed(state)
  state.resize =
    typeof ResizeObserver === 'undefined'
      ? undefined
      : new ResizeObserver(notify)
  state.mutations =
    typeof MutationObserver === 'undefined'
      ? undefined
      : new MutationObserver(notify)
  observeAncestors(state)
  const document = node.ownerDocument
  const window = document.defaultView
  const fonts = document.fonts
  document.addEventListener('visibilitychange', notify)
  window?.addEventListener('resize', notify)
  node.addEventListener('transitionend', notify)
  node.addEventListener('animationend', notify)
  fonts?.addEventListener('loadingdone', notify)
  void fonts?.ready.then(notify)
  state.cleanups.push(() => {
    document.removeEventListener('visibilitychange', notify)
    window?.removeEventListener('resize', notify)
    node.removeEventListener('transitionend', notify)
    node.removeEventListener('animationend', notify)
    fonts?.removeEventListener('loadingdone', notify)
  })
}

/** Saved commands, like queued callbacks, retain only their binding ticket. */
function commands(binding: Binding | null) {
  return {
    isCurrent: () => current(binding),
    setExpanded(value: boolean) {
      if (!current(binding) || !binding.scope || !binding.limited) return
      binding.scope.controller.expandText(
        binding.scope.record,
        binding.scope.columnKey,
        value,
      )
    },
    requestMeasure() {
      if (binding) schedule(binding)
    },
    dispose() {
      if (binding) release(binding)
    },
  }
}

/**
 * Internal DOM adapter shared by the table text views.
 * DOM handles/overflow are private to one mount, with no backend RT/DB reader
 * (runtime/rt-context.md, architecture/truth-source.md). Intent stays in the
 * existing controller and crosses only the useSignal bridge. Render is read-only.
 *
 * @param scope Read the existing geometry mount's exact current record.
 * @param target Clipped plain-text window, excluding controls and labels.
 * @param content The same window's single live, uncapped natural content node.
 * @param limited Whether the normalized policy permits a reading control.
 */
export function useTableTextOverflow(
  scope: () => TableTextOverflowScope | null,
  target: RefObject<HTMLElement | null>,
  content: RefObject<HTMLElement | null>,
  limited: () => boolean,
): TableTextOverflow {
  const id = useId()
  const next = scope()
  const expandedText = useSignal(next?.controller.expandedText ?? EMPTY_INTENT)
  const expanded =
    !!next &&
    (expandedText.get(next.record.occurrence)?.has(next.columnKey) ?? false)
  const [lifetime] = useState<Lifetime>(() => ({
    active: false,
    inputs: null,
    binding: null,
  }))
  const [view, setView] = useState<{
    binding: Binding | null
    overflow: boolean | null
  }>({ binding: null, overflow: null })

  useLayoutEffect(() => {
    lifetime.active = true
    return () => {
      lifetime.active = false
      if (lifetime.binding) release(lifetime.binding)
      lifetime.inputs = null
    }
  }, [lifetime])

  useLayoutEffect(() => {
    // New getter functions are not new scopes. Publish them only at commit.
    lifetime.inputs = { scope, target, content, limited }
    const next = scope()
    const node = target.current
    const natural = content.current
    const isLimited = limited()
    let state = lifetime.binding
    if (
      state &&
      (!sameScope(state.scope, next) ||
        state.node !== node ||
        state.content !== natural ||
        state.limited !== isLimited)
    ) {
      release(state)
      state = null
    }
    if (!next || !node || !natural || natural.parentElement !== node) {
      if (state) release(state)
      setView((previous) =>
        previous.binding ? { binding: null, overflow: null } : previous,
      )
      return
    }
    if (!state) {
      const binding: Binding = {
        owner: lifetime,
        scope: next,
        node,
        content: natural,
        limited: isLimited,
        expanded: intent(next),
        overflow: null,
        ancestors: [],
        observing: false,
        frame: null,
        cleanups: [],
        publish: null,
      }
      binding.publish = () => setView({ binding, overflow: binding.overflow })
      lifetime.binding = binding
      state = binding
      observe(state)
      state.publish?.()
    }
    state.expanded = intent(next)
    schedule(state)
  })

  const binding =
    view.binding?.owner === lifetime &&
    sameScope(view.binding.scope, next) &&
    view.binding.limited === limited()
      ? view.binding
      : null
  return {
    expanded,
    overflow: binding ? view.overflow : null,
    contentId: `${id}-table-text-${next?.projection ?? 'wide'}`,
    binding,
    ...commands(binding),
  }
}
