// The Sessions tab of a person's card (HIL-1662), mounted whole: the shared
// head, the profile's session row and the two windows over the real scopes,
// action lifecycle and confirmation step. The fixture plays the server: it
// answers the confirmation step by `stepUp`, holds an ending until a test answers
// it, and changes the list only when a test says the server did. The real
// server's answer to an ending and its update of the list reach the tab in
// either order, the answer usually first, so the tests play both.
import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindSessionScope,
  computedSignal,
  createAppPageRouter,
  createSignal,
  entityCollection,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  hilosToasts,
  HilosPages,
  pageIdentity,
  resolvePageTitle,
  ScopeManager,
  USER_ENTITY_TYPE,
  userFromFields,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
  type RtStalenessStatus,
} from '@hilos/core/base'
import {
  HILOS_USER_STANDING_SECTION,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import { HilosUserSessionsPage } from './index.js'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** What the server says when an operation's confirmation ran out under a window. */
const EXPIRED =
  'Your confirmation has expired. Close this window and start again.'

/** The moment the fixture's sessions were created. */
const CREATED_AT = 1789812000000

/** How the fixture answers the confirmation step. */
type StepUpAnswer = 'skip' | 'ask' | 'refused'

/** One session of the person as the list delivers it. */
interface SessionFixture {
  readonly id: number
  readonly lastSeenAt: number
  readonly tabs?: readonly string[]
  /** The administrator acting in it, and whether their person row came along. */
  readonly impersonator?: { readonly id: number; readonly delivered: boolean }
}

const mounted: ReturnType<typeof mount>[] = []
const releases: (() => void)[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  for (const release of releases.splice(0)) {
    release()
  }
  hilosToasts.clear()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/**
 * Navigation over the production page identity, on the Sessions tab.
 *
 * @param scopes The page scopes the shell reads.
 */
function router(scopes: ScopeManager): HilosRouter {
  const identity = pageIdentity(scopes)
  const routes = createAppPageRouter({})

  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.USER_SESSIONS,
      params: { userId: '1' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/user/1/sessions'),
    currentTitle: computedSignal(() =>
      resolvePageTitle(
        HilosPages.USER_SESSIONS,
        {},
        'Test app',
        identity.get()?.label,
        true,
        identity.get()?.pageHeadOwner?.label,
      ),
    ),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === HilosPages.USER_ROLES || page === HilosPages.USER_ACCESS
        ? undefined
        : routes.path(page, params),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * A standing as the wire carries it.
 *
 * @param facts The facts that differ from a plain account.
 */
function standing(
  facts: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    shown: 'none',
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    deletionGraceDays: null,
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
    ...facts,
  }
}

/**
 * The card of person #1 (Alice) on its Sessions tab, as its page answered, and
 * the server the tab talks to.
 *
 * @param options How the step answers, who is signed in, and the person's standing.
 */
function sessionsWorld(
  options: {
    stepUp?: StepUpAnswer
    scopes?: ScopeManager
    currentUserId?: number
    standing?: Record<string, unknown>
    answered?: boolean
  } = {},
) {
  const scopes = options.scopes ?? new ScopeManager()
  const page = scopes.openPage(HilosPages.USER_SESSIONS)
  page.data.set('pageLabel', 'Sessions')
  page.data.set('pageHeadOwner', {
    page: HilosPages.USER,
    label: 'User',
    lead: 'A single user: profile, presence, and account actions.',
    icon: null,
  })
  page.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.USERS, label: 'Users' },
    { page: HilosPages.USER, label: 'User' },
  ])
  page.data.set('pageTabs', [
    { page: HilosPages.USER, label: 'Overview' },
    { page: HilosPages.USER_SESSIONS, label: 'Sessions' },
  ])
  if (!options.scopes) {
    scopes.session.data.set('currentUser', {
      type: 'user',
      id: options.currentUserId ?? 99,
    })
  }
  page.entities.upsert(
    { type: 'user', id: 99 },
    { id: 99, name: 'Admin', lastActivity: null },
  )
  if (options.answered !== false) {
    page.data.set('userSessionsEnabled', true)
    page.data.set(HILOS_USER_STANDING_SECTION, options.standing ?? standing())
    page.entities.upsert(
      { type: 'user', id: 1 },
      { id: 1, name: 'Alice', lastActivity: null },
    )
    page.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      connections: { presence: 'online', onlineSessionCount: 1 },
    })
  }

  const listeners = new Map<string, Set<(payload: unknown) => void>>()
  const emit = (event: string, payload: unknown): void => {
    for (const listener of listeners.get(event) ?? []) {
      listener(payload)
    }
  }
  const sent: Array<{
    action: string
    data: Record<string, unknown>
    requestId?: string
  }> = []
  // The server's word on the step, on the next microtask as a socket would.
  const answerStepUp = (action: string, requestId?: string): void => {
    const stepUp = options.stepUp ?? 'skip'
    if (action === 'hilos_step_up_start' && stepUp === 'refused') {
      emit('actionError', {
        kind: 'actionError',
        action,
        requestId,
        reason:
          'Add a password, an email or a phone to your account to do this',
      })

      return
    }
    emit('actionSuccess', {
      kind: 'actionSuccess',
      action,
      requestId,
      reply:
        action === 'hilos_step_up_start'
          ? {
              required: stepUp === 'ask',
              purpose: 'end sessions',
              method: 'password',
            }
          : undefined,
    })
  }
  const connection: {
    state: string
    rtStaleness: RtStalenessStatus
    sendAction(
      action: string,
      data: Record<string, unknown>,
      requestId?: string,
    ): boolean
    on(event: string, listener: (payload: never) => void): () => void
  } = {
    state: 'connected',
    rtStaleness: { stale: false, since: undefined },
    sendAction(
      action: string,
      data: Record<string, unknown>,
      requestId?: string,
    ): boolean {
      sent.push({ action, data, requestId })
      if (action.startsWith('hilos_step_up_')) {
        queueMicrotask(() => answerStepUp(action, requestId))
      }

      return true
    },
    on(event: string, listener: (payload: never) => void): () => void {
      const heard = listener as (payload: unknown) => void
      const set = listeners.get(event) ?? new Set()
      set.add(heard)
      listeners.set(event, set)

      return () => set.delete(heard)
    },
  }
  const context: HilosUsersContext = {
    scopes,
    connection: connection as unknown as HilosConnection,
    actions: new ActionLifecycle(connection as unknown as HilosConnection),
    users: entityCollection(scopes, USER_ENTITY_TYPE, userFromFields),
  }

  return {
    context,
    sent,
    /** The endings sent, without the confirmation step's own requests. */
    endings: () =>
      sent.filter((entry) => !entry.action.startsWith('hilos_step_up_')),
    /** The server delivers one session of the person, or its update. */
    give(session: SessionFixture): void {
      page.entities.upsert(
        { type: 'session', id: session.id },
        {
          id: session.id,
          userId: 1,
          deviceName: `Browser ${session.id}`,
          browserTimeZone: 'Europe/Warsaw',
          createdAt: CREATED_AT,
          lastSeenAt: session.lastSeenAt,
          expiresAt: null,
          impersonatorUserId: session.impersonator?.id ?? null,
        },
      )
      page.lists.upsert('userSessions', session.id, {
        sessions: { type: 'session', id: session.id },
        connections: (session.tabs ?? []).map((acceptKey, index) => ({
          acceptKey,
          sessionId: session.id,
          connectedAt: CREATED_AT + index,
        })),
        ...(session.impersonator?.delivered
          ? { users: { type: 'user', id: session.impersonator.id } }
          : {}),
      })
    },
    /** The server takes one session out of the person's list. */
    take(sessionId: number): void {
      page.lists.delete('userSessions', sessionId)
    },
    /** The server renames a person. */
    rename(userId: number, name: string): void {
      page.entities.upsert({ type: 'user', id: userId }, { name })
    },
    /** The server answers the last ending sent: its success sentence, or a refusal. */
    answer(outcome: { message: string } | { reason: string }): void {
      const last = sent
        .filter((entry) => !entry.action.startsWith('hilos_step_up_'))
        .at(-1)!
      if ('message' in outcome) {
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action: last.action,
          requestId: last.requestId,
          message: outcome.message,
        })

        return
      }
      emit('actionError', {
        kind: 'actionError',
        action: last.action,
        requestId: last.requestId,
        reason: outcome.reason,
      })
    },
    /** The connection moves, and says whether it serves a frozen copy. */
    connect(state: string, stale: boolean): void {
      connection.state = state
      emit('state', state)
      connection.rtStaleness = { stale, since: stale ? CREATED_AT : undefined }
      emit('rtStaleness', connection.rtStaleness)
    },
    /** The server says the person's standing changed. */
    stand(accountStanding: Record<string, unknown>): void {
      emit('projectSignal', {
        kind: 'project',
        type: 'hilos_account_standing_state',
        data: { userId: 1, accountStanding },
      })
    },
  }
}

/**
 * Sign the tab in through a real handshake, so the admin access is the session's.
 *
 * @param user The signed-in person.
 * @param viewMode Whether the node shows its admin section to a viewer.
 */
function signIn(
  user: { id: number; admin: boolean },
  viewMode: boolean,
): ScopeManager {
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  releases.push(bindAdminAccess(scopes))
  const signal = {
    kind: 'project',
    type: 'handshake_response',
    data: {
      entities: { currentUser: { ...user, name: 'Olena' } },
      data: { adminViewMode: viewMode },
    },
    envelope: {},
  } as unknown as ProjectSignal
  for (const listener of listeners) {
    listener(signal)
  }

  return scopes
}

/**
 * Mount the tab over a world.
 *
 * @param context The world's context.
 */
async function mountTab(context: HilosUsersContext) {
  const wrapper = mount(HilosUserSessionsPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: {
      provide: { [hilosRouterKey as symbol]: router(context.scopes) },
    },
  })
  mounted.push(wrapper)
  await settle()

  return wrapper
}

async function settle(): Promise<void> {
  await flushPromises()
  await nextTick()
}

function el(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/** The open window, or null. */
function dialog(): HTMLElement | null {
  return el('modal')
}

/**
 * A button of the open window by its words.
 *
 * @param label The button's text.
 */
function windowButton(label: string): HTMLButtonElement | undefined {
  return Array.from(
    dialog()?.querySelectorAll<HTMLButtonElement>('button') ?? [],
  ).find((button) => button.textContent?.trim() === label)
}

/**
 * The End button of one session's row.
 *
 * @param sessionId The session.
 */
function endButton(sessionId: number): HTMLButtonElement {
  return document.querySelector<HTMLButtonElement>(
    `[data-id="user-session-row"][data-record="${sessionId}"] [data-id="user-session-end"]`,
  )!
}

async function click(button: HTMLElement | null | undefined): Promise<void> {
  button?.click()
  await settle()
}

async function passStep(): Promise<void> {
  const input = el('step-up-password') as HTMLInputElement
  input.value = 'secret'
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  input
    .closest('form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await settle()
}

function successToasts(): string[] {
  return hilosToasts.toasts
    .get()
    .filter((toast) => toast.severity === 'success')
    .map((toast) => toast.message)
}

/**
 * Watch the document for words from now on, the moment between two renders
 * included: whether they were ever on it, drawn or read out. The document as it
 * stands when asked shows only the last render, so every change is read from
 * its record: the text a node held before it changed, and the nodes put in or
 * taken out, which keep what they held.
 *
 * @param words The words to watch for.
 * @returns Whether they were seen since the watch began.
 */
function watchWords(words: string): () => boolean {
  const holds = (text: string | null | undefined): boolean =>
    text?.includes(words) === true
  let seen = holds(document.body.textContent)
  const read = (records: MutationRecord[]): void => {
    for (const record of records) {
      seen ||=
        holds(record.oldValue) ||
        holds(record.target.textContent) ||
        [...record.addedNodes, ...record.removedNodes].some((node) =>
          holds(node.textContent),
        )
    }
  }
  const observer = new MutationObserver(read)
  observer.observe(document.body, {
    subtree: true,
    childList: true,
    characterData: true,
    characterDataOldValue: true,
  })
  releases.push(() => observer.disconnect())

  return () => {
    read(observer.takeRecords())

    return seen
  }
}

/** What the open window reads out at once, trimmed. */
function windowAlert(): string | undefined {
  return dialog()
    ?.querySelector('.visually-hidden[role="alert"]')
    ?.textContent?.trim()
}

describe('HilosUserSessionsPage list', () => {
  it('shows a placeholder until the page answers', async () => {
    const world = sessionsWorld({ answered: false })
    const wrapper = await mountTab(world.context)

    expect(wrapper.find('[data-id="hilos-user-sessions-page"]').exists()).toBe(
      false,
    )
    expect(wrapper.text()).toContain('Loading sessions…')
    expect(wrapper.text()).not.toContain('No sessions')
  })

  it('draws the sessions newest first, opens one row of tabs, and names who acts in a session live', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT + 10, tabs: ['ak-a', 'ak-b'] })
    world.give({
      id: 12,
      lastSeenAt: CREATED_AT + 30,
      impersonator: { id: 99, delivered: true },
    })
    world.give({
      id: 13,
      lastSeenAt: CREATED_AT + 20,
      impersonator: { id: 77, delivered: false },
    })
    const wrapper = await mountTab(world.context)

    const rows = wrapper.findAll('[data-id="user-session-row"]')
    expect(rows.map((row) => row.attributes('data-record'))).toEqual([
      '12',
      '13',
      '11',
    ])
    expect(rows[0].get('h3').text()).toBe('Session #12 Acting as · Admin')
    expect(rows[1].get('h3').text()).toBe('Session #13 Acting as · #77')
    expect(rows[2].get('h3').text()).toBe('Session #11')

    const toggle = rows[2].get('[data-id="user-session-tabs"]')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(rows[2].findAll('[data-id="user-session-tab"]')).toHaveLength(2)
    expect(
      rows[0].get('[data-id="user-session-tabs"]').attributes('aria-expanded'),
    ).toBe('false')

    // A rename of the administrator reaches the mark; an update of the session
    // keeps its tabs open.
    world.rename(99, 'Olena')
    world.give({ id: 11, lastSeenAt: CREATED_AT + 10, tabs: ['ak-a'] })
    await nextTick()
    const after = wrapper.findAll('[data-id="user-session-row"]')
    expect(after[0].get('h3').text()).toBe('Session #12 Acting as · Olena')
    expect(after[2].findAll('[data-id="user-session-tab"]')).toHaveLength(1)
    expect(world.sent).toEqual([])
  })

  it('says there are no sessions and offers no ending of them all', async () => {
    const world = sessionsWorld()
    const wrapper = await mountTab(world.context)

    expect(wrapper.text()).toContain('No sessions.')
    expect(wrapper.find('[data-id="user-session-row"]').exists()).toBe(false)
    expect((el('user-sessions-end-all') as HTMLButtonElement).disabled).toBe(
      true,
    )
    await click(el('user-sessions-end-all'))
    expect(dialog()).toBeNull()
    expect(world.sent).toEqual([])
  })

  it('says over the list that the tabs may lag only while a connected socket serves a frozen copy', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a'] })
    const wrapper = await mountTab(world.context)
    const strip = (): string =>
      wrapper.get('[data-id="hilos-user-sessions-page"] [role="status"]').text()

    expect(strip()).not.toContain('may lag')
    world.connect('connected', true)
    await nextTick()
    expect(strip()).toContain('The tab lists may lag behind right now')
    // The list and its endings stay as they were.
    expect(wrapper.findAll('[data-id="user-session-row"]')).toHaveLength(1)
    expect(endButton(11).disabled).toBe(false)

    // One line at any width: the whole of it opens behind the button beside it,
    // and goes with the strip.
    await click(el('user-sessions-lag-details'))
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe('Details')
    expect(el('user-sessions-lag-full')?.textContent?.trim()).toBe(
      'The tab lists may lag behind right now; the sessions themselves are current.',
    )

    world.connect('reconnecting', true)
    await settle()
    expect(dialog()).toBeNull()
    expect(strip()).not.toContain('may lag')
    world.connect('connected', false)
    await nextTick()
    expect(strip()).not.toContain('may lag')
  })
})

describe('HilosUserSessionsPage ending one session', () => {
  it('opens on the row it was pressed on and sends nothing until Confirm; Cancel, Escape, the close button and Enter change nothing', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a'] })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    await click(endButton(11))
    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'hilos_step_up_start',
        data: { operation: 'end_user_sessions' },
      }),
    ])
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe(
      'End session #11',
    )
    expect(dialog()?.textContent).toContain(
      'Tabs of this session lose the account at once',
    )
    expect(dialog()?.textContent).toContain(
      "The person's other sessions are not touched",
    )
    expect(dialog()?.textContent).not.toContain('This is your own account')

    dialog()!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    await settle()
    await click(windowButton('Cancel'))
    expect(dialog()).toBeNull()

    await click(endButton(11))
    dialog()!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settle()
    expect(dialog()).toBeNull()

    await click(endButton(12))
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe(
      'End session #12',
    )
    await click(el('modal-close'))
    expect(dialog()).toBeNull()
    expect(world.endings()).toEqual([])
  })

  it('holds the window while the ending is in flight, keeps a refusal in it, and closes on the server word without taking the row', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a'] })
    const wrapper = await mountTab(world.context)

    await click(endButton(11))
    const confirm = el('user-session-end-confirm') as HTMLButtonElement
    await click(confirm)
    expect(world.endings()).toEqual([
      expect.objectContaining({
        action: 'hilos_user_session_end',
        data: { userId: 1, sessionId: 11 },
      }),
    ])
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(windowButton('Cancel')?.disabled).toBe(true)
    await click(confirm)
    dialog()!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settle()
    expect(dialog()).not.toBeNull()
    expect(world.endings()).toHaveLength(1)

    world.answer({ reason: 'The sessions library is not answering' })
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-session-end-error')?.textContent).toContain(
      'The sessions library is not answering',
    )
    expect(confirm.disabled).toBe(false)

    await click(confirm)
    expect(world.endings()).toHaveLength(2)
    world.answer({ message: 'Session #11 ended' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Session #11 ended'])
    // The row stays until the server takes it out of the list.
    expect(wrapper.findAll('[data-id="user-session-row"]')).toHaveLength(1)
    world.take(11)
    await nextTick()
    expect(wrapper.findAll('[data-id="user-session-row"]')).toHaveLength(0)
  })

  it('says nothing of a session gone while its own ending waits, closes on the answer and gives the focus to End all', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    endButton(11).focus()
    await click(endButton(11))
    const confirm = el('user-session-end-confirm') as HTMLButtonElement
    await click(confirm)
    const gone = watchWords('This session has already ended')
    // The server ends the session first and answers after: its row leaves the
    // list, and its End with it, before the word comes.
    world.take(11)
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-session-end-error')).toBeNull()
    expect(windowAlert()).toBe('')
    expect(confirm.getAttribute('aria-busy')).toBe('true')

    world.answer({ message: 'Session #11 ended' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Session #11 ended'])
    expect(gone()).toBe(false)
    expect(document.activeElement).toBe(el('user-sessions-end-all'))
  })

  it('gives the focus to End all when the answer comes before the row leaves', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    endButton(11).focus()
    await click(endButton(11))
    await click(el('user-session-end-confirm'))
    const gone = watchWords('This session has already ended')
    // The usual order: the server answers, and its update takes the row after.
    world.answer({ message: 'Session #11 ended' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Session #11 ended'])
    // The window gives the focus back to the End it was opened from, still drawn.
    expect(document.activeElement).toBe(endButton(11))

    world.take(11)
    await settle()
    expect(document.activeElement).toBe(el('user-sessions-end-all'))
    expect(gone()).toBe(false)
  })

  it('gives the focus to the tab when the last session leaves after the answer', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    endButton(11).focus()
    await click(endButton(11))
    await click(el('user-session-end-confirm'))
    world.answer({ message: 'Session #11 ended' })
    await settle()
    expect(document.activeElement).toBe(endButton(11))

    world.take(11)
    await settle()
    // End all sessions is off over an empty list; the tab takes the focus.
    expect((el('user-sessions-end-all') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(document.activeElement).toBe(el('hilos-user-sessions-page'))
  })

  it('leaves the focus where it stands when another row leaves', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    endButton(12).focus()
    world.take(11)
    await settle()
    expect(document.activeElement).toBe(endButton(12))
  })

  it('says the session is gone once a refusal ends the wait its row left in', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    await click(endButton(11))
    await click(el('user-session-end-confirm'))
    world.take(11)
    await settle()
    expect(el('user-session-end-error')).toBeNull()
    world.answer({ reason: 'The sessions library is not answering' })
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-session-end-error')?.textContent).toContain(
      'This session has already ended',
    )
    expect((el('user-session-end-confirm') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(world.endings()).toHaveLength(1)
  })

  it('keeps the window on a session that left the list, says it has already ended and sends nothing', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    await click(endButton(11))
    expect(el('user-session-end-error')).toBeNull()
    // Ended elsewhere, or bound to another person: either way it leaves this list.
    world.take(11)
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-session-end-error')?.textContent).toContain(
      'This session has already ended',
    )
    const confirm = el('user-session-end-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    await click(confirm)
    expect(world.endings()).toEqual([])
  })

  it('tells an administrator acting in a session that their browser goes back to them, and warns on their own card', async () => {
    const world = sessionsWorld({ currentUserId: 1 })
    world.give({
      id: 12,
      lastSeenAt: CREATED_AT,
      impersonator: { id: 99, delivered: true },
    })
    await mountTab(world.context)

    await click(endButton(12))
    const text = dialog()?.textContent ?? ''
    expect(text).toContain('In this session Admin is acting as the person')
    expect(text).toContain('takes this browser back to that administrator')
    expect(text).toContain("The person's own sessions are not touched")
    expect(text).not.toContain('the sign-in form takes the place')
    expect(text).toContain(
      'This is your own account: if you are using this session now, this tab loses the account too.',
    )
  })
})

describe('HilosUserSessionsPage ending all sessions', () => {
  it('reads its number from the live list and says the number the server ended', async () => {
    const world = sessionsWorld({ currentUserId: 1 })
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a', 'ak-b'] })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    await click(el('user-sessions-end-all'))
    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'hilos_step_up_start',
        data: { operation: 'end_user_sessions' },
      }),
    ])
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe(
      'End all sessions · Alice',
    )
    const count = (): string | undefined =>
      dialog()?.querySelector('strong')?.textContent?.trim()
    expect(count()).toBe('2')
    expect(dialog()?.textContent).toContain(
      'This is your own account: your current session ends too',
    )
    expect(dialog()?.textContent).not.toContain('takes that browser back')

    world.give({
      id: 13,
      lastSeenAt: CREATED_AT + 2,
      impersonator: { id: 99, delivered: true },
    })
    await nextTick()
    expect(count()).toBe('3')
    expect(dialog()?.textContent).toContain('takes that browser back')

    await click(el('user-sessions-end-all-confirm'))
    expect(world.endings()).toEqual([
      expect.objectContaining({
        action: 'hilos_user_sessions_end_all',
        data: { userId: 1 },
      }),
    ])
    world.answer({ message: 'Ended 4 sessions' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Ended 4 sessions'])
  })

  it('says nothing of an emptied list while its own ending waits, closes on the answer and gives the focus to the tab', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    const endAll = el('user-sessions-end-all') as HTMLButtonElement
    endAll.focus()
    await click(endAll)
    const confirm = el('user-sessions-end-all-confirm') as HTMLButtonElement
    await click(confirm)
    const gone = watchWords('All sessions have already ended')
    world.take(11)
    world.take(12)
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-sessions-end-all-error')).toBeNull()
    expect(windowAlert()).toBe('')
    expect(confirm.getAttribute('aria-busy')).toBe('true')

    world.answer({ message: 'Ended 2 sessions' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Ended 2 sessions'])
    expect(gone()).toBe(false)
    // End all sessions is off over an empty list; the tab takes the focus.
    expect(endAll.disabled).toBe(true)
    expect(document.activeElement).toBe(el('hilos-user-sessions-page'))
  })

  it('gives the focus to the tab when the answer comes before the list empties', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    world.give({ id: 12, lastSeenAt: CREATED_AT + 1 })
    await mountTab(world.context)

    const endAll = el('user-sessions-end-all') as HTMLButtonElement
    endAll.focus()
    await click(endAll)
    await click(el('user-sessions-end-all-confirm'))
    const gone = watchWords('All sessions have already ended')
    // The usual order: the server answers, and its update empties the list after.
    world.answer({ message: 'Ended 2 sessions' })
    await settle()
    expect(dialog()).toBeNull()
    expect(successToasts()).toEqual(['Ended 2 sessions'])
    expect(document.activeElement).toBe(endAll)

    // End all sessions keeps the focus while it can be pressed.
    world.take(11)
    await settle()
    expect(document.activeElement).toBe(endAll)
    world.take(12)
    await settle()
    expect(endAll.disabled).toBe(true)
    expect(document.activeElement).toBe(el('hilos-user-sessions-page'))
    expect(gone()).toBe(false)
  })

  it('holds Confirm with the list emptied under the window', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    await click(el('user-sessions-end-all'))
    expect(el('user-sessions-end-all-error')).toBeNull()
    world.take(11)
    await settle()
    expect(dialog()).not.toBeNull()
    expect(el('user-sessions-end-all-error')?.textContent).toContain(
      'All sessions have already ended',
    )
    const confirm = el('user-sessions-end-all-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    await click(confirm)
    expect(world.endings()).toEqual([])
  })
})

describe('HilosUserSessionsPage confirmation step', () => {
  it('asks the administrator first, and after an expired confirmation asks again on the next opening', async () => {
    const world = sessionsWorld({ stepUp: 'ask' })
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    await click(endButton(11))
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe(
      "Confirm it's you",
    )
    expect(el('step-up-password')).not.toBeNull()
    expect(el('user-session-end-confirm')).toBeNull()

    await passStep()
    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({
      data: { operation: 'end_user_sessions', password: 'secret' },
    })
    expect(dialog()?.querySelector('.modal-title')?.textContent).toBe(
      'End session #11',
    )
    await click(el('user-session-end-confirm'))
    world.answer({ reason: EXPIRED })
    await settle()
    expect(el('user-session-end-error')?.textContent).toContain(EXPIRED)

    await click(windowButton('Cancel'))
    await click(endButton(11))
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_step_up_start'),
    ).toHaveLength(2)
    expect(el('step-up-password')).not.toBeNull()
    await passStep()
    // The window opens clean: the refusal belonged to the earlier opening.
    expect(el('user-session-end-error')).toBeNull()
    await click(el('user-session-end-confirm'))
    world.answer({ message: 'Session #11 ended' })
    await settle()
    expect(dialog()).toBeNull()
    expect(world.endings()).toHaveLength(2)
  })

  it('draws the step refusal with Cancel alone', async () => {
    const world = sessionsWorld({ stepUp: 'refused' })
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    await click(el('user-sessions-end-all'))
    expect(el('step-up-error')?.textContent).toContain(
      'Add a password, an email or a phone',
    )
    expect(windowButton('Cancel')).toBeDefined()
    expect(windowButton('Confirm')).toBeUndefined()
    expect(el('user-sessions-end-all-confirm')).toBeNull()
    expect(world.endings()).toEqual([])
  })
})

describe('HilosUserSessionsPage view mode and merged card', () => {
  it('shows a viewer the list hidden and the window for all without a code, its Confirm disabled by the mode', async () => {
    const scopes = signIn({ id: 5, admin: false }, true)
    const world = sessionsWorld({ scopes })
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a'] })
    const wrapper = await mountTab(world.context)

    const tab = wrapper.get('[data-id="hilos-user-sessions-page"]')
    expect(tab.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(tab.find('[data-id="user-session-row"]').exists()).toBe(false)
    expect(tab.text()).not.toContain('Browser 11')
    expect(tab.text()).not.toContain('No sessions')

    await click(el('user-sessions-end-all'))
    expect(dialog()).not.toBeNull()
    expect(el('step-up')).toBeNull()
    expect(dialog()?.querySelector('strong')?.textContent?.trim()).toBe(
      'Hidden',
    )
    const confirm = el('user-sessions-end-all-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await click(confirm)
    expect(world.sent).toEqual([])
  })

  it('keeps a merged card to read, with no ending at all', async () => {
    const world = sessionsWorld({
      standing: standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
    })
    world.give({ id: 11, lastSeenAt: CREATED_AT, tabs: ['ak-a'] })
    const wrapper = await mountTab(world.context)

    expect(wrapper.findAll('[data-id="hilos-user-merged"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-id="user-session-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-id="user-session-end"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="user-sessions-end-all"]').exists()).toBe(
      false,
    )
    expect(world.sent).toEqual([])
  })

  it('closes an open window when the account is merged under it', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    const wrapper = await mountTab(world.context)

    await click(el('user-sessions-end-all'))
    expect(dialog()).not.toBeNull()
    world.stand(
      standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
    )
    await settle()
    expect(dialog()).toBeNull()
    expect(wrapper.find('[data-id="user-session-end"]').exists()).toBe(false)
    expect(world.endings()).toEqual([])
  })

  it('gives the focus to the tab when a merge takes End all sessions away from under it', async () => {
    const world = sessionsWorld()
    world.give({ id: 11, lastSeenAt: CREATED_AT })
    await mountTab(world.context)

    el('user-sessions-end-all')!.focus()
    world.stand(
      standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
    )
    await settle()
    expect(el('user-sessions-end-all')).toBeNull()
    expect(document.activeElement).toBe(el('hilos-user-sessions-page'))
  })
})
