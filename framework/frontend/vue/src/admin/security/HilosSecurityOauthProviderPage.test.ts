// The provider-field edit modal on the shared row-edit helper (HIL-1134): a
// field's modal holds its row in focus, reloads a pristine edit when the value
// changes elsewhere, shows the conflict chrome without Merge on a changed one and
// answers Keep mine / Take theirs, and locks save as "Deleted" when the row goes.
// The secret's modal opens empty, keeps save locked until something is typed,
// and only ever says the row is gone. The ↺ resets a field only through a
// confirm dialog (HIL-1147).
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_COPY,
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

import HilosSecurityOauthProviderPage from './HilosSecurityOauthProviderPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
  answer: (outcome: 'success' | 'fail', errorCode?: string) => void
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
    answer(outcome: 'success' | 'fail', errorCode?: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The provider refused the reset.',
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
  return modalEl('hilos-oauth-field-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-oauth-field-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return modalEl('hilos-oauth-field-edit-notice')
}

async function typeDraft(text: string): Promise<void> {
  const input = valueInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/**
 * Mount the page and open the modal of one field. The row's controls stand in
 * the document twice — the table and the narrow-screen card — so the button is
 * looked up through the table.
 */
async function openModal(
  context: HilosSecurityOauthContext,
  field: 'client_id' | 'client_secret',
): Promise<void> {
  const wrapper = mount(HilosSecurityOauthProviderPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()
  ;(
    document.querySelector(
      `table [data-id="hilos-oauth-field-edit-${field}"]`,
    ) as HTMLElement
  ).click()
  await nextTick()
}

/** Let a live frame reach the modal. */
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
}

describe('HilosSecurityOauthProviderPage field modal', () => {
  it('opens on the live value with save locked and the message line empty', async () => {
    const { context, focus } = seededContext('Iv1.a')
    await openModal(context, 'client_id')

    expect(valueInput().value).toBe('Iv1.a')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY])
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')
    pushUpdate('Iv1.b')
    await settle()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    await openModal(context, 'client_id')

    pushUpdate('Iv1.b')
    await settle()

    expect(valueInput().value).toBe('Iv1.b')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')

    pushUpdate(null)
    await settle()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "—"')
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_provider_set')
    expect(sent[0]?.payload).toMatchObject({
      providerKey: PROVIDER,
      field: 'client_id',
      value: 'Iv1.mine',
    })
  })

  it('Take theirs puts the live value in, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')
    pushUpdate('Iv1.b')
    await settle()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('Iv1.b')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', async () => {
    const { context, pushRemove, focus } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')
    pushRemove(CLIENT_ID_KEY)
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('Iv1.mine')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })
})

describe('HilosSecurityOauthProviderPage secret modal', () => {
  it('opens empty with save locked, and a typed secret opens save and sends', async () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    await openModal(context, 'client_secret')

    expect(focus).toEqual([SECRET_KEY])
    expect(valueInput().value).toBe('')
    expect(saveButton().disabled).toBe(true)
    expect(modalEl('conflict-badge')).toBeNull()

    await typeDraft('s3cret')
    expect(saveButton().disabled).toBe(false)
    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toMatchObject({
      field: 'client_secret',
      value: 's3cret',
    })
  })

  it('sends nothing on Enter while the input is empty', async () => {
    const { context, sent } = seededContext('Iv1.a')
    await openModal(context, 'client_secret')

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
  })

  it('says only that the row is gone, and locks save as Deleted', async () => {
    const { context, pushRemove } = seededContext('Iv1.a')
    await openModal(context, 'client_secret')
    await typeDraft('s3cret')
    pushRemove(SECRET_KEY)
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(modalEl('conflict-badge')).toBeNull()
  })
})

describe('HilosSecurityOauthProviderPage reset dialog', () => {
  function resetButton(field: string): HTMLButtonElement {
    return document.querySelector(
      `table [data-id="hilos-oauth-field-reset-${field}"]`,
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-field-reset-confirm') as HTMLButtonElement
  }

  async function openReset(
    context: HilosSecurityOauthContext,
    field: 'client_id' | 'client_secret' | 'scope',
  ): Promise<void> {
    const wrapper = mount(HilosSecurityOauthProviderPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()
    resetButton(field).click()
    await nextTick()
  }

  it('opens on ↺ with the row in focus and sends nothing', async () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    expect(modalEl('modal')?.textContent).toContain('Reset · Client ID')
    expect(focus).toEqual([CLIENT_ID_KEY])
    expect(sent).toEqual([])
  })

  it('shows the value now, where it goes back to, and what it costs sign-in', async () => {
    const { context } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.a',
    )
    expect(
      modalEl('hilos-oauth-field-reset-default')?.textContent?.trim(),
    ).toBe('the env value, or the default when env has none')
    expect(modalEl('hilos-oauth-field-reset-signin')?.textContent?.trim()).toBe(
      'If env has none, sign-in with GitHub stops being offered.',
    )
  })

  it('shows the secret as set, never its value', async () => {
    const { context } = seededContext('Iv1.a')
    await openReset(context, 'client_secret')

    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Set',
    )
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    confirmButton().click()
    await nextTick()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'security_oauth_provider_reset',
        payload: { providerKey: PROVIDER, field: 'client_id' },
      },
    ])

    answer('success')
    await settle()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', async () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    confirmButton().click()
    await nextTick()
    answer('fail')
    await settle()
    await nextTick()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      'The provider refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', async () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    await openReset(context, 'client_id')
    expect(modalEl('hilos-oauth-field-reset-gone')).toBeNull()

    pushUpdate('Iv1.env', 'env')
    await settle()

    expect(modalEl('hilos-oauth-field-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.env',
    )
    expect(confirmButton().disabled).toBe(true)
  })

  it('says nothing of sign-in when resetting the scope', async () => {
    const { context } = seededContext('Iv1.a')
    await openReset(context, 'scope')

    expect(modalEl('modal')?.textContent).toContain('Reset · Scope')
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
  })

  it('locks ↺ while the value is not set in admin', async () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    const wrapper = mount(HilosSecurityOauthProviderPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()
    expect(resetButton('client_id').disabled).toBe(false)

    pushUpdate('Iv1.env', 'env')
    await settle()

    expect(resetButton('client_id').disabled).toBe(true)
  })
})

describe('HilosSecurityOauthProviderPage in the admin view mode', () => {
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

  function resetButton(
    field: 'client_id' | 'client_secret' | 'scope',
  ): HTMLButtonElement {
    return document.querySelector(
      `table [data-id="hilos-oauth-field-reset-${field}"]`,
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-oauth-field-reset-confirm') as HTMLButtonElement
  }

  it('a viewer opens field edit and finds Save disabled with the mode strip reference', async () => {
    const { context, sent, answer } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake(null, true)
    await openModal(context, 'client_id')

    expect(saveButton().disabled).toBe(true)
    expect(saveButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await typeDraft('Iv1.mine')
    expect(saveButton().disabled).toBe(true)

    const form = document.querySelector('[data-id="modal"] form')
    expect(form).not.toBeNull()
    form?.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await nextTick()

    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_provider_set')
    expect(sent[0]?.payload).toEqual({
      providerKey: PROVIDER,
      field: 'client_id',
      value: 'Iv1.mine',
    })

    answer('fail', 'view_mode')
    await settle()
    await nextTick()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      HILOS_VIEW_MODE_COPY.refusal,
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('a viewer opens field reset and finds confirm disabled with the mode strip reference', async () => {
    const { context, sent } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake(null, true)
    const wrapper = mount(HilosSecurityOauthProviderPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(resetButton('client_id').disabled).toBe(false)
    resetButton('client_id').click()
    await nextTick()

    expect(confirmButton().disabled).toBe(true)
    expect(confirmButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmButton().click()
    await nextTick()
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode has Save and Reset active', async () => {
    const { context } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    await openModal(context, 'client_id')

    await typeDraft('Iv1.mine')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()

    resetButton('client_id').click()
    await nextTick()

    expect(confirmButton().disabled).toBe(false)
    expect(confirmButton().getAttribute('aria-describedby')).toBeNull()
  })
})
