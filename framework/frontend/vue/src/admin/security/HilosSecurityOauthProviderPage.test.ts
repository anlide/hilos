// The provider-field edit modal on the shared row-edit helper (HIL-1134): a
// field's modal holds its row in focus, reloads a pristine edit when the value
// changes elsewhere, shows the conflict chrome without Merge on a changed one and
// answers Keep mine / Take theirs, and locks save as "Deleted" when the row goes.
// The secret's modal opens empty, keeps save locked until something is typed,
// and only ever says the row is gone. The ↺ resets a field only through a
// confirm dialog (HIL-1147).
import { flushPromises, mount } from '@vue/test-utils'
import { h, markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  computedSignal,
  createAppPageRouter,
  createSignal,
  pageIdentity,
  resolvePageTitle,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'
import {
  OAUTH_PROVIDER_HEAD_DATA,
  type HilosOAuthProviderHead,
  type HilosSecurityOauthContext,
} from '@hilos/core/admin/security'

import HilosSecurityOauthProviderPage from './HilosSecurityOauthProviderPage.vue'
import HilosLayout from '../../HilosLayout.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const PROVIDER = 'oauth:github'
const FIELDS_TABLE = 'hilosSecurityOauthProviderFields'
const PROVIDERS_TABLE = 'hilosSecurityOauthProviders'
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
    onLeave: () => () => {},
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
    defaultValue: secret
      ? null
      : field === 'scope'
        ? 'read:user user:email'
        : '',
    defaultSource: 'default',
    defaultSetState: field === 'scope',
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

function seededContext(
  clientId: string | null,
  initialFields: Record<string, Record<string, unknown>> = {},
  initialProvider: Record<string, unknown> | null = PROVIDER_SLOT,
  automaticWindows = true,
): {
  context: HilosSecurityOauthContext
  releaseWindows: () => void
  pushProviderRow: (
    row: Record<string, unknown> | null,
    update?: boolean,
  ) => void
  pushUpdate: (value: string | null, source?: string) => void
  pushFieldUpdate: (field: string, changes: Record<string, unknown>) => void
  pushProviderUpdate: (configured: boolean | null) => void
  pushRemove: (rowKey: string) => void
  answer: (
    outcome: 'success' | 'fail',
    errorCode?: string,
    reason?: string,
  ) => void
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
  for (const [field, changes] of Object.entries(initialFields)) {
    const rowKey = `${PROVIDER}/${field}`
    fields.set(rowKey, { ...fields.get(rowKey), ...changes })
  }
  let provider = initialProvider
  const focus: string[] = []
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
  scope.data.set(
    OAUTH_PROVIDER_HEAD_DATA,
    initialProvider === null
      ? null
      : {
          providerKey: initialProvider.providerKey,
          label: initialProvider.label,
          configured: initialProvider.configured,
          missingFields: initialProvider.missingFields,
        },
  )
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === FIELDS_TABLE
        ? [...fields].map(([rowKey, field]) => ({ rowKey, slots: { field } }))
        : provider === null
          ? []
          : [{ rowKey: provider.providerKey, slots: { provider } }]
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
    state: 'connected',
    protectedMode: PROTECTED_MODE_INACTIVE,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    registerTableWindow(tableKey: string): void {
      if (automaticWindows) serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      if (automaticWindows) serveWindow(tableKey)

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
    releaseWindows(): void {
      automaticWindows = true
      serveWindow(PROVIDERS_TABLE)
      serveWindow(FIELDS_TABLE)
    },
    pushProviderRow(row: Record<string, unknown> | null, update = false): void {
      provider = row
      if (update && row !== null) {
        pushDelta({
          tableKey: PROVIDERS_TABLE,
          kind: 'row_updated',
          rowKey: row.providerKey,
          row: { rowKey: row.providerKey, slots: { provider: row } },
        })
      } else {
        serveWindow(PROVIDERS_TABLE)
      }
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
    pushFieldUpdate(field: string, changes: Record<string, unknown>): void {
      const rowKey = `${PROVIDER}/${field}`
      const slot = { ...fields.get(rowKey), ...changes }
      fields = new Map(fields).set(rowKey, slot)
      pushDelta({
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { field: slot } },
      })
    },
    pushProviderUpdate(configured: boolean | null): void {
      if (configured === null) {
        provider = null
        serveWindow(PROVIDERS_TABLE)
        return
      }
      provider = {
        ...PROVIDER_SLOT,
        configured,
        missingFields: configured ? 0 : 1,
      }
      pushDelta({
        tableKey: PROVIDERS_TABLE,
        kind: 'row_updated',
        rowKey: PROVIDER,
        row: { rowKey: PROVIDER, slots: { provider } },
      })
    },
    pushRemove(rowKey: string): void {
      fields = new Map(fields)
      fields.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
    },
    // Answer the last action sent, the way the server replies to it.
    answer(
      outcome: 'success' | 'fail',
      errorCode?: string,
      reason = 'The provider refused the reset.',
    ): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason,
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

function cancelButton(): HTMLButtonElement {
  return Array.from(
    document.querySelectorAll<HTMLButtonElement>(
      '[data-id="modal"] .modal-footer button',
    ),
  ).find((button) => button.textContent?.trim() === 'Cancel')!
}

// The fieldset the modal stands its body in; a save in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the dialog: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  modalEl('modal-close')?.click()
  cancelButton().click()
  modalEl('modal')?.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modalEl('modal')?.click()
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

const PROVIDER_HEAD: HilosOAuthProviderHead = {
  providerKey: PROVIDER,
  label: 'GitHub',
  configured: true,
  missingFields: 0,
}
const headReleases: Array<() => void> = []
afterEach(() => {
  for (const release of headReleases.splice(0)) release()
  document.title = ''
})

/** The real head, identity binding and document-title consumer, with windows held back. */
function headHarness(
  options: {
    head?: unknown
    providerKey?: string
    provider?: Record<string, unknown> | null
    viewer?: boolean
  } = {},
) {
  const t = seededContext('Iv1.a', {}, options.provider, false)
  const { scopes } = t.context
  scopes.session.data.set('adminViewMode', options.viewer === true)
  if (!options.viewer) {
    const user = { type: 'users', id: '1' }
    scopes.session.entities.upsert(user, { admin: true })
    scopes.session.data.set('currentUser', user)
  }
  headReleases.push(bindAdminAccess(scopes))
  const scope = scopes.page()!
  const answer = (head: unknown): void => {
    scope.data.set('pageLabel', 'OAuth provider')
    scope.data.set('pageLead', 'A single OAuth provider configuration.')
    scope.data.set('pageHeadOwner', {
      page: HilosPages.SECURITY_OAUTH_PROVIDER,
      label: 'OAuth provider',
      lead: 'A single OAuth provider configuration.',
    })
    scope.data.set('pageBreadcrumb', [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.SECURITY, label: 'Security Center' },
      { page: HilosPages.SECURITY_OAUTH, label: 'OAuth providers' },
      { page: HilosPages.SECURITY_OAUTH_PROVIDER, label: 'OAuth provider' },
    ])
    scope.data.set('pageTabs', [
      { page: HilosPages.SECURITY_OAUTH_PROVIDER, label: 'Overview' },
    ])
    scope.data.set('pageChildren', [])
    scope.data.set(OAUTH_PROVIDER_HEAD_DATA, head)
  }
  answer(Object.hasOwn(options, 'head') ? options.head : PROVIDER_HEAD)
  const identity = pageIdentity(scopes)
  const routes = createAppPageRouter({})
  const route = createSignal<PageRouteMatch>({
    page: HilosPages.SECURITY_OAUTH_PROVIDER,
    params: { providerId: options.providerKey ?? PROVIDER },
    admin: true,
  })
  const loading = createSignal(false)
  const navigation: HilosRouter = {
    ...router(),
    currentRoute: route,
    pageLoading: loading,
    pageIdentity: identity,
    currentTitle: computedSignal(() => {
      const current = identity.get()
      return resolvePageTitle(
        route.get().page,
        {},
        'Chat',
        current?.label,
        !loading.get(),
        current?.pageHeadOwner?.label,
      )
    }),
    resolvePath: (page, params) => routes.path(page, params),
  }
  const view = mount(HilosLayout, {
    props: { connection: markRaw(t.context.connection) },
    slots: {
      default: () =>
        h(HilosSecurityOauthProviderPage, { context: markRaw(t.context) }),
    },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  mounted.push(view)
  return { ...t, scope, answer, route, loading, navigation, view }
}

describe('HilosSecurityOauthProviderPage entity head', () => {
  it.each([false, true])(
    'uses the complete first payload before the filtered windows (viewer=%s)',
    async (viewer) => {
      const t = headHarness({ viewer })
      await settle()

      expect(t.view.findAll('h1').map((title) => title.text())).toEqual([
        'GitHub',
      ])
      const crumbs = t.view.get('nav[aria-label="Breadcrumb"]')
      expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
        'Hilos',
        'Security Center',
        'OAuth providers',
        'GitHub',
      ])
      expect(
        crumbs.findAll('a').map((link) => link.attributes('href')),
      ).toEqual(['/hilos', '/hilos/security', '/hilos/security/oauth'])
      expect(crumbs.get('[aria-current="page"]').find('a').exists()).toBe(false)
      expect(document.title).toBe('GitHub · Chat')
      expect(t.view.get('[data-id="oauth-provider-head-state"]').text()).toBe(
        'Configured',
      )
      expect(
        t.view.get('[data-id="oauth-provider-head-icon"]').classes(),
      ).toEqual(['bi', 'bi-github'])
      expect(
        t.view
          .get('[data-id="oauth-provider-head-icon"]')
          .attributes('aria-hidden'),
      ).toBe('true')
      expect(t.view.get('[data-id="oauth-provider-head-key"]').text()).toBe(
        PROVIDER,
      )
      expect(
        t.view.findAll('[data-id="oauth-provider-head-key"]'),
      ).toHaveLength(1)
      const block = t.view.get('[data-id="hilos-page-head-block"]')
      expect(block.findAll('code').map((code) => code.text())).toEqual([
        PROVIDER,
      ])
      expect(block.get('p').text()).toBe(`Provider ${PROVIDER}`)
      expect(t.view.find('p.text-body-secondary.mb-3').exists()).toBe(false)
      expect(t.view.find('nav[aria-label="Page sections"]').exists()).toBe(
        false,
      )
      expect(t.view.find('[data-id="hilos-admin-children"]').exists()).toBe(
        false,
      )
      expect(t.view.find('[data-id="hilos-oauth-recipe"]').exists()).toBe(false)
      expect(t.sent).toEqual([])

      t.releaseWindows()
      await settle()
      expect(t.view.get('[data-id="hilos-oauth-recipe"]').text()).toContain(
        'Recipe',
      )
      expect(t.view.get('h1').text()).toBe('GitHub')
    },
  )

  it.each([
    { key: 'oauth:google', label: 'Google', missing: 1, icon: 'bi-google' },
    {
      key: 'oauth:custom',
      label: 'Company login',
      missing: 2,
      icon: 'bi-box-arrow-in-right',
    },
  ])(
    'shows $missing missing and the shared icon for $key',
    async ({ key, label, missing, icon }) => {
      const t = headHarness({
        providerKey: key,
        head: {
          providerKey: key,
          label,
          configured: false,
          missingFields: missing,
        },
      })
      await settle()

      expect(t.view.get('h1').text()).toBe(label)
      expect(document.title).toBe(`${label} · Chat`)
      expect(t.view.get('[data-id="oauth-provider-head-state"]').text()).toBe(
        `${missing} missing`,
      )
      expect(
        t.view
          .get('[data-id="oauth-provider-head-state"] i')
          .attributes('aria-hidden'),
      ).toBe('true')
      expect(
        t.view.get('[data-id="oauth-provider-head-icon"]').classes(),
      ).toContain(icon)
      expect(t.view.get('[data-id="oauth-provider-head-key"]').text()).toBe(key)
      expect(
        t.view.get('nav[aria-label="Breadcrumb"] [aria-current="page"]').text(),
      ).toBe(label)
    },
  )

  it('follows the matching summary row live without replacing the complete body model', async () => {
    const t = headHarness()
    t.releaseWindows()
    await settle()
    t.pushProviderRow(
      {
        ...PROVIDER_SLOT,
        label: 'GitHub enterprise',
        configured: false,
        missingFields: 2,
        authorizeUrl: 'https://login.example/authorize',
      },
      true,
    )
    await settle()

    expect(t.view.get('h1').text()).toBe('GitHub enterprise')
    expect(document.title).toBe('GitHub enterprise · Chat')
    expect(
      t.view.get('nav[aria-label="Breadcrumb"] [aria-current="page"]').text(),
    ).toBe('GitHub enterprise')
    expect(t.view.get('[data-id="oauth-provider-head-state"]').text()).toBe(
      '2 missing',
    )
    expect(t.view.get('[data-id="hilos-oauth-recipe"]').text()).toContain(
      'https://login.example/authorize',
    )
    t.pushProviderRow({ ...PROVIDER_SLOT, label: 'Ready again' }, true)
    await settle()
    expect(t.view.get('h1').text()).toBe('Ready again')
    expect(document.title).toBe('Ready again · Chat')
    expect(t.view.get('[data-id="oauth-provider-head-state"]').text()).toBe(
      'Configured',
    )
    expect(t.sent).toEqual([])
    t.view.unmount()
    expect(t.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'OAuth provider',
    )
  })

  it('rejects an old snapshot and a foreign summary when navigating between provider ids', async () => {
    const t = headHarness()
    t.releaseWindows()
    await settle()
    t.loading.set(true)
    t.route.set({ ...t.route.get(), params: { providerId: 'oauth:google' } })
    await settle()
    expect(t.view.find('[data-id="oauth-provider-head-state"]').exists()).toBe(
      false,
    )
    expect(t.view.find('[data-id="oauth-provider-head-key"]').exists()).toBe(
      false,
    )
    expect(t.view.get('h1').text()).toBe('OAuth provider')

    t.loading.set(false)
    await settle()
    expect(t.view.find('[data-id="oauth-provider-head-icon"]').exists()).toBe(
      false,
    )
    expect(
      t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
    ).toBe(false)
    expect(t.view.get('h1').text()).toBe('OAuth provider')
    t.answer({
      providerKey: 'oauth:google',
      label: 'Google',
      configured: false,
      missingFields: 1,
    })
    await settle()
    expect(t.view.get('h1').text()).toBe('Google')
    expect(document.title).toBe('Google · Chat')
    expect(t.view.get('[data-id="oauth-provider-head-key"]').text()).toBe(
      'oauth:google',
    )
    expect(t.view.get('[data-id="oauth-provider-head-state"]').text()).toBe(
      '1 missing',
    )
    t.pushProviderRow({ ...PROVIDER_SLOT, label: 'Foreign old label' }, true)
    await settle()
    expect(t.view.get('h1').text()).toBe('Google')
    t.pushProviderRow({
      ...PROVIDER_SLOT,
      providerKey: 'oauth:google',
      label: 'Google live',
    })
    await settle()
    expect(t.view.get('h1').text()).toBe('Google live')
    expect(document.title).toBe('Google live · Chat')
  })

  it('ignores an old unknown null during navigation and accepts a new known head before its window', async () => {
    const t = headHarness({
      head: null,
      providerKey: 'oauth:unknown',
      provider: null,
    })
    t.releaseWindows()
    await settle()
    expect(
      t.view.get('[data-id="hilos-oauth-provider-not-found"]').text(),
    ).toContain('oauth:unknown')
    expect(t.view.find('[data-id="oauth-provider-head-key"]').exists()).toBe(
      false,
    )
    expect(document.title).toBe('OAuth provider · Chat')

    t.loading.set(true)
    t.route.set({ ...t.route.get(), params: { providerId: PROVIDER } })
    await settle()
    expect(
      t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
    ).toBe(false)
    t.answer(PROVIDER_HEAD)
    await settle()
    expect(t.view.find('[data-id="oauth-provider-head-state"]').exists()).toBe(
      false,
    )
    t.loading.set(false)
    await settle()
    expect(t.view.get('h1').text()).toBe('GitHub')
    expect(document.title).toBe('GitHub · Chat')
    expect(
      t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
    ).toBe(false)
  })

  it('uses a new explicit unknown head even while the old summary is loaded', async () => {
    const t = headHarness()
    t.releaseWindows()
    await settle()
    t.loading.set(true)
    t.route.set({ ...t.route.get(), params: { providerId: 'oauth:unknown' } })
    await settle()
    expect(t.view.find('[data-id="oauth-provider-head-key"]').exists()).toBe(
      false,
    )
    expect(
      t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
    ).toBe(false)
    t.answer(null)
    t.loading.set(false)
    await settle()
    expect(
      t.view.get('[data-id="hilos-oauth-provider-not-found"]').text(),
    ).toContain('oauth:unknown')
    expect(t.view.get('h1').text()).toBe('OAuth provider')
    expect(document.title).toBe('OAuth provider · Chat')
    expect(t.view.find('[data-id="oauth-provider-head-state"]').exists()).toBe(
      false,
    )
    expect(t.view.find('[data-id="hilos-oauth-recipe"]').exists()).toBe(false)
    expect(t.view.find('table').exists()).toBe(false)
  })

  it.each([
    undefined,
    { ...PROVIDER_HEAD, configured: false },
    { ...PROVIDER_HEAD, missingFields: 3 },
    { ...PROVIDER_HEAD, providerKey: 'oauth:other' },
  ])(
    'keeps missing, malformed or mismatched head pending: %j',
    async (head) => {
      const t = headHarness({ head, provider: null })
      t.releaseWindows()
      await settle()
      expect(t.view.get('h1').text()).toBe('OAuth provider')
      expect(
        t.view.find('[data-id="oauth-provider-head-state"]').exists(),
      ).toBe(false)
      expect(t.view.find('[data-id="oauth-provider-head-key"]').exists()).toBe(
        false,
      )
      expect(
        t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
      ).toBe(false)
      expect(t.view.findComponent({ name: 'HilosSkeleton' }).exists()).toBe(
        true,
      )
    },
  )

  it('clears the binding when either active page or route leaves the provider', async () => {
    const t = headHarness()
    await settle()
    t.route.set({ ...t.route.get(), page: HilosPages.SECURITY_OAUTH })
    await settle()
    expect(t.view.get('h1').text()).toBe('OAuth provider')
    expect(t.view.find('[data-id="oauth-provider-head-key"]').exists()).toBe(
      false,
    )
    t.route.set({ ...t.route.get(), page: HilosPages.SECURITY_OAUTH_PROVIDER })
    await settle()
    expect(t.view.get('h1').text()).toBe('GitHub')
    t.context.scopes.openPage(HilosPages.SECURITY)
    await settle()
    expect(t.navigation.pageIdentity.get()).toBeUndefined()
    expect(t.view.find('[data-id="oauth-provider-head-state"]').exists()).toBe(
      false,
    )
    expect(
      t.view.find('[data-id="hilos-oauth-provider-not-found"]').exists(),
    ).toBe(false)
  })
})

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
    const { context, pushRemove, focus, sent } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')
    pushRemove(CLIENT_ID_KEY)
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('Iv1.mine')
    const field = valueInput()
    saveButton().click()
    field.form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(sent).toHaveLength(0)

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm')?.getAttribute('aria-label')).toBe(
      'Discard changes?',
    )
    expect(modalEl('modal')).not.toBeNull()
    expect(valueInput()).toBe(field)
    expect(valueInput().value).toBe('Iv1.mine')
    expect(focus).toEqual([CLIENT_ID_KEY])
    modalEl('modal-confirm-cancel')?.click()
    await nextTick()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(modalEl('modal')).not.toBeNull()
    expect(valueInput()).toBe(field)
    expect(valueInput().value).toBe('Iv1.mine')
    expect(focus).toEqual([CLIENT_ID_KEY])

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()
    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY, ''])
    expect(sent).toHaveLength(0)
  })

  it('holds the dialog and locks its body while the save is in flight, and frees the same field after a refusal', async () => {
    const { context, sent, answer, focus } = seededContext('Iv1.a')
    await openModal(context, 'client_id')
    await typeDraft('Iv1.mine')
    const field = valueInput()
    const body = bodyLock()
    const cancel = cancelButton()
    const close = modalEl('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toMatchObject({
      field: 'client_id',
      value: 'Iv1.mine',
    })
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY])

    answer('fail')
    await flushPromises()
    expect(modalEl('modal')).not.toBeNull()
    expect(bodyLock()).toBe(body)
    expect(valueInput()).toBe(field)
    expect(field.value).toBe('Iv1.mine')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await typeDraft('Iv1.mine.again')
    saveButton().click()
    await nextTick()
    expect(sent[1]?.payload).toMatchObject({
      field: 'client_id',
      value: 'Iv1.mine.again',
    })
    answer('success')
    await flushPromises()
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
    for (const field of ['client_id', 'client_secret', 'scope'] as const) {
      expect(resetButton(field).title).toBe('Reset to default')
      expect(resetButton(field).getAttribute('aria-label')).toBe(
        'Reset to default',
      )
    }
  })

  it('shows the value now, where it goes back to, and what it costs sign-in', async () => {
    const { context } = seededContext('Iv1.a')
    await openReset(context, 'client_id')

    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.a',
    )
    expect(
      modalEl('hilos-oauth-field-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('— Default')
    expect(modalEl('hilos-oauth-field-reset-signin')?.textContent?.trim()).toBe(
      'After this reset, sign-in with GitHub stops being offered.',
    )
    expect(
      modalEl('hilos-oauth-field-reset-default')?.classList.contains(
        'text-break',
      ),
    ).toBe(true)
    const twin = modalEl(
      'hilos-oauth-field-reset-signin',
    )?.parentElement?.querySelector('.invisible')
    expect(twin?.getAttribute('aria-hidden')).toBe('true')
    expect(twin?.hasAttribute('inert')).toBe(true)
    expect(twin?.textContent?.trim()).toBe(
      modalEl('hilos-oauth-field-reset-signin')?.textContent?.trim(),
    )
  })

  it.each([
    { value: 'Iv1.env', source: 'env', expected: 'Iv1.env From env' },
    { value: 'recipe-id', source: 'default', expected: 'recipe-id Default' },
    { value: null, source: 'default', expected: '— Default' },
    { value: '', source: 'env', expected: '— From env' },
  ])(
    'shows the non-secret fallback $value from $source',
    async ({ value, source, expected }) => {
      const { context } = seededContext('Iv1.a', {
        client_id: {
          defaultValue: value,
          defaultSource: source,
          defaultSetState: Boolean(value),
        },
      })
      await openReset(context, 'client_id')

      expect(
        modalEl('hilos-oauth-field-reset-default')
          ?.textContent?.replace(/\s+/g, ' ')
          .trim(),
      ).toBe(expected)
    },
  )

  it('updates fallback and readiness in the open dialog without another action', async () => {
    const { context, pushFieldUpdate, pushProviderUpdate, sent, focus } =
      seededContext('Iv1.a')
    await openReset(context, 'client_id')
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()

    pushFieldUpdate('client_id', {
      value: 'Iv1.b',
      defaultValue: 'Iv1.env',
      defaultSource: 'env',
      defaultSetState: true,
    })
    await settle()
    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Iv1.b',
    )
    expect(
      modalEl('hilos-oauth-field-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Iv1.env From env')
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()

    pushFieldUpdate('client_id', {
      defaultValue: '',
      defaultSource: 'default',
      defaultSetState: false,
    })
    await settle()
    expect(
      modalEl('hilos-oauth-field-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('— Default')
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()

    pushProviderUpdate(false)
    await settle()
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
    pushProviderUpdate(true)
    await settle()
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()
    expect(focus).toEqual([CLIENT_ID_KEY])
    expect(sent).toEqual([])
  })

  it('has no warning when the provider is already unready', async () => {
    const { context } = seededContext(
      'Iv1.a',
      {},
      { ...PROVIDER_SLOT, configured: false, missingFields: 1 },
    )
    await openReset(context, 'client_id')

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
  })

  it('drops the warning when the summary provider disappears under the open dialog', async () => {
    const { context, pushProviderUpdate } = seededContext('Iv1.a')
    await openReset(context, 'client_id')
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()

    pushProviderUpdate(null)
    await settle()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
  })

  it('keeps a hidden fallback as a hidden mark even when unset', async () => {
    const { context, pushFieldUpdate } = seededContext('Iv1.a')
    await openReset(context, 'client_id')
    pushFieldUpdate('client_id', {
      defaultValue: { _hidden: true },
      defaultSetState: false,
    })
    await settle()

    const fallback = modalEl('hilos-oauth-field-reset-default')
    expect(fallback?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(fallback?.querySelector('.badge.ms-2')?.textContent?.trim()).toBe(
      'Default',
    )
    expect(fallback?.textContent).not.toContain('[object Object]')
    expect(fallback?.textContent).not.toContain('—')
  })

  it('shows the secret as set, never its value', async () => {
    const { context, pushFieldUpdate } = seededContext('Iv1.a', {
      client_secret: { defaultValue: 'must-never-be-rendered' },
    })
    await openReset(context, 'client_secret')

    expect(modalEl('hilos-oauth-field-reset-now')?.textContent?.trim()).toBe(
      'Set',
    )
    expect(
      modalEl('hilos-oauth-field-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Not set Default')
    expect(modalEl('hilos-oauth-field-reset-signin')).not.toBeNull()
    expect(document.body.textContent).not.toContain('must-never-be-rendered')

    pushFieldUpdate('client_secret', {
      defaultValue: 'must-never-be-rendered',
      defaultSetState: true,
      defaultSource: 'env',
    })
    await settle()
    expect(
      modalEl('hilos-oauth-field-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Set From env')
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
    expect(document.body.textContent).not.toContain('must-never-be-rendered')
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
    const { context, pushUpdate, sent } = seededContext('Iv1.a')
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
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
    confirmButton().click()
    await settle()
    expect(sent).toEqual([])
  })

  it('keeps the dialog and drops the warning when the focused field is deleted', async () => {
    const { context, pushRemove, sent } = seededContext('Iv1.a')
    await openReset(context, 'client_id')
    pushRemove(CLIENT_ID_KEY)
    await settle()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-oauth-field-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(modalEl('hilos-oauth-field-reset-signin')).toBeNull()
    expect(confirmButton().disabled).toBe(true)
    confirmButton().click()
    expect(sent).toEqual([])
  })

  it('says nothing of sign-in when resetting the scope', async () => {
    const { context } = seededContext('Iv1.a', {
      scope: { defaultValue: '', defaultSetState: false },
    })
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

    answer(
      'fail',
      'view_mode',
      'View mode: you can look around, but not change anything.',
    )
    await settle()
    await nextTick()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      'View mode: you can look around, but not change anything.',
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
