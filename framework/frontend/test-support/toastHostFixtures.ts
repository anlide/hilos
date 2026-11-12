// Subject-only inputs and lifecycle for the real ToastHost in each SDK.
import {
  createHilosRouter,
  createHilosToastStore,
  createPageRouter,
  createSignal,
  HILOS_ROUTE_DECLARATIONS,
  type HilosRouter,
  type HilosSessionToast,
  type HilosToastStore,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

export type ToastHostRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

/** The component's real store and the router behind its real links. */
export interface ToastHostInput {
  store: HilosToastStore
  router: HilosRouter
}

/** Native input delivery and the registering framework's rendering boundary. */
export interface ToastHostView {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
  click(target: HTMLElement, init?: MouseEventInit): Promise<void>
  mouseOver(target: HTMLElement): Promise<void>
  mouseLeave(target: HTMLElement): Promise<void>
  unmount(): Promise<void>
}

/** Cleanup covers creation, initial rendering and every subsequent step. */
export type WithToastHost = (
  input: ToastHostInput,
  steps: (view: ToastHostView) => Promise<void>,
) => Promise<void>

/** A server-authored, signed notice leading to its durable record. */
export function sessionToast(): HilosSessionToast {
  return {
    key: 'toast-key',
    message: 'The export is ready',
    severity: 'info',
    source: 'Backup',
    destination: '/hilos/backup',
    repeats: 1,
  }
}

/** The real navigator over the existing routes, with isolated transport and history. */
function router(visited: string[]): HilosRouter {
  return createHilosRouter(
    createPageRouter(HILOS_ROUTE_DECLARATIONS),
    {
      subscribe: () => {},
      unsubscribe: () => {},
      refuseUnbuilt: () => {},
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
    },
    {
      pathname: () => '/',
      pushState: (path) => {
        visited.push(path)
      },
      replaceState: () => {},
      onPopState: () => () => {},
    },
  )
}

/** The count-based initial budget fills before any card can be measured. */
export function overfill(store: HilosToastStore): void {
  for (const message of ['One', 'Two', 'Three', 'Four', 'Five']) {
    store.push(message, { severity: 'info' })
  }
  store.push('The report could not be built', { severity: 'error' })
}

/** A missing element fails at the step that needs the real target. */
export function byId(root: ParentNode, id: string): HTMLElement {
  const target = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (target === null) throw new Error(`Missing toast element: ${id}`)

  return target
}

/** Normalize template whitespace without changing the words or their order. */
export function text(target: Node): string {
  return target.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

/** Restore an own host property, including the absence of an override. */
function restoreProperty(
  target: object,
  key: string,
  descriptor: PropertyDescriptor | undefined,
): void {
  if (descriptor === undefined) Reflect.deleteProperty(target, key)
  else Object.defineProperty(target, key, descriptor)
}

/**
 * Isolate the subject's real store, clock and browser measurement sources.
 * The SDK adapter destroys its component before clocks and spies are restored.
 */
export async function withToastCase(
  runner: ToastHostRunner,
  withHost: WithToastHost,
  fill: (store: HilosToastStore) => void,
  steps: (
    view: ToastHostView,
    store: HilosToastStore,
    visited: string[],
  ) => Promise<void>,
): Promise<void> {
  const { vi } = runner
  const hidden = Object.getOwnPropertyDescriptor(document, 'hidden')
  const viewport = Object.getOwnPropertyDescriptor(window, 'innerHeight')
  let store: HilosToastStore | undefined
  vi.useFakeTimers()
  try {
    Object.defineProperty(document, 'hidden', {
      configurable: true,
      get: () => false,
    })
    Object.defineProperty(window, 'innerHeight', {
      configurable: true,
      value: 768,
    })
    store = createHilosToastStore()
    const visited: string[] = []
    const navigator = router(visited)
    try {
      fill(store)
      await withHost({ store, router: navigator }, async (view) => {
        await view.flush()
        await steps(view, store as HilosToastStore, visited)
      })
    } finally {
      navigator.stop()
    }
  } finally {
    try {
      store?.clear()
      vi.restoreAllMocks()
      restoreProperty(document, 'hidden', hidden)
      restoreProperty(window, 'innerHeight', viewport)
    } finally {
      vi.useRealTimers()
    }
  }
}
