import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { HilosPages, ScopeManager, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
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
