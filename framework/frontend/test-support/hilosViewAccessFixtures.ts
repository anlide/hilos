// Inputs reach the real session/page bindings; the fixture supplies no loss policy.
import {
  bindAccessLoss,
  bindPageScope,
  bindSessionScope,
  createAuthGate,
  createHilosRouter,
  createPageRouter,
  HilosPages,
  resolveHilosPath,
  ScopeManager,
  sessionUserId,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
  type AuthGate,
  type HilosConnection,
  type HilosRouter,
  type HilosPageIdentity,
  type PageError,
  type PageView,
  type ProjectSignal,
} from '@hilos/core/base'
import { vi } from 'vitest'

/** Only native SDK inputs; no expected fields or access-loss implementation. */
export interface AccessOutletInput<T> {
  router: HilosRouter
  pages: Record<string, PageView<T>>
  authSurface?: T
  authGate?: AuthGate
}

export interface AccessOutlet {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
  type(input: HTMLInputElement, value: string): Promise<void>
}

/** The dirty dialog differs from the deletion card: closing asks for confirmation. */
export interface HilosViewAccessKit<T> {
  dirty: T
  auth: T
  known: T
  status: T
  card(preserve: boolean): { component: T }
  mount(input: AccessOutletInput<T>): Promise<AccessOutlet>
}

export type WithHilosViewAccess<T> = (
  steps: (kit: HilosViewAccessKit<T>) => Promise<void>,
  afterUnmount: () => void,
) => Promise<void>

/** One frame source, real normalization, correlation, navigation and auth gate. */
export function accessSession(initial = '/') {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const send = vi.fn(() => true)
  const connection = {
    state: 'connected',
    reconnectDragging: false,
    on(event: string, listener: (payload: never) => void): () => void {
      const projectListener = listener as (signal: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(projectListener)
      return () => listeners.delete(projectListener)
    },
    send,
    forgetPageFrames: () => {},
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  const pages = bindPageScope(connection, scopes)
  let pathname = initial
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      lazy: { path: '/lazy/{id?}', admin: true },
      other: { path: '/other', admin: false },
      [HilosPages.PUBLIC_STATUS]: {
        path: resolveHilosPath(HilosPages.PUBLIC_STATUS)!,
        admin: false,
      },
    }),
    pages,
    {
      pathname: () => pathname,
      pushState: (next) => {
        pathname = next
      },
      replaceState: (next) => {
        pathname = next
      },
      onPopState: () => () => {},
    },
  )
  const releaseLoss = bindAccessLoss(scopes, router)
  pages.releaseOnSession()
  router.start()
  const gate = createAuthGate({ router, currentUserId: sessionUserId(scopes) })
  const emit = (type: string, data: unknown): void => {
    for (const listener of listeners) {
      listener({ kind: 'project', type, data, envelope: {} } as ProjectSignal)
    }
  }
  return {
    router,
    gate,
    send,
    handshake(id: number | null, data: Record<string, unknown> = {}) {
      emit(SIGNAL_HANDSHAKE_RESPONSE, {
        entities: { currentUser: id === null ? null : { id } },
        data: { accountBlocked: null, ...data },
      })
    },
    answer(identity?: HilosPageIdentity) {
      emit(SIGNAL_TYPE_PAGE_RESPONSE, {
        page: router.currentRoute.get().page,
        payload: identity
          ? {
              data: {
                pageLabel: identity.label,
                pageLead: identity.lead,
                pageBreadcrumb: identity.breadcrumb,
                pageChildren: identity.children,
              },
            }
          : {},
      })
    },
    refuse(error: PageError) {
      emit(SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR, {
        ...error,
        page: router.currentRoute.get().page,
      })
    },
    release() {
      releaseLoss()
      router.stop()
      pages.unsubscribe()
      gate.dismiss()
      listeners.clear()
    },
  }
}
