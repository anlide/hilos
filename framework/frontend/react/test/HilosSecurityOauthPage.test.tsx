// The React peer of vue/src/admin/security/HilosSecurityOauthPage.test.ts:
// the return-address edit modal on the shared row-edit helper (HIL-1134): it
// holds the address row in focus, reloads a pristine modal when the address
// changes elsewhere and says so, shows the conflict chrome without Merge on a
// changed one and answers Keep mine / Take theirs, locks save as "Deleted" when
// the row goes, and asks before discarding a changed draft. The ↺ resets the
// address only through a confirm dialog (HIL-1147).
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosSecurityOauthPage } from '../src/admin/security/HilosSecurityOauthPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
  pushUpdate: (value: string, source?: string) => void
  pushRemove: () => void
  answer: (outcome: 'success' | 'fail') => void
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
  const source = {
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (event: string, listener: (signal: never) => void) => {
      if (event === 'state') {
        drops.push(() => (listener as (state: string) => void)('disconnected'))

        return () => {}
      }
      const listeners = replyListeners.get(event) ?? new Set()
      const reply = listener as (signal: Record<string, unknown>) => void
      listeners.add(reply)
      replyListeners.set(event, listeners)

      return () => listeners.delete(reply)
    },
  }
  const actions = new ActionLifecycle(
    source as unknown as ConstructorParameters<typeof ActionLifecycle>[0],
  )

  return {
    context: {
      connection:
        connection as unknown as HilosSecurityOauthContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(value: string, source?: string): void {
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
    answer(outcome: 'success' | 'fail'): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The address refused the reset.',
        })
      }
    },
    sent,
    focus,
  }
}

// Drops every connection a case left an action in flight on, so the lifecycle
// fails the action and stops its deferred-loading timer before the page goes.
const drops: Array<() => void> = []

afterEach(() => {
  act(() => {
    for (const drop of drops.splice(0)) {
      drop()
    }
  })
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement {
  return byId('hilos-oauth-redirect-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-oauth-redirect-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return byId('hilos-oauth-redirect-edit-notice')
}

function typeDraft(text: string): void {
  fireEvent.change(valueInput(), { target: { value: text } })
}

/** Mount the page and open the modal on the seeded address. */
function openModal(context: HilosSecurityOauthContext): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurityOauthPage context={context} />
    </HilosRouterContext.Provider>,
  )
  fireEvent.click(byId('hilos-oauth-redirect-edit') as Element)
}

describe('HilosSecurityOauthPage return-address modal', () => {
  it('opens on the live value with save locked and the message line empty', () => {
    const { context } = seededContext('https://a.example/cb')
    openModal(context)

    expect(valueInput().value).toBe('https://a.example/cb')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(byId('hilos-oauth-redirect-edit-notice-idle')).not.toBeNull()
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    openModal(context)
    typeDraft('https://mine.example/cb')
    act(() => {
      pushUpdate('https://b.example/cb')
    })

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(byId('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the address changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    openModal(context)

    act(() => {
      pushUpdate('https://b.example/cb')
    })

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(byId('conflict-badge')).toBeNull()
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    openModal(context)
    typeDraft('https://mine.example/cb')

    act(() => {
      pushUpdate('')
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "—"')
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(byId('conflict-accept-mine') as Element)
    expect(byId('conflict-badge')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    fireEvent.click(saveButton())
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_redirect_set')
    expect(sent[0]?.payload).toMatchObject({ value: 'https://mine.example/cb' })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    openModal(context)
    typeDraft('https://mine.example/cb')
    act(() => {
      pushUpdate('https://b.example/cb')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, pushRemove } = seededContext('https://a.example/cb')
    openModal(context)
    typeDraft('https://mine.example/cb')
    act(() => {
      pushRemove()
    })

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(valueInput().value).toBe('https://mine.example/cb')
  })

  it('takes the row into focus on open, lets it go on close, and asks before discarding a changed draft', () => {
    const { context, focus } = seededContext('https://a.example/cb')
    openModal(context)
    expect(focus).toEqual([ROW_KEY])
    typeDraft('https://mine.example/cb')

    fireEvent.click(byId('modal-close') as Element)
    expect(byId('modal-confirm-discard')).not.toBeNull()

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('closes a pristine edit on Save without a request', () => {
    const { context, sent } = seededContext('https://a.example/cb')
    openModal(context)

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosSecurityOauthPage return-address reset dialog', () => {
  function renderPage(context: HilosSecurityOauthContext): void {
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurityOauthPage context={context} />
      </HilosRouterContext.Provider>,
    )
  }

  function resetButton(): HTMLButtonElement {
    return byId('hilos-oauth-redirect-reset') as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return byId('hilos-oauth-redirect-reset-confirm') as HTMLButtonElement
  }

  function cancelButton(): HTMLButtonElement {
    return Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  function openReset(context: HilosSecurityOauthContext): void {
    renderPage(context)
    fireEvent.click(resetButton())
  }

  async function reply(
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    await act(async () => {
      answer(outcome)
      await Promise.resolve()
    })
  }

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    openReset(context)

    expect(byId('modal')?.textContent).toContain('Reset · Return address')
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('shows the address now and where it goes back to', () => {
    const { context } = seededContext('https://a.example/cb')
    openReset(context)

    expect(byId('hilos-oauth-redirect-reset-now')?.textContent?.trim()).toBe(
      'https://a.example/cb',
    )
    expect(
      byId('hilos-oauth-redirect-reset-default')?.textContent?.trim(),
    ).toBe('the env value — empty when env has none')
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext(
      'https://a.example/cb',
    )
    openReset(context)

    fireEvent.click(confirmButton())
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'security_oauth_redirect_reset', payload: {} },
    ])

    await reply(answer, 'success')
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    openReset(context)

    fireEvent.click(cancelButton())

    expect(byId('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('https://a.example/cb')
    openReset(context)

    fireEvent.click(confirmButton())
    await reply(answer, 'fail')

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The address refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    openReset(context)
    expect(byId('hilos-oauth-redirect-reset-gone')).toBeNull()

    act(() => {
      pushUpdate('https://env.example/cb', 'env')
    })

    expect(byId('hilos-oauth-redirect-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(byId('hilos-oauth-redirect-reset-now')?.textContent?.trim()).toBe(
      'https://env.example/cb',
    )
    expect(confirmButton().disabled).toBe(true)
  })

  it('locks ↺ while the address is not set in admin', () => {
    const { context } = seededContext('')
    renderPage(context)

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
    return byId('hilos-oauth-redirect-reset') as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return byId('hilos-oauth-redirect-reset-confirm') as HTMLButtonElement
  }

  it('a viewer opens return address edit, sees the hidden mark instead of an input, and closes via Cancel', () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    openModal(context)

    expect(byId('hilos-oauth-redirect-input')).toBeNull()
    expect(byId('hilos-hidden')).not.toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(saveButton())
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    fireEvent.click(cancel as HTMLElement)

    expect(byId('modal')).toBeNull()
  })

  it('a viewer opens return address reset, finds confirm disabled, and closes via Cancel', () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurityOauthPage context={context} />
      </HilosRouterContext.Provider>,
    )

    expect(
      document.querySelector(
        'code[data-id="hilos-oauth-redirect-value"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    expect(resetButton().disabled).toBe(false)
    fireEvent.click(resetButton())

    expect(
      byId('hilos-oauth-redirect-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(confirmButton().disabled).toBe(true)
    expect(confirmButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirmButton())
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    fireEvent.click(cancel as HTMLElement)

    expect(byId('modal')).toBeNull()
  })

  it('a viewer sees the provider configure link in place', () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake(null, true)
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurityOauthPage context={context} />
      </HilosRouterContext.Provider>,
    )

    expect(
      document.querySelector(
        '[data-id="hilos-oauth-provider-open-oauth:github"]',
      ),
    ).not.toBeNull()
  })

  it('an admin on a node in the mode has Save and Reset active', () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    openModal(context)

    typeDraft('https://mine.example/cb')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as HTMLElement)

    fireEvent.click(byId('modal-confirm-discard') as HTMLElement)

    fireEvent.click(resetButton())

    expect(confirmButton().disabled).toBe(false)
    expect(confirmButton().getAttribute('aria-describedby')).toBeNull()
  })
})
