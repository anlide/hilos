import {
  computed,
  onMounted,
  onScopeDispose,
  onUpdated,
  shallowReadonly,
  shallowRef,
  useId,
  watch,
  type Ref,
} from 'vue'
import {
  subscribeSignal,
  type TableGeometryRecord,
  type TableProjection,
  type TableViewportController,
} from '@hilos/core/base'

/** Only the controller's existing text-intent seam; no geometry registration. */
export type TableTextOverflowController = Pick<
  TableViewportController<unknown>,
  'expandedText' | 'expandText'
>

/** One current live value in one mounted projection. */
export interface TableTextOverflowScope {
  readonly controller: TableTextOverflowController
  /** Exact owner-issued object, never reconstructed from a row view. */
  readonly record: TableGeometryRecord
  readonly columnKey: string
  readonly projection: TableProjection
}

export interface TableTextOverflow {
  /** Mirror of the controller's occurrence/column intent, shared by projections. */
  readonly expanded: Readonly<Ref<boolean>>
  /** Last usable collapsed measurement; null until one exists for this binding. */
  readonly overflow: Readonly<Ref<boolean | null>>
  /** Bind to the live text window's id and its control's aria-controls. */
  readonly contentId: Readonly<Ref<string>>
  readonly setExpanded: (expanded: boolean) => void
  readonly requestMeasure: () => void
  /** Early release, also called automatically when the setup scope ends. */
  readonly dispose: () => void
}

interface Binding {
  scope: TableTextOverflowScope | null
  node: HTMLElement | null
  content: HTMLElement | null
  limited: boolean
  active: boolean
  ancestors: HTMLElement[]
  observing: boolean
  frame: number | null
  resize?: ResizeObserver
  mutations?: MutationObserver
  unsubscribe?: () => void
  cleanups: (() => void)[]
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

/**
 * Read the one live text window after layout, without a hidden text clone or
 * temporarily changing its CSS. Expanded natural layout is not a collapsed
 * measurement. Hidden/zero boxes leave the previous usable result intact.
 *
 * DOM handles and overflow belong to this browser mount, with no reader in
 * Hilos::$rt (runtime/rt-context.md, architecture/truth-source.md). Logical
 * intent stays in TableViewportController and crosses subscribeSignal only.
 *
 * @param scope Reactive getter of the current live scope, or null for an
 * outgoing/departed/unavailable record. Read the existing mount's geometry
 * registration here; the getter is also checked at every asynchronous boundary.
 * @param target Ref of the full plain-text window only, excluding labels,
 * badges, hidden-value markers and controls. The caller owns its CSS preset.
 * @param content Ref of its single live, uncapped content container. Observing
 * its natural size catches stylesheet typography changes inside a fixed cap.
 * @param limited Reactive getter: true for lines/height, false for content.
 */
export function useTableTextOverflow(
  scope: () => TableTextOverflowScope | null,
  target: Readonly<Ref<HTMLElement | null>>,
  content: Readonly<Ref<HTMLElement | null>>,
  limited: () => boolean,
): TableTextOverflow {
  const id = useId()
  const expanded = shallowRef(false)
  const overflow = shallowRef<boolean | null>(null)
  const projection = shallowRef<TableProjection>('wide')
  const contentId = computed(() => `${id}-table-text-${projection.value}`)
  let readScope: typeof scope | null = scope
  let targetRef: typeof target | null = target
  let contentRef: typeof content | null = content
  let readLimited: typeof limited | null = limited
  let binding: Binding | null = null
  let mounted = false
  let stopped = false

  function current(state: Binding): boolean {
    return (
      !stopped &&
      state.active &&
      binding === state &&
      state.node === targetRef?.value &&
      state.content === contentRef?.value &&
      (!state.content || state.content.parentElement === state.node) &&
      state.limited === readLimited?.() &&
      sameScope(state.scope, readScope?.() ?? null)
    )
  }

  function release(): void {
    const state = binding
    binding = null
    if (!state) return
    state.active = false
    if (state.frame !== null) cancelAnimationFrame(state.frame)
    state.resize?.disconnect()
    state.mutations?.disconnect()
    state.unsubscribe?.()
    for (const cleanup of state.cleanups) cleanup()
    // In particular, unresolved document.fonts.ready callbacks keep only this
    // emptied ticket, never a detached node, controller or geometry record.
    state.frame = null
    state.resize = undefined
    state.mutations = undefined
    state.unsubscribe = undefined
    state.cleanups.length = 0
    state.ancestors.length = 0
    state.node = null
    state.content = null
    state.scope = null
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
    // The clipping box can stay fixed while typography changes its scroll
    // extent. Observe the actual live text's natural box, never a probe copy.
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
    if (!current(state) || !state.limited || expanded.value) return
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
    if (current(state))
      overflow.value = scrollHeight > clientHeight || scrollWidth > clientWidth
  }

  function schedule(state: Binding): void {
    if (!mounted || !current(state) || !state.node || state.frame !== null)
      return
    const frame = requestAnimationFrame(() => {
      if (!current(state) || state.frame !== frame) return
      state.frame = null
      measure(state)
    })
    state.frame = frame
  }

  function reconcile(): void {
    if (stopped) return
    const next = readScope?.() ?? null
    const node = targetRef?.value ?? null
    const natural = contentRef?.value ?? null
    const isLimited = readLimited?.() ?? false
    if (
      binding &&
      sameScope(binding.scope, next) &&
      binding.node === node &&
      binding.content === natural &&
      binding.limited === isLimited
    ) {
      schedule(binding)
      return
    }
    release()
    overflow.value = null
    expanded.value = false
    if (!next) return
    projection.value = next.projection
    const state: Binding = {
      scope: next,
      node,
      content: natural,
      limited: isLimited,
      active: true,
      ancestors: [],
      observing: false,
      frame: null,
      cleanups: [],
    }
    binding = state
    const publishIntent = (): void => {
      if (!current(state) || !state.scope) return
      const { controller, record, columnKey } = state.scope
      expanded.value =
        controller.expandedText.get().get(record.occurrence)?.has(columnKey) ??
        false
      schedule(state)
    }
    state.unsubscribe = subscribeSignal(
      next.controller.expandedText,
      publishIntent,
    )
    publishIntent()
    if (!mounted || !node || !isLimited) return
    const changed = (): void => {
      if (current(state)) schedule(state)
    }
    state.resize =
      typeof ResizeObserver === 'undefined'
        ? undefined
        : new ResizeObserver(changed)
    state.mutations =
      typeof MutationObserver === 'undefined'
        ? undefined
        : new MutationObserver(changed)
    observeAncestors(state)
    const document = node.ownerDocument
    const window = document.defaultView
    const fonts = document.fonts
    document.addEventListener('visibilitychange', changed)
    window?.addEventListener('resize', changed)
    node.addEventListener('transitionend', changed)
    node.addEventListener('animationend', changed)
    fonts?.addEventListener('loadingdone', changed)
    void fonts?.ready.then(changed)
    // These disposers are emptied before a late callback can retain the target.
    state.cleanups.push(() => {
      document.removeEventListener('visibilitychange', changed)
      window?.removeEventListener('resize', changed)
      state.node?.removeEventListener('transitionend', changed)
      state.node?.removeEventListener('animationend', changed)
      fonts?.removeEventListener('loadingdone', changed)
    })
    schedule(state)
  }

  let stopWatch: (() => void) | undefined = watch(
    () => {
      const next = readScope?.()
      return [
        next?.controller,
        next?.record,
        next?.columnKey,
        next?.projection,
        targetRef?.value,
        contentRef?.value,
        readLimited?.(),
      ]
    },
    reconcile,
    { immediate: true, flush: 'sync' },
  )
  onMounted(() => {
    mounted = true
    // The setup binding has no DOM listeners yet, even if its ref was supplied.
    release()
    reconcile()
  })
  onUpdated(reconcile)

  function dispose(): void {
    if (stopped) return
    stopped = true
    stopWatch?.()
    stopWatch = undefined
    release()
    readScope = null
    targetRef = null
    contentRef = null
    readLimited = null
    overflow.value = null
    expanded.value = false
  }
  onScopeDispose(dispose)

  return {
    expanded: shallowReadonly(expanded),
    overflow: shallowReadonly(overflow),
    contentId,
    setExpanded(value) {
      const state = binding
      if (!state || !current(state) || !state.scope || !state.limited) return
      state.scope.controller.expandText(
        state.scope.record,
        state.scope.columnKey,
        value,
      )
    },
    requestMeasure: reconcile,
    dispose,
  }
}
