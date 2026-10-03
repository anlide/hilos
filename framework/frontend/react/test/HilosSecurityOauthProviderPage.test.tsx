import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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
  ActionHandle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  HilosSecurityOauthContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { HilosSecurityOauthProviderPage } from '../src/admin/security/HilosSecurityOauthProviderPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const PROVIDER = 'oauth:github'
const PROVIDERS_TABLE = 'hilosSecurityOauthProviders'
const FIELDS_TABLE = 'hilosSecurityOauthProviderFields'

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
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** One row as a table carries it on the wire: its key and one inline slot. */
interface WireRow {
  rowKey: string
  slot: Record<string, unknown>
}

/**
 * A connection stub that hands the page the two things its tables live on: a
 * window of rows, and a change to one of them. The change matters most here —
 * the page is supposed to redraw from it, and from nothing else.
 */
function makeConnection(): {
  connection: HilosConnection
  pushWindow: (tableKey: string, slotKey: string, rows: WireRow[]) => void
  pushRowUpdated: (tableKey: string, slotKey: string, row: WireRow) => void
} {
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const deltaListeners: ((signal: { data: unknown }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
    sendTableRendered(): void {},
    sendTableRowFocus(): boolean {
      return true
    },
  } as unknown as HilosConnection

  return {
    connection,
    pushWindow(tableKey: string, slotKey: string, rows: WireRow[]): void {
      act(() => {
        for (const listener of windowListeners) {
          listener({
            data: {
              page: HilosPages.SECURITY_OAUTH_PROVIDER,
              tableKey,
              rows: rows.map((row) => ({
                rowKey: row.rowKey,
                slots: { [slotKey]: row.slot },
              })),
              totalCount: rows.length,
            },
          })
        }
      })
    },
    pushRowUpdated(tableKey: string, slotKey: string, row: WireRow): void {
      act(() => {
        for (const listener of deltaListeners) {
          listener({
            data: {
              page: HilosPages.SECURITY_OAUTH_PROVIDER,
              tableKey,
              kind: 'row_updated',
              rowKey: row.rowKey,
              row: { rowKey: row.rowKey, slots: { [slotKey]: row.slot } },
            },
          })
        }
      })
    },
  }
}

/** One dispatched action, held open so the test times the answer the backend gives. */
interface Dispatched {
  action: string
  payload: Record<string, unknown>
  settle: (result: ActionResult) => void
}

/**
 * An action lifecycle that records what was dispatched and hands the answer back
 * to the test: the point is what the page shows between the submit and the echo,
 * so a fake that settled by itself would hide exactly the step under test.
 */
function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      const done = new Promise<ActionResult>((resolve) => {
        settle = resolve
      })
      dispatched.push({ action, payload, settle })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

/** The provider's own row of the providers table, as the backend puts it on the wire. */
function providerRow(): WireRow {
  return {
    rowKey: PROVIDER,
    slot: {
      providerKey: PROVIDER,
      label: 'GitHub',
      builtIn: true,
      configured: false,
      missingFields: 1,
      secretSet: false,
      clientIdSource: 'env',
      authorizeUrl: 'https://github.com/login/oauth/authorize',
      tokenUrl: 'https://github.com/login/oauth/access_token',
      userInfoUrl: 'https://api.github.com/user',
      subjectKey: 'id',
      emailKey: 'email',
      nameKey: 'name',
    },
  }
}

/** The client id row of the fields table. */
function clientIdRow(): WireRow {
  return {
    rowKey: `${PROVIDER}/client_id`,
    slot: {
      providerKey: PROVIDER,
      field: 'client_id',
      label: 'Client ID',
      type: 'string',
      secret: false,
      value: 'Iv1.0123456789',
      source: 'env',
      setState: true,
    },
  }
}

/** The client secret row of the fields table. */
function secretRow(overrides: Record<string, unknown> = {}): WireRow {
  return {
    rowKey: `${PROVIDER}/client_secret`,
    slot: {
      providerKey: PROVIDER,
      field: 'client_secret',
      label: 'Client secret',
      type: 'string',
      secret: true,
      value: null,
      source: 'default',
      setState: false,
      ...overrides,
    },
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)

  return scopes
}

function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle = makeActions().actions,
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurityOauthProviderPage
        context={{ connection, scopes: makeScopes(), actions }}
      />
    </HilosRouterContext.Provider>,
  ).container
}

/**
 * Every place the secret's value cell is drawn: the table on a wide screen and the
 * card on a narrow one both carry it.
 */
function secretCells(container: HTMLElement): HTMLElement[] {
  return Array.from(
    container.querySelectorAll<HTMLElement>(
      '[data-id="hilos-oauth-field-value-client_secret"]',
    ),
  )
}

function secretCellTexts(container: HTMLElement): string[] {
  return secretCells(container).map((cell) => cell.textContent?.trim() ?? '')
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

describe('HilosSecurityOauthProviderPage', () => {
  // The edit dialog is portalled to the document body, so a page left mounted
  // would leave its modal there for the next case to find.
  afterEach(cleanup)

  it('shows a secret in force as Set, and never its value', () => {
    const { connection, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushWindow(PROVIDERS_TABLE, 'provider', [providerRow()])
    // Even a slot that carried a value by mistake must not put it on the screen.
    pushWindow(FIELDS_TABLE, 'field', [
      clientIdRow(),
      secretRow({ value: 'leaked-secret', source: 'db', setState: true }),
    ])

    expect(secretCells(container).length).toBeGreaterThan(0)
    for (const text of secretCellTexts(container)) {
      expect(text).toBe('Set')
    }
    expect(document.body.textContent).not.toContain('leaked-secret')
  })

  it('shows a missing secret as Not set', () => {
    const { connection, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushWindow(PROVIDERS_TABLE, 'provider', [providerRow()])
    pushWindow(FIELDS_TABLE, 'field', [clientIdRow(), secretRow()])

    expect(secretCells(container).length).toBeGreaterThan(0)
    for (const text of secretCellTexts(container)) {
      expect(text).toBe('Not set')
    }
  })

  it('opens the Replace dialog for the secret with an empty input', () => {
    const { connection, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushWindow(PROVIDERS_TABLE, 'provider', [providerRow()])
    pushWindow(FIELDS_TABLE, 'field', [
      clientIdRow(),
      secretRow({ source: 'db', setState: true }),
    ])

    // Editing is modal, not inline: nothing is mounted until Replace, and the
    // modal portals to <body>, so the input is queried on the document.
    expect(
      document.querySelector('[data-id="hilos-oauth-field-input"]'),
    ).toBeNull()
    const replace = container.querySelector(
      '[data-id="hilos-oauth-field-edit-client_secret"]',
    ) as HTMLElement
    expect(replace.getAttribute('aria-label')).toBe('Replace Client secret')
    fireEvent.click(replace)

    expect(
      document.querySelector('[data-id="modal"]')?.getAttribute('aria-label'),
    ).toBe('Replace · Client secret')
    const input = document.querySelector(
      '[data-id="hilos-oauth-field-input"]',
    ) as HTMLInputElement
    expect(input).not.toBeNull()
    expect(input.value).toBe('')
    expect(input.type).toBe('password')
  })

  it('redraws the secret from the table row, not from the submit', async () => {
    const { connection, pushWindow, pushRowUpdated } = makeConnection()
    const { actions, dispatched } = makeActions()
    const container = mountPage(connection, actions)

    pushWindow(PROVIDERS_TABLE, 'provider', [providerRow()])
    pushWindow(FIELDS_TABLE, 'field', [clientIdRow(), secretRow()])
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-oauth-field-edit-client_secret"]',
      ) as HTMLElement,
    )
    fireEvent.change(
      document.querySelector(
        '[data-id="hilos-oauth-field-input"]',
      ) as HTMLInputElement,
      { target: { value: 's3cret' } },
    )
    fireEvent.click(
      document.querySelector(
        '[data-id="hilos-oauth-field-save"]',
      ) as HTMLElement,
    )
    await settled()

    expect(dispatched).toMatchObject([
      {
        action: 'security_oauth_provider_set',
        payload: {
          providerKey: PROVIDER,
          field: 'client_secret',
          value: 's3cret',
        },
      },
    ])
    // Submitted, not answered: the row still says what the table last said.
    for (const text of secretCellTexts(container)) {
      expect(text).toBe('Not set')
    }

    dispatched[0]?.settle({
      action: 'security_oauth_provider_set',
    } as ActionResult)
    await settled()

    // Answered: the dialog closes on the server's word, and the row still waits
    // for the table — the acknowledgement is not the row.
    expect(
      document.querySelector('[data-id="hilos-oauth-field-input"]'),
    ).toBeNull()
    for (const text of secretCellTexts(container)) {
      expect(text).toBe('Not set')
    }

    pushRowUpdated(
      FIELDS_TABLE,
      'field',
      secretRow({ source: 'db', setState: true }),
    )

    expect(secretCells(container).length).toBeGreaterThan(0)
    for (const text of secretCellTexts(container)) {
      expect(text).toBe('Set')
    }
    expect(document.body.textContent).not.toContain('s3cret')
  })
})

// The row-edit modal (HIL-1134), the React peer of
// vue/src/admin/security/HilosSecurityOauthProviderPage.test.ts.

const CLIENT_ID_KEY = `${PROVIDER}/client_id`
const SECRET_KEY = `${PROVIDER}/client_secret`
const SCOPE_KEY = `${PROVIDER}/scope`

/** The label each field row carries. */
const FIELD_LABEL: Record<string, string> = {
  client_id: 'Client ID',
  client_secret: 'Client secret',
  scope: 'Scope',
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
  return byId('hilos-oauth-field-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-oauth-field-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return byId('hilos-oauth-field-edit-notice')
}

function typeDraft(text: string): void {
  fireEvent.change(valueInput(), { target: { value: text } })
}

/**
 * Mount the page and open the modal of one field. The row's controls stand in
 * the document twice — the table and the narrow-screen card — so the button is
 * looked up through the table.
 */
function openModal(
  context: HilosSecurityOauthContext,
  field: 'client_id' | 'client_secret',
): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurityOauthProviderPage context={context} />
    </HilosRouterContext.Provider>,
  )
  fireEvent.click(
    document.querySelector(
      `table [data-id="hilos-oauth-field-edit-${field}"]`,
    ) as Element,
  )
}

describe('HilosSecurityOauthProviderPage field modal', () => {
  it('opens on the live value with save locked and the message line empty', () => {
    const { context, focus } = seededContext('Iv1.a')
    openModal(context, 'client_id')

    expect(valueInput().value).toBe('Iv1.a')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY])
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    openModal(context, 'client_id')
    typeDraft('Iv1.mine')
    act(() => {
      pushUpdate('Iv1.b')
    })

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(byId('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    openModal(context, 'client_id')

    act(() => {
      pushUpdate('Iv1.b')
    })

    expect(valueInput().value).toBe('Iv1.b')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
    openModal(context, 'client_id')
    typeDraft('Iv1.mine')

    act(() => {
      pushUpdate(null)
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "—"')
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(byId('conflict-accept-mine') as Element)
    fireEvent.click(saveButton())
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
    openModal(context, 'client_id')
    typeDraft('Iv1.mine')
    act(() => {
      pushUpdate('Iv1.b')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(valueInput().value).toBe('Iv1.b')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', () => {
    const { context, pushRemove, focus } = seededContext('Iv1.a')
    openModal(context, 'client_id')
    typeDraft('Iv1.mine')
    act(() => {
      pushRemove(CLIENT_ID_KEY)
    })

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('Iv1.mine')

    fireEvent.click(byId('modal-close') as Element)
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })
})

describe('HilosSecurityOauthProviderPage secret modal', () => {
  it('opens empty with save locked, and a typed secret opens save and sends', () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    openModal(context, 'client_secret')

    expect(focus).toEqual([SECRET_KEY])
    expect(valueInput().value).toBe('')
    expect(saveButton().disabled).toBe(true)
    expect(byId('conflict-badge')).toBeNull()

    typeDraft('s3cret')
    expect(saveButton().disabled).toBe(false)
    fireEvent.click(saveButton())
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toMatchObject({
      field: 'client_secret',
      value: 's3cret',
    })
  })

  it('sends nothing on Enter while the input is empty', () => {
    const { context, sent } = seededContext('Iv1.a')
    openModal(context, 'client_secret')

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
  })

  it('says only that the row is gone, and locks save as Deleted', () => {
    const { context, pushRemove } = seededContext('Iv1.a')
    openModal(context, 'client_secret')
    typeDraft('s3cret')
    act(() => {
      pushRemove(SECRET_KEY)
    })

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(byId('conflict-badge')).toBeNull()
  })
})

describe('HilosSecurityOauthProviderPage reset dialog', () => {
  function renderPage(context: HilosSecurityOauthContext): void {
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurityOauthProviderPage context={context} />
      </HilosRouterContext.Provider>,
    )
  }

  function resetButton(field: string): HTMLButtonElement {
    return document.querySelector(
      `table [data-id="hilos-oauth-field-reset-${field}"]`,
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return byId('hilos-oauth-field-reset-confirm') as HTMLButtonElement
  }

  function cancelButton(): HTMLButtonElement {
    return Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  function openReset(
    context: HilosSecurityOauthContext,
    field: 'client_id' | 'client_secret' | 'scope',
  ): void {
    renderPage(context)
    fireEvent.click(resetButton(field))
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
    const { context, sent, focus } = seededContext('Iv1.a')
    openReset(context, 'client_id')

    expect(byId('modal')?.textContent).toContain('Reset · Client ID')
    expect(focus).toEqual([CLIENT_ID_KEY])
    expect(sent).toEqual([])
  })

  it('shows the value now, where it goes back to, and what it costs sign-in', () => {
    const { context } = seededContext('Iv1.a')
    openReset(context, 'client_id')

    expect(byId('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.a',
    )
    expect(byId('hilos-oauth-field-reset-default')?.textContent?.trim()).toBe(
      'the env value, or the default when env has none',
    )
    expect(byId('hilos-oauth-field-reset-signin')?.textContent?.trim()).toBe(
      'If env has none, sign-in with GitHub stops being offered.',
    )
  })

  it('shows the secret as set, never its value', () => {
    const { context } = seededContext('Iv1.a')
    openReset(context, 'client_secret')

    expect(byId('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe('Set')
    expect(byId('hilos-oauth-field-reset-signin')).not.toBeNull()
  })

  it('says nothing of sign-in when resetting the scope', () => {
    const { context } = seededContext('Iv1.a')
    openReset(context, 'scope')

    expect(byId('modal')?.textContent).toContain('Reset · Scope')
    expect(byId('hilos-oauth-field-reset-signin')).toBeNull()
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext('Iv1.a')
    openReset(context, 'client_id')

    fireEvent.click(confirmButton())
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'security_oauth_provider_reset',
        payload: { providerKey: PROVIDER, field: 'client_id' },
      },
    ])

    await reply(answer, 'success')
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext('Iv1.a')
    openReset(context, 'client_id')

    fireEvent.click(cancelButton())

    expect(byId('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext('Iv1.a')
    openReset(context, 'client_id')

    fireEvent.click(confirmButton())
    await reply(answer, 'fail')

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The provider refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    openReset(context, 'client_id')
    expect(byId('hilos-oauth-field-reset-gone')).toBeNull()

    act(() => {
      pushUpdate('Iv1.env', 'env')
    })

    expect(byId('hilos-oauth-field-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(byId('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.env',
    )
    expect(confirmButton().disabled).toBe(true)
  })

  it('locks ↺ while the value is not set in admin', () => {
    const { context, pushUpdate } = seededContext('Iv1.a')
    renderPage(context)
    expect(resetButton('client_id').disabled).toBe(false)

    act(() => {
      pushUpdate('Iv1.env', 'env')
    })

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
    return byId('hilos-oauth-field-reset-confirm') as HTMLButtonElement
  }

  it('a viewer opens field edit and finds Save disabled with the mode strip reference', async () => {
    const { context, sent, answer } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake(null, true)
    openModal(context, 'client_id')

    expect(saveButton().disabled).toBe(true)
    expect(saveButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    typeDraft('Iv1.mine')
    expect(saveButton().disabled).toBe(true)

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_provider_set')
    expect(sent[0]?.payload).toEqual({
      providerKey: PROVIDER,
      field: 'client_id',
      value: 'Iv1.mine',
    })

    await act(async () => {
      answer('fail', 'view_mode')
      await Promise.resolve()
    })

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      HILOS_VIEW_MODE_COPY.refusal,
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('a viewer opens field reset and finds confirm disabled with the mode strip reference', () => {
    const { context, sent } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake(null, true)
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurityOauthProviderPage context={context} />
      </HilosRouterContext.Provider>,
    )

    expect(resetButton('client_id').disabled).toBe(false)
    fireEvent.click(resetButton('client_id'))

    expect(confirmButton().disabled).toBe(true)
    expect(confirmButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirmButton())
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode has Save and Reset active', () => {
    const { context } = seededContext('Iv1.a')
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    openModal(context, 'client_id')

    typeDraft('Iv1.mine')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as HTMLElement)

    fireEvent.click(byId('modal-confirm-discard') as HTMLElement)

    fireEvent.click(resetButton('client_id'))

    expect(confirmButton().disabled).toBe(false)
    expect(confirmButton().getAttribute('aria-describedby')).toBeNull()
  })
})
