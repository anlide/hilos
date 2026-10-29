import { computed, signal } from '@angular/core'
import {
  subscribeSignal,
  type TableGeometryRecord,
  type TableProjection,
  type TableViewportController,
} from '@hilos/core/base'

/** Existing core intent only; DOM state never creates a geometry registration. */
export type TableTextOverflowController = Pick<
  TableViewportController<unknown>,
  'expandedText' | 'expandText'
>

/** Exact live value in a single mounted projection. */
export interface TableTextOverflowScope {
  readonly controller: TableTextOverflowController
  readonly record: TableGeometryRecord
  readonly columnKey: string
  readonly projection: TableProjection
}

interface Inputs {
  scope: () => TableTextOverflowScope | null
  target: () => HTMLElement | null
  content: () => HTMLElement | null
  limited: () => boolean
}

interface Lifetime {
  inputs: Inputs | null
  binding: Binding | null
}

interface Binding {
  owner: Lifetime | null
  scope: TableTextOverflowScope | null
  node: HTMLElement | null
  content: HTMLElement | null
  limited: boolean
  expanded: boolean
  ancestors: HTMLElement[]
  observing: boolean
  frame: number | null
  resize?: ResizeObserver
  mutations?: MutationObserver
  cleanups: (() => void)[]
  publish: (() => void) | null
  reset: (() => void) | null
}

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
  if (state.owner?.binding === state) {
    state.owner.binding = null
    state.reset?.()
  }
  state.owner = null
  if (state.frame !== null) cancelAnimationFrame(state.frame)
  state.resize?.disconnect()
  state.mutations?.disconnect()
  for (const cleanup of state.cleanups) cleanup()
  // Queued observers, RAF and unresolved fonts.ready retain only this ticket.
  state.frame = null
  state.resize = undefined
  state.mutations = undefined
  state.cleanups.length = 0
  state.ancestors.length = 0
  state.node = null
  state.content = null
  state.scope = null
  state.publish = null
  state.reset = null
}

function current(state: Binding | null): state is Binding {
  if (!state) return false
  const owner = state.owner
  const inputs = owner?.inputs
  if (
    owner?.binding === state &&
    inputs &&
    sameScope(state.scope, inputs.scope()) &&
    state.limited === inputs.limited() &&
    state.node === inputs.target() &&
    state.node?.isConnected &&
    state.content === inputs.content() &&
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
  // A fixed cap need not resize when stylesheet typography changes its content.
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

function measure(state: Binding): boolean | null {
  if (
    !current(state) ||
    !state.scope ||
    !state.limited ||
    state.expanded ||
    intent(state.scope)
  )
    return null
  const node = state.node
  if (!node?.isConnected || node.classList.contains('is-open')) return null
  observeAncestors(state)
  if (node.ownerDocument.visibilityState === 'hidden') return null
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
      return null
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
    return null
  if (!current(state) || !state.scope || intent(state.scope)) return null
  return scrollHeight > clientHeight || scrollWidth > clientWidth
}

function schedule(state: Binding): void {
  if (!current(state) || !state.limited || !state.node || state.frame !== null)
    return
  const frame = requestAnimationFrame(() => {
    if (!current(state) || state.frame !== frame) return
    state.frame = null
    state.publish?.()
  })
  state.frame = frame
}

/** The callback captures only a ticket emptied on release. */
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

/**
 * Mount-local DOM reader. The caller commits from Angular afterRenderEffect and
 * disposes through DestroyRef; construction is safe without DOM globals. Intent
 * stays in core, not replicated RT (runtime/rt-context.md, architecture/truth-source.md).
 *
 * @param scope Read the existing registration's exact current record.
 * @param target The clipping window, excluding labels and controls.
 * @param content Its one live uncapped content node.
 * @param limited Whether the normalized policy permits clipping.
 */
export function createTableTextOverflow(
  scope: Inputs['scope'],
  target: Inputs['target'],
  content: Inputs['content'],
  limited: Inputs['limited'],
) {
  const expanded = signal(false)
  const overflow = signal<boolean | null>(null)
  const binding = signal<Binding | null>(null)
  const lifetime: Lifetime = {
    inputs: { scope, target, content, limited },
    binding: null,
  }
  // Rendering a replacement scope cannot borrow the old mirror while its
  // after-render commit is pending. This is a read-only identity check.
  const visibleBinding = computed(() => {
    const state = binding()
    const inputs = lifetime.inputs
    return state?.owner === lifetime &&
      inputs &&
      sameScope(state.scope, inputs.scope()) &&
      state.limited === inputs.limited() &&
      state.node === inputs.target() &&
      state.content === inputs.content()
      ? state
      : null
  })

  function commit(): void {
    const inputs = lifetime.inputs
    if (!inputs) return
    const next = inputs.scope()
    const node = inputs.target()
    const natural = inputs.content()
    const isLimited = inputs.limited()
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
      expanded.set(false)
      overflow.set(null)
      binding.set(null)
      return
    }
    if (!state) {
      state = {
        owner: lifetime,
        scope: next,
        node,
        content: natural,
        limited: isLimited,
        expanded: intent(next),
        ancestors: [],
        observing: false,
        frame: null,
        cleanups: [],
        publish: null,
        reset: () => {
          binding.set(null)
          expanded.set(false)
          overflow.set(null)
        },
      }
      lifetime.binding = state
      binding.set(state)
      overflow.set(null)
      const ticket = state
      ticket.publish = () => {
        if (!current(ticket) || !ticket.scope) return
        expanded.set(intent(ticket.scope))
        const result = measure(ticket)
        if (result !== null) overflow.set(result)
      }
      const unsubscribe = subscribeSignal(next.controller.expandedText, () => {
        if (!current(ticket) || !ticket.scope) return
        expanded.set(intent(ticket.scope))
        schedule(ticket)
      })
      state.cleanups.push(unsubscribe)
      observe(state)
    }
    state.expanded = intent(next)
    expanded.set(state.expanded)
    schedule(state)
  }

  return {
    expanded: computed(() => visibleBinding() !== null && expanded()),
    overflow: computed(() => (visibleBinding() !== null ? overflow() : null)),
    binding: computed<object | null>(() => visibleBinding()),
    commit,
    isCurrent: () => current(lifetime.binding),
    setExpanded(value: boolean) {
      const state = lifetime.binding
      if (!current(state) || !state.scope || !state.limited) return
      state.scope.controller.expandText(
        state.scope.record,
        state.scope.columnKey,
        value,
      )
    },
    requestMeasure() {
      if (lifetime.binding) schedule(lifetime.binding)
    },
    dispose() {
      if (lifetime.binding) release(lifetime.binding)
      lifetime.inputs = null
      expanded.set(false)
      overflow.set(null)
      binding.set(null)
    },
  }
}
