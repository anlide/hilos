// The Angular peer of vue/src/admin/security/HilosSecurityOauthProviderPage.test.ts:
// the provider-field edit modal on the shared row-edit helper (HIL-1134): a
// field's modal holds its row in focus, reloads a pristine edit when the value
// changes elsewhere, shows the conflict chrome without Merge on a changed one and
// answers Keep mine / Take theirs, and locks save as "Deleted" when the row goes.
// The secret's modal opens empty, keeps save locked until something is typed,
// and only ever says the row is gone. The ↺ resets a field only through a
// confirm dialog (HIL-1147).
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosSecurityOauthContext,
  PageRouteMatch,
} from '@hilos/core'

import { describe, expect, it } from 'vitest'

import { HilosSecurityOauthProviderPage } from '../src/admin/security/HilosSecurityOauthProviderPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const PROVIDER = 'oauth:github'
const FIELDS_TABLE = 'hilosSecurityOauthProviderFields'
const CLIENT_ID_KEY = `${PROVIDER}/client_id`
const SECRET_KEY = `${PROVIDER}/client_secret`
const SCOPE_KEY = `${PROVIDER}/scope`

/** The label each field row carries. */
const FIELD_LABEL: Record<string, string> = {
  client_id: 'Client ID',
  client_secret: 'Client secret',
  scope: 'Scope',
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_OAUTH_PROVIDER,
      params: { providerId: PROVIDER },
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

/** A field row's slot: the client id or the scope with the given value, or the secret. */
function fieldSlot(
  field: 'client_id' | 'client_secret' | 'scope',
  value: string | null = null,
  source = 'db',
): Record<string, unknown> {
  const secret = field === 'client_secret'

  return {
    providerKey: PROVIDER,
    field,
    label: FIELD_LABEL[field],
    type: 'string',
    secret,
    value: secret ? null : value,
    source,
    setState: true,
  }
}

/** The provider's own row of the providers table. */
const PROVIDER_SLOT: Record<string, unknown> = {
  providerKey: PROVIDER,
  label: 'GitHub',
  builtIn: true,
  configured: true,
  missingFields: 0,
  secretSet: true,
  clientIdSource: 'db',
}

function seededContext(clientId: string | null): {
  context: HilosSecurityOauthContext
  pushUpdate: (value: string | null, source?: string) => void
  pushRemove: (rowKey: string) => void
  answer: (outcome: 'success' | 'fail') => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let fields = new Map<string, Record<string, unknown>>([
    [CLIENT_ID_KEY, fieldSlot('client_id', clientId)],
    [SECRET_KEY, fieldSlot('client_secret')],
    [SCOPE_KEY, fieldSlot('scope', 'read:user')],
  ])
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === FIELDS_TABLE
        ? [...fields].map(([rowKey, field]) => ({ rowKey, slots: { field } }))
        : [{ rowKey: PROVIDER, slots: { provider: PROVIDER_SLOT } }]
    const data = {
      page: HilosPages.SECURITY_OAUTH_PROVIDER,
      tableKey,
      rows,
      totalCount: rows.length,
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
          page: HilosPages.SECURITY_OAUTH_PROVIDER,
          tableKey: FIELDS_TABLE,
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
    pushUpdate(value: string | null, source = 'db'): void {
      const field = fieldSlot('client_id', value, source)
      fields = new Map(fields).set(CLIENT_ID_KEY, field)
      pushDelta({
        kind: 'row_updated',
        rowKey: CLIENT_ID_KEY,
        row: { rowKey: CLIENT_ID_KEY, slots: { field } },
      })
    },
    pushRemove(rowKey: string): void {
      fields = new Map(fields)
      fields.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
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
          reason: 'The provider refused the reset.',
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
  return el(fixture, 'hilos-oauth-field-input') as HTMLInputElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-oauth-field-save') as HTMLButtonElement
}

function notice(fixture: ComponentFixture<unknown>): HTMLElement | null {
  return el(fixture, 'hilos-oauth-field-edit-notice')
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = valueInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

/**
 * Mount the page and open the modal of one field. The row's controls stand in
 * the document twice — the table and the narrow-screen card — so the button is
 * looked up through the table.
 */
function openModal(
  context: HilosSecurityOauthContext,
  field: 'client_id' | 'client_secret',
): ComponentFixture<HilosSecurityOauthProviderPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSecurityOauthProviderPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  ;(fixture.nativeElement as HTMLElement)
    .querySelector<HTMLElement>(
      `table [data-id="hilos-oauth-field-edit-${field}"]`,
    )
    ?.click()
  fixture.detectChanges()

  return fixture
}

describe('HilosSecurityOauthProviderPage field modal', () => {
  it('opens on the live value with save locked and the message line empty', () => {
    const { context, focus } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')

    expect(valueInput(fixture).value).toBe('Iv1.a')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(notice(fixture)).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY])
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')
    typeDraft(fixture, 'Iv1.mine')
    pushUpdate('Iv1.b')
    fixture.detectChanges()

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')

    pushUpdate('Iv1.b')
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('Iv1.b')
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')
    typeDraft(fixture, 'Iv1.mine')

    pushUpdate(null)
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(notice(fixture)?.textContent).toContain('Changed elsewhere to "—"')
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_provider_set')
    expect(sent[0]?.payload).toMatchObject({
      providerKey: PROVIDER,
      field: 'client_id',
      value: 'Iv1.mine',
    })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')
    typeDraft(fixture, 'Iv1.mine')
    pushUpdate('Iv1.b')
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('Iv1.b')
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', () => {
    const { context, pushRemove, focus } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_id')
    typeDraft(fixture, 'Iv1.mine')
    pushRemove(CLIENT_ID_KEY)
    fixture.detectChanges()

    expect(notice(fixture)?.textContent).toContain('Deleted elsewhere')
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(valueInput(fixture).value).toBe('Iv1.mine')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })
})

describe('HilosSecurityOauthProviderPage secret modal', () => {
  it('opens empty with save locked, and a typed secret opens save and sends', () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_secret')

    expect(focus).toEqual([SECRET_KEY])
    expect(valueInput(fixture).value).toBe('')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'conflict-badge')).toBeNull()

    typeDraft(fixture, 's3cret')
    expect(saveButton(fixture).disabled).toBe(false)
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toMatchObject({
      field: 'client_secret',
      value: 's3cret',
    })
  })

  it('sends nothing on Enter while the input is empty', () => {
    const { context, sent } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_secret')

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
  })

  it('says only that the row is gone, and locks save as Deleted', () => {
    const { context, pushRemove } = seededContext('Iv1.a')
    const fixture = openModal(context, 'client_secret')
    typeDraft(fixture, 's3cret')
    pushRemove(SECRET_KEY)
    fixture.detectChanges()

    expect(notice(fixture)?.textContent).toContain('Deleted elsewhere')
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'conflict-badge')).toBeNull()
  })
})

describe('HilosSecurityOauthProviderPage reset dialog', () => {
  function mountPage(
    context: HilosSecurityOauthContext,
  ): ComponentFixture<HilosSecurityOauthProviderPage> {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosSecurityOauthProviderPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return fixture
  }

  function resetButton(
    fixture: ComponentFixture<unknown>,
    field: string,
  ): HTMLButtonElement {
    return (fixture.nativeElement as HTMLElement).querySelector(
      `table [data-id="hilos-oauth-field-reset-${field}"]`,
    ) as HTMLButtonElement
  }

  function confirmButton(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement {
    return el(fixture, 'hilos-oauth-field-reset-confirm') as HTMLButtonElement
  }

  function openReset(
    context: HilosSecurityOauthContext,
    field: 'client_id' | 'client_secret' | 'scope',
  ): ComponentFixture<HilosSecurityOauthProviderPage> {
    const fixture = mountPage(context)
    resetButton(fixture, field).click()
    fixture.detectChanges()

    return fixture
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
    const { context, sent, focus } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')

    expect(el(fixture, 'modal')?.textContent).toContain('Reset · Client ID')
    expect(focus).toEqual([CLIENT_ID_KEY])
    expect(sent).toEqual([])
  })

  it('shows the value now, where it goes back to, and what it costs sign-in', () => {
    const { context } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')

    expect(
      el(fixture, 'hilos-oauth-field-reset-now')?.textContent?.trim(),
    ).toBe('Iv1.a')
    expect(
      el(fixture, 'hilos-oauth-field-reset-default')?.textContent?.trim(),
    ).toBe('the env value, or the default when env has none')
    expect(
      el(fixture, 'hilos-oauth-field-reset-signin')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('If env has none, sign-in with GitHub stops being offered.')
  })

  it('shows the secret as set, never its value', () => {
    const { context } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_secret')

    expect(
      el(fixture, 'hilos-oauth-field-reset-now')?.textContent?.trim(),
    ).toBe('Set')
    expect(el(fixture, 'hilos-oauth-field-reset-signin')).not.toBeNull()
  })

  it('says nothing of sign-in when resetting the scope', () => {
    const { context } = seededContext('Iv1.a')
    const fixture = openReset(context, 'scope')

    expect(el(fixture, 'modal')?.textContent).toContain('Reset · Scope')
    expect(el(fixture, 'hilos-oauth-field-reset-signin')).toBeNull()
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')

    confirmButton(fixture).click()
    fixture.detectChanges()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'security_oauth_provider_reset',
        payload: { providerKey: PROVIDER, field: 'client_id' },
      },
    ])

    await reply(fixture, answer, 'success')
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')

    cancelButton(fixture.nativeElement as HTMLElement).click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')

    confirmButton(fixture).click()
    fixture.detectChanges()
    await reply(fixture, answer, 'fail')

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'hilos-action-error')?.textContent).toContain(
      'The provider refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    const fixture = openReset(context, 'client_id')
    expect(el(fixture, 'hilos-oauth-field-reset-gone')).toBeNull()

    pushUpdate('Iv1.env', 'env')
    fixture.detectChanges()

    expect(
      el(fixture, 'hilos-oauth-field-reset-gone')?.textContent?.trim(),
    ).toBe('Already reset elsewhere.')
    expect(
      el(fixture, 'hilos-oauth-field-reset-now')?.textContent?.trim(),
    ).toBe('Iv1.env')
    expect(confirmButton(fixture).disabled).toBe(true)
  })

  it('locks ↺ while the value is not set in admin', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    const fixture = mountPage(context)
    expect(resetButton(fixture, 'client_id').disabled).toBe(false)

    pushUpdate('Iv1.env', 'env')
    fixture.detectChanges()

    expect(resetButton(fixture, 'client_id').disabled).toBe(true)
  })
})
