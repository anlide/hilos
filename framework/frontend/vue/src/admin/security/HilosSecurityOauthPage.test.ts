// The return-address edit modal on the shared row-edit helper (HIL-1134): it
// holds the address row in focus, reloads a pristine modal when the address
// changes elsewhere and says so, shows the conflict chrome without Merge on a
// changed one and answers Keep mine / Take theirs, locks save as "Deleted" when
// the row goes, and asks before discarding a changed draft. The ↺ resets the
// address only through a confirm dialog (HIL-1147).
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosSecurityOauthContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import HilosSecurityOauthPage from './HilosSecurityOauthPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const REDIRECT_TABLE = 'hilosSecurityOauthRedirect'
const PROVIDERS_TABLE = 'hilosSecurityOauthProviders'
const ROW_KEY = 'oauth_redirect_uri'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_OAUTH,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** The return-address row's slot, with the given value and, unless named, its usual source. */
function redirectSlot(
  value: unknown,
  source?: string,
): Record<string, unknown> {
  return {
    value,
    source: source ?? (value === '' ? 'default' : 'db'),
    setState: value !== '',
  }
}

function seededContext(initial: unknown): {
  context: HilosSecurityOauthContext
  pushUpdate: (value: unknown, source?: string) => void
  pushRemove: () => void
  answer: (outcome: 'success' | 'fail', errorCode?: string) => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let rows = [redirectSlot(initial)]
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_OAUTH)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    if (tableKey === PROVIDERS_TABLE) {
      const data = {
        page: HilosPages.SECURITY_OAUTH,
        tableKey,
        rows: [
          {
            rowKey: 'oauth:github',
            slots: {
              provider: {
                providerKey: 'oauth:github',
                label: 'GitHub',
                builtIn: true,
                configured: true,
                missingFields: 0,
                secretSet: true,
                clientIdSource: 'db',
              },
            },
          },
        ],
        totalCount: 1,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 10,
      }
      for (const listener of windowListeners) {
        listener({ data })
      }

      return
    }
    const served = tableKey === REDIRECT_TABLE ? rows : []
    const data = {
      page: HilosPages.SECURITY_OAUTH,
      tableKey,
      rows: served.map((redirect) => ({
        rowKey: ROW_KEY,
        slots: { redirect },
      })),
      totalCount: served.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const pushDelta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: {
          page: HilosPages.SECURITY_OAUTH,
          tableKey: REDIRECT_TABLE,
          ...data,
        },
      })
    }
  }

  const connection = {
    registerTableWindow(tableKey: string): void {
      serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection:
        connection as unknown as HilosSecurityOauthContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(value: unknown, source?: string): void {
      rows = [redirectSlot(value, source)]
      pushDelta({
        kind: 'row_updated',
        rowKey: ROW_KEY,
        row: { rowKey: ROW_KEY, slots: { redirect: rows[0] } },
      })
    },
    pushRemove(): void {
      rows = []
      pushDelta({ kind: 'row_removed', rowKey: ROW_KEY, reason: 'deleted' })
    },
    // Answer the last action sent, the way the server replies to it.
    answer(outcome: 'success' | 'fail', errorCode?: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The address refused the reset.',
          ...(errorCode !== undefined ? { errorCode } : {}),
        })
      }
    },
    sent,
    focus,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
})

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement {
  return modalEl('hilos-oauth-redirect-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-oauth-redirect-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return modalEl('hilos-oauth-redirect-edit-notice')
}

async function typeDraft(text: string): Promise<void> {
  const input = valueInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/** Mount the page and open the modal on the seeded address. */
async function openModal(context: HilosSecurityOauthContext): Promise<void> {
  const wrapper = mount(HilosSecurityOauthPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()
  modalEl('hilos-oauth-redirect-edit')?.click()
  await nextTick()
}

/** Let a live frame reach the modal. */
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
}

describe('HilosSecurityOauthPage return-address modal', () => {
  it('opens on the live value with save locked and the message line empty', async () => {
    const { context } = seededContext('https://a.example/cb')
    await openModal(context)

    expect(valueInput().value).toBe('https://a.example/cb')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(modalEl('hilos-oauth-redirect-edit-notice-idle')).not.toBeNull()
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    await settle()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the address changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    await openModal(context)

    pushUpdate('https://b.example/cb')
    await settle()

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')

    pushUpdate('')
    await settle()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "—"')
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_redirect_set')
    expect(sent[0]?.payload).toMatchObject({ value: 'https://mine.example/cb' })
  })

  it('Take theirs puts the live value in, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    await settle()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', async () => {
    const { context, pushRemove } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushRemove()
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(valueInput().value).toBe('https://mine.example/cb')
  })

  it('takes the row into focus on open, lets it go on close, and asks before discarding a changed draft', async () => {
    const { context, focus } = seededContext('https://a.example/cb')
    await openModal(context)
    expect(focus).toEqual([ROW_KEY])
    await typeDraft('https://mine.example/cb')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('closes a pristine edit on Save without a request', async () => {
    const { context, sent } = seededContext('https://a.example/cb')
    await openModal(context)

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosSecurityOauthPage return-address reset dialog', () => {
  function resetButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-redirect-reset') as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-redirect-reset-confirm') as HTMLButtonElement
  }

  async function mountPage(context: HilosSecurityOauthContext): Promise<void> {
    const wrapper = mount(HilosSecurityOauthPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()
  }

  async function openReset(context: HilosSecurityOauthContext): Promise<void> {
    await mountPage(context)
    resetButton().click()
    await nextTick()
  }

  it('opens on ↺ with the row in focus and sends nothing', async () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    await openReset(context)

    expect(modalEl('modal')?.textContent).toContain('Reset · Return address')
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('shows the address now and where it goes back to', async () => {
    const { context } = seededContext('https://a.example/cb')
    await openReset(context)

    expect(modalEl('hilos-oauth-redirect-reset-now')?.textContent?.trim()).toBe(
      'https://a.example/cb',
    )
    expect(
      modalEl('hilos-oauth-redirect-reset-default')?.textContent?.trim(),
    ).toBe('the env value — empty when env has none')
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext(
      'https://a.example/cb',
    )
    await openReset(context)

    confirmButton().click()
    await nextTick()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'security_oauth_redirect_reset', payload: {} },
    ])

    answer('success')
    await settle()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', async () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    await openReset(context)

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('https://a.example/cb')
    await openReset(context)

    confirmButton().click()
    await nextTick()
    answer('fail')
    await settle()
    await nextTick()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      'The address refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', async () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    await openReset(context)
    expect(modalEl('hilos-oauth-redirect-reset-gone')).toBeNull()

    pushUpdate('https://env.example/cb', 'env')
    await settle()

    expect(
      modalEl('hilos-oauth-redirect-reset-gone')?.textContent?.trim(),
    ).toBe('Already reset elsewhere.')
    expect(modalEl('hilos-oauth-redirect-reset-now')?.textContent?.trim()).toBe(
      'https://env.example/cb',
    )
    expect(confirmButton().disabled).toBe(true)
  })

  it('locks ↺ while the address is not set in admin', async () => {
    const { context } = seededContext('')
    await mountPage(context)

    expect(resetButton().disabled).toBe(true)
  })
})

describe('HilosSecurityOauthPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession(scopes: ScopeManager) {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  function resetButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-redirect-reset') as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-redirect-reset-confirm') as HTMLButtonElement
  }

  it('a viewer opens return address edit, sees the hidden mark instead of an input, and closes via Cancel', async () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    await openModal(context)

    expect(modalEl('hilos-oauth-redirect-input')).toBeNull()
    expect(modalEl('hilos-hidden')).not.toBeNull()
    expect(saveButton().disabled).toBe(true)

    saveButton().click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
  })

  it('a viewer opens return address reset, finds confirm disabled, and closes via Cancel', async () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    const wrapper = mount(HilosSecurityOauthPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(
      document.querySelector(
        'code[data-id="hilos-oauth-redirect-value"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    expect(resetButton().disabled).toBe(false)
    resetButton().click()
    await nextTick()

    expect(
      modalEl('hilos-oauth-redirect-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(confirmButton().disabled).toBe(true)
    expect(confirmButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmButton().click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
  })

  it('a viewer sees the provider configure link in place', async () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake(null, true)
    const wrapper = mount(HilosSecurityOauthPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    const configureLink = document.querySelector(
      '[data-id="hilos-oauth-provider-open-oauth:github"]',
    )
    expect(configureLink).not.toBeNull()
  })

  it('an admin on a node in the mode has Save and Reset active', async () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    await openModal(context)

    await typeDraft('https://mine.example/cb')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()

    resetButton().click()
    await nextTick()

    expect(confirmButton().disabled).toBe(false)
    expect(confirmButton().getAttribute('aria-describedby')).toBeNull()
  })
})
