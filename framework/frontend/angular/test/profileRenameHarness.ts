// A standard profile binding over the real connection parser, session stores,
// action lifecycle and held name state. Only the server socket is substituted.
import {
  bindProfileNameState,
  bindSessionScope,
  createHilosConnection,
  createHilosProfileRename,
  PROFILE_NAME_SET_ACTION,
  HilosPages,
  sessionUserName,
  ScopeManager,
  type HilosProfileNameChange,
  type WebSocketLike,
} from '@hilos/core/base'

/** The permanent checking region in the name form, located by its live semantics. */
export function profileNameStatus(): HTMLElement {
  const field = document.querySelector<HTMLInputElement>(
    '[data-id="profile-name-input"]',
  )
  const status = field?.form?.querySelector<HTMLElement>(
    '.hilos-stack > [role="status"]',
  )
  if (!status) throw new Error('Missing profile name status')
  return status
}

/** A fresh application connection; storage stays with the caller's tab. */
export function profileRenameTab(
  known = true,
  options: { stepUp?: unknown; hold?: readonly string[] } = {},
) {
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const frames: Array<{
    type: string
    action?: string
    requestId?: string
    data: unknown
  }> = []
  const tell = (type: string, data: unknown, requestId?: string): void => {
    for (const listener of listeners.get('message') ?? []) {
      listener({ data: JSON.stringify({ type, data, requestId }) })
    }
  }
  const reply = (frame: (typeof frames)[number], reason?: string): void => {
    tell(
      reason === undefined ? 'action_success' : 'action_error',
      reason === undefined
        ? {
            action: frame.action,
            reply:
              frame.action === 'hilos_step_up_start'
                ? (options.stepUp ?? {
                    required: false,
                    purpose: 'change your name',
                  })
                : [],
          }
        : { action: frame.action, reason },
      frame.requestId,
    )
  }
  const socket: WebSocketLike = {
    send(raw) {
      const frame = JSON.parse(String(raw)) as (typeof frames)[number]
      frames.push(frame)
      if (frame.requestId && !options.hold?.includes(frame.action ?? '')) {
        queueMicrotask(() => reply(frame))
      }
    },
    close() {},
    addEventListener(type, listener) {
      const handlers = listeners.get(type) ?? []
      handlers.push(listener)
      listeners.set(type, handlers)
    },
  }
  const bundle = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
  })
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.PROFILE)
  page.data.set('pageLabel', 'Profile')
  page.data.set('pageHeadOwner', {
    page: HilosPages.PROFILE,
    label: 'Profile',
    lead: '',
  })
  page.data.set('pageTabs', [{ page: HilosPages.PROFILE, label: 'Overview' }])
  bindSessionScope(bundle.connection, scopes)
  const stopName = bindProfileNameState(bundle.connection, scopes)
  const name = sessionUserName(scopes)
  const rename = createHilosProfileRename(
    bundle.connection,
    bundle.actionErrors,
  )
  const identity = (
    id: number | null = 1,
    liveName = 'Ann',
    data: Record<string, unknown> = {},
  ): void => {
    tell('handshake_response', {
      data: {
        accountStanding: null,
        accountBlocked: null,
        pendingAck: null,
        pendingAuthStep: null,
        ...data,
      },
      entities: {
        currentUser: id === null ? null : { id, name: liveName },
        impersonatedBy: null,
      },
    })
  }
  const state = (
    phase: HilosProfileNameChange['phase'] | null,
    requestedName = 'Bob',
    refusal = ' Exact server refusal.\nTry another name. ',
  ): void => {
    tell('hilos_profile_name_state', {
      userId: 1,
      change:
        phase === null
          ? null
          : {
              requestId: 'name-1',
              name: requestedName,
              phase,
              refusal: phase === 'refused' ? refusal : null,
            },
    })
  }
  const socketOpen = (): void => {
    for (const listener of listeners.get('open') ?? []) listener({})
  }
  bundle.connection.connect()
  socketOpen()
  tell('handshake', { build: 'test' })
  if (known) {
    identity()
    state(null)
  }

  return {
    context: { connection: bundle.connection, scopes, actions: bundle.actions },
    binding: { name, rename, sessionCount: null, deviceCount: null },
    identity,
    state,
    tell,
    reply,
    frames,
    reconnect() {
      bundle.connection.connect()
      socketOpen()
      tell('handshake', { build: 'test' })
      identity()
      state('refused')
    },
    names: () =>
      frames.filter((frame) => frame.action === PROFILE_NAME_SET_ACTION),
    dispose() {
      stopName()
      bundle.connection.close()
      bundle.actionErrors.dispose()
    },
  }
}
