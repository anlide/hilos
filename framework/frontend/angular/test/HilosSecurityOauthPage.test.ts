// The Angular peer of vue/src/admin/security/HilosSecurityOauthPage.test.ts:
// the return-address edit modal on the shared row-edit helper (HIL-1134): it
// holds the address row in focus, reloads a pristine modal when the address
// changes elsewhere and says so, shows the conflict chrome without Merge on a
// changed one and answers Keep mine / Take theirs, locks save as "Deleted" when
// the row goes, and asks before discarding a changed draft. The ↺ resets the
// address only through a confirm dialog (HIL-1147). In the admin view mode a
// viewer sees the hidden mark in place of the address and of the modal's
// input, with Save and Reset disabled; an admin on the node keeps both.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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

import { afterEach, describe, expect, it } from 'vitest'

import { HilosSecurityOauthPage } from '../src/admin/security/HilosSecurityOauthPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function valueInput(fixture: ComponentFixture<unknown>): HTMLInputElement {
  return el(fixture, 'hilos-oauth-redirect-input') as HTMLInputElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-oauth-redirect-save') as HTMLButtonElement
}

function notice(fixture: ComponentFixture<unknown>): HTMLElement | null {
  return el(fixture, 'hilos-oauth-redirect-edit-notice')
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = valueInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

/** Mount the page and open the modal on the seeded address. */
function openModal(
  context: HilosSecurityOauthContext,
): ComponentFixture<HilosSecurityOauthPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSecurityOauthPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  el(fixture, 'hilos-oauth-redirect-edit')?.click()
  fixture.detectChanges()

  return fixture
}

describe('HilosSecurityOauthPage return-address modal', () => {
  it('opens on the live value with save locked and the message line empty', () => {
    const { context } = seededContext('https://a.example/cb')
    const fixture = openModal(context)

    expect(valueInput(fixture).value).toBe('https://a.example/cb')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(notice(fixture)).toBeNull()
    expect(el(fixture, 'hilos-oauth-redirect-edit-notice-idle')).not.toBeNull()
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    const fixture = openModal(context)
    typeDraft(fixture, 'https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    fixture.detectChanges()

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the address changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    const fixture = openModal(context)

    pushUpdate('https://b.example/cb')
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('https://b.example/cb')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    const fixture = openModal(context)
    typeDraft(fixture, 'https://mine.example/cb')

    pushUpdate('')
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(notice(fixture)?.textContent).toContain('Changed elsewhere to "—"')
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_redirect_set')
    expect(sent[0]?.payload).toMatchObject({ value: 'https://mine.example/cb' })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    const fixture = openModal(context)
    typeDraft(fixture, 'https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('https://b.example/cb')
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, pushRemove } = seededContext('https://a.example/cb')
    const fixture = openModal(context)
    typeDraft(fixture, 'https://mine.example/cb')
    pushRemove()
    fixture.detectChanges()

    expect(notice(fixture)?.textContent).toContain('Deleted elsewhere')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(valueInput(fixture).value).toBe('https://mine.example/cb')
  })

  it('takes the row into focus on open, lets it go on close, and asks before discarding a changed draft', () => {
    const { context, focus } = seededContext('https://a.example/cb')
    const fixture = openModal(context)
    expect(focus).toEqual([ROW_KEY])
    typeDraft(fixture, 'https://mine.example/cb')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()

    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('closes a pristine edit on Save without a request', () => {
    const { context, sent } = seededContext('https://a.example/cb')
    const fixture = openModal(context)

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'modal')).toBeNull()
  })
})

describe('HilosSecurityOauthPage return-address reset dialog', () => {
  function mountPage(
    context: HilosSecurityOauthContext,
  ): ComponentFixture<HilosSecurityOauthPage> {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosSecurityOauthPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return fixture
  }

  function openReset(
    context: HilosSecurityOauthContext,
  ): ComponentFixture<HilosSecurityOauthPage> {
    const fixture = mountPage(context)
    el(fixture, 'hilos-oauth-redirect-reset')?.click()
    fixture.detectChanges()

    return fixture
  }

  function confirmButton(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement {
    return el(
      fixture,
      'hilos-oauth-redirect-reset-confirm',
    ) as HTMLButtonElement
  }

  function cancelButton(root: HTMLElement): HTMLButtonElement {
    return Array.from(
      root.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  /** Answer the action and let the page take the reply. */
  async function reply(
    fixture: ComponentFixture<unknown>,
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    answer(outcome)
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()
  }

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    const fixture = openReset(context)

    expect(el(fixture, 'modal')?.textContent).toContain(
      'Reset · Return address',
    )
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('shows the address now and where it goes back to', () => {
    const { context } = seededContext('https://a.example/cb')
    const fixture = openReset(context)

    expect(
      el(fixture, 'hilos-oauth-redirect-reset-now')?.textContent?.trim(),
    ).toBe('https://a.example/cb')
    expect(
      el(fixture, 'hilos-oauth-redirect-reset-default')?.textContent?.trim(),
    ).toBe('the env value — empty when env has none')
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext(
      'https://a.example/cb',
    )
    const fixture = openReset(context)

    confirmButton(fixture).click()
    fixture.detectChanges()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'security_oauth_redirect_reset', payload: {} },
    ])

    await reply(fixture, answer, 'success')
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext('https://a.example/cb')
    const fixture = openReset(context)

    cancelButton(fixture.nativeElement as HTMLElement).click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('https://a.example/cb')
    const fixture = openReset(context)

    confirmButton(fixture).click()
    fixture.detectChanges()
    await reply(fixture, answer, 'fail')

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'hilos-action-error')?.textContent).toContain(
      'The address refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    const fixture = openReset(context)
    expect(el(fixture, 'hilos-oauth-redirect-reset-gone')).toBeNull()

    pushUpdate('https://env.example/cb', 'env')
    fixture.detectChanges()

    expect(
      el(fixture, 'hilos-oauth-redirect-reset-gone')?.textContent?.trim(),
    ).toBe('Already reset elsewhere.')
    expect(
      el(fixture, 'hilos-oauth-redirect-reset-now')?.textContent?.trim(),
    ).toBe('https://env.example/cb')
    expect(confirmButton(fixture).disabled).toBe(true)
  })

  it('locks ↺ while the address is not set in admin', () => {
    const { context } = seededContext('')
    const fixture = mountPage(context)

    expect(
      (el(fixture, 'hilos-oauth-redirect-reset') as HTMLButtonElement).disabled,
    ).toBe(true)
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

  function mountPage(
    context: HilosSecurityOauthContext,
  ): ComponentFixture<HilosSecurityOauthPage> {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosSecurityOauthPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return fixture
  }

  function resetButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
    return el(fixture, 'hilos-oauth-redirect-reset') as HTMLButtonElement
  }

  function confirmButton(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement {
    return el(
      fixture,
      'hilos-oauth-redirect-reset-confirm',
    ) as HTMLButtonElement
  }

  function cancelButton(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement | undefined {
    return Array.from(
      (
        fixture.nativeElement as HTMLElement
      ).querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
  }

  it('a viewer opens return address edit, sees the hidden mark instead of an input, and closes via Cancel', () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    const fixture = openModal(context)

    expect(el(fixture, 'hilos-oauth-redirect-input')).toBeNull()
    expect(
      el(fixture, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = cancelButton(fixture)
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
  })

  it('a viewer opens return address reset, finds confirm disabled, and closes via Cancel', () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    const fixture = mountPage(context)

    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        'code[data-id="hilos-oauth-redirect-value"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    expect(resetButton(fixture).disabled).toBe(false)
    resetButton(fixture).click()
    fixture.detectChanges()

    expect(
      el(fixture, 'hilos-oauth-redirect-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(confirmButton(fixture).disabled).toBe(true)
    expect(confirmButton(fixture).getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = cancelButton(fixture)
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
  })

  it('a viewer sees the provider configure link in place', () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake(null, true)
    const fixture = mountPage(context)

    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="hilos-oauth-provider-open-oauth:github"]',
      ),
    ).not.toBeNull()
  })

  it('an admin on a node in the mode has Save and Reset active', () => {
    const { context } = seededContext('https://a.example/cb')
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    const fixture = openModal(context)

    typeDraft(fixture, 'https://mine.example/cb')
    expect(saveButton(fixture).disabled).toBe(false)
    expect(saveButton(fixture).getAttribute('aria-describedby')).toBeNull()

    cancelButton(fixture)?.click()
    fixture.detectChanges()

    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()

    resetButton(fixture).click()
    fixture.detectChanges()

    expect(confirmButton(fixture).disabled).toBe(false)
    expect(confirmButton(fixture).getAttribute('aria-describedby')).toBeNull()
  })
})
