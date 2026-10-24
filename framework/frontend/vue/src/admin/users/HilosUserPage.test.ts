// The rename modal on the shared row-edit helper (HIL-1050): a live rename
// reloads a pristine modal and says so, a typed one conflicts and answers Keep
// mine / Take theirs without Merge, a card whose row went locks save as
// "Deleted", and the modal asks before discarding a changed draft. The account
// merge cases below cover the separate live two-step modal. A window that takes
// something away opens on the server's word about the administrator's
// confirmation (HIL-1275); the fixture answers it by `stepUp`. The takeover
// lives on the card since HIL-1170: its section follows the installation's
// settings the page's first answer carries (`impersonation`).
import { flushPromises, mount } from '@vue/test-utils'
import { h, markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  ActionError,
  type ActionResult,
  bindAdminAccess,
  bindSessionScope,
  createAppPageRouter,
  formatCalendarDate,
  HIDDEN_VALUE,
  hilosToasts,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  type Hideable,
  type ProjectSignal,
  resolveHilosPath,
  ScopeManager,
  createSignal,
  computedSignal,
  pageIdentity,
  resolvePageTitle,
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import {
  createHilosUserDetail,
  type HilosUserDetailRow,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import HilosUserPage from './HilosUserPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/**
 * Navigation uses the production page identity and live instance-name binding.
 * The future user tabs have no built views and therefore resolve no address.
 *
 * @param scopes The same permitted page scopes the production shell reads.
 * @param overrides The members a test needs live.
 */
function router(
  scopes: ScopeManager,
  overrides: Partial<HilosRouter> = {},
): HilosRouter {
  const identity = pageIdentity(scopes)
  const routes = createAppPageRouter({})

  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.USER,
      params: { userId: '1' },
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: computedSignal(() =>
      resolvePageTitle(
        HilosPages.USER,
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
      page === HilosPages.USER_ROLES ||
      page === HilosPages.USER_ACCESS ||
      page === HilosPages.USER_SESSIONS
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
    ...overrides,
  }
}

/**
 * The single-user detail arrives as the one row of the `userDetail` table, its
 * name through the `user` entity — so a rename elsewhere is an entity upsert and
 * a deleted user is the row leaving the table.
 */
function userContext(
  accountMerge = false,
  options: {
    detailHasPassword?: boolean
    detailHasSecondFactor?: boolean
    candidateHasSecondFactor?: boolean
    detailUnverifiedPasswordAddress?: string | null
    candidateHasPassword?: boolean
    candidateUnverifiedPasswordAddress?: string | null
    candidateNameHidden?: boolean
    candidateIdentitiesHidden?: boolean
    stepUp?: 'skip' | 'ask' | 'refused'
    scopes?: ScopeManager
    currentUserId?: number
    graceDays?: Hideable<number>
    impersonation?: Record<string, unknown>
  } = {},
): {
  context: HilosUsersContext
  renameElsewhere: (name: string) => void
  removeRow: () => void
  removeCandidate: () => void
  answerMerge: (reason?: string) => void
  answerImpersonate: (reason?: string) => void
  failRename: () => void
  standingFrame: (data: Record<string, unknown>) => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
} {
  const scopes = options.scopes ?? new ScopeManager()
  const page = scopes.page() ?? scopes.openPage(HilosPages.USER)
  page.data.set('pageLabel', 'User')
  page.data.set('pageLead', 'The user Overview.')
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
    { page: HilosPages.USER_ROLES, label: 'Roles' },
    { page: HilosPages.USER_ACCESS, label: 'Access' },
    { page: HilosPages.USER_SESSIONS, label: 'Sessions' },
  ])
  page.entities.upsert(
    { type: 'user', id: 1 },
    { id: 1, name: 'Alice', lastActivity: null },
  )
  page.tables.upsert('userDetail', 1, {
    users: { type: 'user', id: 1 },
    connections: { presence: 'online', onlineSessionCount: 1 },
    secondFactors: { hasSecondFactor: options.detailHasSecondFactor ?? false },
    identities: {
      hasPassword: options.detailHasPassword ?? false,
      unverifiedPasswordAddress:
        options.detailUnverifiedPasswordAddress ?? null,
    },
  })
  if (options.graceDays !== undefined) {
    page.data.set('accountDeletionGraceDays', options.graceDays)
  }
  if (options.impersonation !== undefined) {
    page.data.set('impersonation', options.impersonation)
  }
  if (options.currentUserId !== undefined) {
    scopes.session.data.set('currentUser', {
      type: 'user',
      id: options.currentUserId,
    })
  } else if (!options.scopes) {
    scopes.session.data.set('currentUser', { type: 'user', id: 99 })
  }
  page.entities.upsert(
    { type: 'user', id: 2 },
    {
      id: 2,
      name: options.candidateNameHidden ? { _hidden: true } : 'Bob',
      lastActivity: null,
    },
  )
  page.entities.upsert(
    { type: 'user', id: 99 },
    { id: 99, name: 'Admin', lastActivity: null },
  )
  const users = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)
  const listeners = new Map<
    string,
    Set<(payload: { data?: unknown } & Record<string, unknown>) => void>
  >()
  const sent: Array<{
    action: string
    data: unknown
    requestId?: string
  }> = []
  const emit = (
    event: string,
    payload: { data?: unknown } & Record<string, unknown>,
  ): void => {
    for (const listener of listeners.get(event) ?? []) {
      listener(payload)
    }
  }
  const candidateRows = () => [
    {
      rowKey: 2,
      slots: {
        users: { type: 'user', id: 2 },
        merge: {
          identities: options.candidateIdentitiesHidden
            ? { _hidden: true }
            : [
                {
                  type: 'email',
                  identifier: 'bob@example.test',
                  provider: null,
                  verified: true,
                },
              ],
          hasPassword: options.candidateHasPassword ?? false,
          hasSecondFactor: options.candidateHasSecondFactor ?? false,
          unverifiedPasswordAddress:
            options.candidateUnverifiedPasswordAddress ?? null,
        },
      },
    },
    {
      rowKey: 99,
      slots: {
        users: { type: 'user', id: 99 },
        merge: { identities: [], hasPassword: false, hasSecondFactor: false },
      },
    },
  ]
  const serveCandidates = (): void => {
    emit('tableWindow', {
      data: {
        page: HilosPages.USER,
        tableKey: 'mergeCandidates',
        rows: candidateRows(),
        totalCount: 2,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 10,
      },
    })
  }
  // The server's word on the step, answered on the next microtask as a socket would.
  const answerStepUp = (action: string, requestId?: string): void => {
    const stepUp = options.stepUp ?? 'skip'
    if (action === 'hilos_step_up_start' && stepUp === 'refused') {
      emit('actionError', {
        kind: 'actionError',
        action,
        requestId,
        reason: STEP_UP_REFUSAL,
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
              purpose: 'grant admin rights',
              method: 'password',
            }
          : undefined,
    })
  }
  const connection = {
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })
      if (action.startsWith('hilos_step_up_')) {
        queueMicrotask(() => answerStepUp(action, requestId))
      }

      return true
    },
    registerTableWindow(tableKey: string): void {
      if (tableKey === 'mergeCandidates') {
        serveCandidates()
      }
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      serveCandidates()

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(
      event: string,
      listener: (payload: { data?: unknown } & Record<string, unknown>) => void,
    ): () => void {
      const eventListeners = listeners.get(event) ?? new Set()
      eventListeners.add(listener)
      listeners.set(event, eventListeners)

      return () => eventListeners.delete(listener)
    },
  } as unknown as HilosConnection

  return {
    context: {
      scopes,
      connection,
      actions: new ActionLifecycle(connection),
      users,
      accountMerge,
    },
    renameElsewhere(name: string): void {
      page.entities.upsert({ type: 'user', id: 1 }, { name })
    },
    removeRow(): void {
      page.tables.delete('userDetail', 1)
    },
    removeCandidate(): void {
      emit('tableViewportDelta', {
        data: {
          page: HilosPages.USER,
          tableKey: 'mergeCandidates',
          kind: 'row_removed',
          rowKey: '2',
          reason: 'deleted',
        },
      })
    },
    answerMerge(reason?: string): void {
      const requestId = sent.at(-1)?.requestId
      if (reason === undefined) {
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action: 'hilos_user_merge',
          requestId,
          message: 'Merged.',
        })

        return
      }
      emit('actionError', {
        kind: 'actionError',
        action: 'hilos_user_merge',
        requestId,
        reason,
      })
    },
    answerImpersonate(reason?: string): void {
      const requestId = sent
        .filter((entry) => entry.action === 'hilos_impersonate_start')
        .at(-1)?.requestId
      if (reason === undefined) {
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action: 'hilos_impersonate_start',
          requestId,
        })

        return
      }
      emit('actionError', {
        kind: 'actionError',
        action: 'hilos_impersonate_start',
        requestId,
        reason,
      })
    },
    failRename(): void {
      emit('unknownSignal', { type: 'hilos_user_update_fail' })
    },
    standingFrame(data: Record<string, unknown>): void {
      emit('projectSignal', {
        kind: 'project',
        type: 'hilos_account_standing_state',
        data,
      })
    },
    sent,
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
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
    ...facts,
  }
}

/** The refusal a window meets when its administrator has nothing to confirm with. */
const STEP_UP_REFUSAL =
  'Add a password, an email or a phone to your account to do this'

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function nameInput(): HTMLInputElement {
  return modalEl('hilos-user-name-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-user-save') as HTMLButtonElement
}

// The fieldset the modal stands its body in; a save in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the dialog: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  modalEl('modal-close')?.click()
  modalEl('hilos-user-cancel')?.click()
  modalEl('modal')?.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modalEl('modal')?.click()
}

async function typeDraft(text: string): Promise<void> {
  const input = nameInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/** Mount the page and open the rename modal. */
async function openModal(context: HilosUsersContext): Promise<void> {
  const wrapper = mount(HilosUserPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router(context.scopes) } },
  })
  mounted.push(wrapper)
  await nextTick()
  modalEl('hilos-user-edit')?.click()
  await nextTick()
}

describe('HilosUserPage Overview', () => {
  it('adopts the real shared head and live identity, with one h1 and three crumbs', async () => {
    const { context, renameElsewhere, removeRow } = userContext()
    const navigation = router(context.scopes)
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      global: { provide: { [hilosRouterKey as symbol]: navigation } },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Alice')
    expect(
      wrapper
        .findAll('[data-id="hilos-breadcrumb"] li')
        .map((crumb) => crumb.text()),
    ).toEqual(['Hilos', 'Users', 'Alice'])
    expect(
      wrapper.get('[data-id="hilos-breadcrumb"] a').attributes('href'),
    ).toBe('/hilos')
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-page-head-block"] button').exists(),
    ).toBe(false)
    expect(navigation.currentTitle.get()).toBe('Alice · Test app')

    renameElsewhere('Alicia')
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('Alicia')
    expect(
      wrapper.get('[data-id="hilos-user-detail"] section:first-of-type').text(),
    ).toContain('Alicia')
    expect(navigation.currentTitle.get()).toBe('Alicia · Test app')

    context.scopes
      .page()!
      .entities.upsert({ type: 'user', id: 1 }, { name: HIDDEN_VALUE })
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('Hidden')
    expect(navigation.currentTitle.get()).toBe('Hidden · Test app')
    expect(wrapper.text()).not.toContain('Alicia')
    expect(wrapper.find('[data-id="hilos-user-name"]').exists()).toBe(false)

    removeRow()
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('User')
    expect(navigation.currentTitle.get()).toBe('User · Test app')
    expect(wrapper.find('[data-id="hilos-user-detail"]').exists()).toBe(false)
  })

  it('orders the Overview sections around the optional project content and opens rename from Profile', async () => {
    const { context, sent } = userContext(true, {
      impersonation: impersonationSettings(),
    })
    context.scopes.page()!.data.set('accountStanding', standing())
    const slot = vi.fn(({ detail }: { detail: HilosUserDetailRow }) =>
      h('section', { 'data-id': 'project-user-overview' }, [
        h('h2', 'Chat participant'),
        h('a', { href: `/user/${detail.id}` }, 'Open participant card'),
      ]),
    )
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      slots: { 'project-overview': slot },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()
    const overview = wrapper.get('[data-id="hilos-user-detail"]')
    expect(overview.findAll('h2').map((heading) => heading.text())).toEqual([
      'Profile',
      'Rights',
      'Access to the product',
      'Acting as another person',
      'Chat participant',
      'Danger zone',
    ])
    expect(overview.findAll('h3').map((heading) => heading.text())).toEqual([
      'Account state',
      'Impersonation',
      'Merge another account into this one',
    ])
    expect(
      overview
        .findAll('section')
        .map((section) => section.attributes('data-id')),
    ).toEqual([
      undefined,
      'hilos-user-rights',
      'hilos-user-access',
      undefined,
      'project-user-overview',
      'hilos-user-merge-zone',
    ])
    expect(overview.get('[data-id="hilos-user-sessions"]').text()).toBe('1')
    expect(overview.find('[data-id="hilos-user-sessions"] a').exists()).toBe(
      false,
    )
    expect(overview.find('dl').exists()).toBe(false)
    expect(overview.text()).not.toMatch(
      /User ID|Online sessions|Last activity|Superadmin|Time zone|Delegations/,
    )
    expect(overview.findAll('.badge').map((badge) => badge.text())).toEqual([])
    expect(
      overview
        .findAll('[data-id$="-state"] > p:first-child')
        .map((row) => row.text()),
    ).toEqual([
      'Not an administrator',
      'Not blocked',
      'Not frozen',
      'No deletion is scheduled',
    ])
    expect(slot.mock.lastCall?.[0].detail).toEqual(
      createHilosUserDetail(context).get(),
    )
    expect(sent).toEqual([])
    const opener = overview.get(
      'section:first-of-type [data-id="hilos-user-edit"]',
    )
    expect(opener.text()).toBe('Change name')
    await opener.trigger('click')
    expect(nameInput().value).toBe('Alice')
    expect(saveButton().disabled).toBe(true)
  })

  it('uses affirmative state sentences without Yes/No badges and keeps deletion details', async () => {
    const { context } = userContext()
    const effectiveAt = Date.now() + 5 * 86_400_000
    context.scopes
      .page()!
      .entities.upsert({ type: 'user', id: 1 }, { admin: true })
    context.scopes.page()!.data.set(
      'accountStanding',
      standing({
        shown: 'blocked',
        blocked: true,
        frozen: true,
        deletionEffectiveAt: effectiveAt,
        lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
      }),
    )
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()
    const overview = wrapper.get('[data-id="hilos-user-detail"]')
    expect(
      overview
        .findAll('[data-id$="-state"] > p:first-child')
        .map((row) => row.text()),
    ).toEqual(['Administrator', 'Blocked', 'Frozen', 'Deletion scheduled'])
    expect(
      overview.get('[data-id="hilos-user-deletion-state"]').text(),
    ).toContain('5 days left')
    expect(overview.get('[data-id="hilos-user-frozen-lapsed"]').text()).toBe(
      'Terms of use — deadline passed 1 September 2026',
    )
    expect(overview.findAll('.badge')).toHaveLength(0)
  })

  it('omits the project section when no slot is supplied and never invokes it without detail', async () => {
    const { context, removeRow } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()
    expect(
      wrapper.findAll('[data-id="hilos-user-detail"] section'),
    ).toHaveLength(3)
    expect(wrapper.findAll('h2').map((heading) => heading.text())).toEqual([
      'Profile',
      'Rights',
      'Access to the product',
    ])
    removeRow()
    await nextTick()
    wrapper.unmount()
    const slot = vi.fn(() => h('section', 'Chat participant'))
    const pending = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      slots: { 'project-overview': slot },
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(pending)
    expect(slot).not.toHaveBeenCalled()
    expect(pending.get('[data-id="hilos-user-empty"]').text()).toBe(
      'Loading user…',
    )
    expect(pending.text()).not.toContain('Chat participant')
  })

  it('passes the permitted readonly detail to project content inside the view-mode providers', async () => {
    const { context, renameElsewhere, removeRow } = userContext()
    context.scopes.session.data.set('adminViewMode', true)
    context.scopes
      .page()!
      .entities.upsert({ type: 'user', id: 1 }, { name: HIDDEN_VALUE })
    const release = bindAdminAccess(context.scopes)
    const slot = vi.fn(({ detail }: { detail: HilosUserDetailRow }) =>
      h('section', [
        h(HilosHideable, { value: detail.name }),
        h(LoadingButton, { 'data-id': 'project-confirm' }, () => 'Confirm'),
      ]),
    )
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      slots: { 'project-overview': slot },
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    try {
      await nextTick()
      expect(slot.mock.lastCall?.[0].detail.name).toEqual(HIDDEN_VALUE)
      expect(slot.mock.lastCall?.[0].detail.id).toBe(1)
      expect(wrapper.text()).not.toContain('Alice')
      expect(
        wrapper.get('[data-id="project-confirm"]').attributes('disabled'),
      ).toBeDefined()
      expect(
        wrapper
          .get('[data-id="project-confirm"]')
          .attributes('aria-describedby'),
      ).toBe(HILOS_VIEW_MODE_STRIP_TEXT_ID)
      renameElsewhere('Allowed by the next answer')
      await nextTick()
      expect(slot.mock.lastCall?.[0].detail.name).toBe(
        'Allowed by the next answer',
      )
      removeRow()
      await nextTick()
      expect(wrapper.find('[data-id="project-confirm"]').exists()).toBe(false)
    } finally {
      release()
    }
  })

  it('keeps one merged notice and project navigation while removing every body mutation', async () => {
    const { context, standingFrame, sent } = userContext(true, {
      impersonation: impersonationSettings(),
    })
    const slot = vi.fn(({ detail }: { detail: HilosUserDetailRow }) =>
      h('section', [
        h('h2', 'Chat participant'),
        h('a', { href: `/user/${detail.id}` }, 'Open participant card'),
      ]),
    )
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      slots: { 'project-overview': slot },
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    standingFrame({
      userId: 1,
      accountStanding: standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
    })
    await nextTick()
    const overview = wrapper.get('[data-id="hilos-user-detail"]')
    expect(wrapper.findAll('[data-id="hilos-user-merged"]')).toHaveLength(1)
    expect(overview.findAll('button')).toHaveLength(0)
    expect(overview.findAll('h2').map((heading) => heading.text())).toEqual([
      'Profile',
      'Chat participant',
    ])
    expect(overview.get('a').attributes('href')).toBe('/user/1')
    expect(slot.mock.lastCall?.[0].detail.id).toBe(1)
    expect(sent).toEqual([])
  })
})

describe('HilosUserPage rename modal', () => {
  it('shows the merge zone only when enabled and locks Next without a candidate', async () => {
    const disabled = userContext()
    const disabledWrapper = mount(HilosUserPage, {
      props: { context: markRaw(disabled.context) },
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(disabled.context.scopes),
        },
      },
    })
    mounted.push(disabledWrapper)
    await nextTick()
    expect(
      disabledWrapper.find('[data-id="hilos-user-merge-zone"]').exists(),
    ).toBe(false)

    const enabled = userContext(true)
    const enabledWrapper = mount(HilosUserPage, {
      props: { context: markRaw(enabled.context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(enabled.context.scopes) },
      },
    })
    mounted.push(enabledWrapper)
    await nextTick()
    expect(
      enabledWrapper.find('[data-id="hilos-user-merge-zone"]').exists(),
    ).toBe(true)
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    expect(document.activeElement).toBe(modalEl('hilos-table-search'))
    expect(
      document.body.querySelectorAll('[data-id="hilos-user-merge-row-2"]'),
    ).toHaveLength(2)
    expect(
      modalEl('modal')
        ?.querySelector('.modal-dialog')
        ?.classList.contains('modal-lg'),
    ).toBe(true)
    expect(
      (modalEl('hilos-user-merge-next') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('requires an independent protection choice and clears it when the live summary changes', async () => {
    const scopes = new ScopeManager()
    const world = userContext(true, {
      scopes,
      detailHasPassword: true,
      candidateHasPassword: true,
      detailHasSecondFactor: true,
      candidateHasSecondFactor: true,
    })
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(world.context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(world.context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()
    modalEl('hilos-user-merge-fate-survivor')?.click()
    await nextTick()
    const submit = () =>
      modalEl('hilos-user-merge-confirm') as HTMLButtonElement
    expect(submit().disabled).toBe(true)
    modalEl('hilos-user-merge-second-factor-both')?.click()
    await nextTick()
    expect(submit().disabled).toBe(false)
    scopes.page()?.tables.upsert('userDetail', 1, {
      secondFactors: { hasSecondFactor: false },
    })
    await nextTick()
    expect(submit().disabled).toBe(true)
    expect(modalEl('hilos-user-merge-second-factor-survivor')).toBeNull()
    expect(document.body.textContent).toContain('current sessions will end')
    modalEl('hilos-user-merge-second-factor-both')?.click()
    await nextTick()
    submit().click()
    expect(world.sent.at(-1)).toMatchObject({
      action: 'hilos_user_merge',
      data: {
        secondFactorFate: 'both',
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: true,
      },
    })
    world.answerMerge('Protection changed')
    await flushPromises()
    expect(modalEl('modal')).not.toBeNull()
    expect(
      (modalEl('hilos-user-merge-second-factor-both') as HTMLInputElement)
        .checked,
    ).toBe(true)
  })

  it('drives password choice, tracked outcomes, and a candidate leaving live', async () => {
    const passwordWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    const passwordWrapper = mount(HilosUserPage, {
      props: { context: markRaw(passwordWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(passwordWorld.context.scopes),
        },
      },
    })
    mounted.push(passwordWrapper)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()

    expect(
      (modalEl('hilos-user-merge-row-99') as HTMLInputElement).disabled,
    ).toBe(true)
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    expect(
      Array.from(
        document.body.querySelectorAll<HTMLInputElement>(
          '[data-id="hilos-user-merge-row-2"]',
        ),
      ).every((choice) => choice.checked),
    ).toBe(true)
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()
    expect(modalEl('hilos-user-merge-fate-survivor')).not.toBeNull()
    expect(
      (modalEl('hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    modalEl('hilos-user-merge-fate-loser')?.click()
    await nextTick()
    modalEl('hilos-user-merge-confirm')?.click()
    expect(passwordWorld.sent.at(-1)).toMatchObject({
      action: 'hilos_user_merge',
      data: {
        survivorUserId: 1,
        loserUserId: 2,
        passwordFate: 'loser',
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: false,
      },
    })
    passwordWorld.answerMerge('Merge refused')
    await nextTick()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(document.body.textContent).toContain('Merge refused')

    passwordWorld.removeCandidate()
    await nextTick()
    expect(modalEl('hilos-user-merge-gone')?.textContent).toContain(
      'No longer available',
    )
    expect(
      (modalEl('hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    passwordWrapper.unmount()
    mounted.splice(mounted.indexOf(passwordWrapper), 1)
    const plainWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: false,
    })
    const plainWrapper = mount(HilosUserPage, {
      props: { context: markRaw(plainWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(plainWorld.context.scopes),
        },
      },
    })
    mounted.push(plainWrapper)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()
    expect(modalEl('hilos-user-merge-fate-survivor')).toBeNull()
    modalEl('hilos-user-merge-confirm')?.click()
    expect(plainWorld.sent.at(-1)).toMatchObject({
      action: 'hilos_user_merge',
      data: {
        survivorUserId: 1,
        loserUserId: 2,
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: false,
      },
    })
    plainWorld.answerMerge()
    await flushPromises()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })

  it('shows removal notices for unconfirmed password addresses in the merge window (HIL-1276)', async () => {
    // 1. Password on both accounts, candidate unconfirmed
    const loserUnverifiedWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: true,
      candidateUnverifiedPasswordAddress: 'bob@example.test',
    })
    const w1 = mount(HilosUserPage, {
      props: { context: markRaw(loserUnverifiedWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(
            loserUnverifiedWorld.context.scopes,
          ),
        },
      },
    })
    mounted.push(w1)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()

    const survivorRemoves = modalEl('hilos-user-merge-fate-survivor-removes')
    expect(survivorRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    const noneRemoves = modalEl('hilos-user-merge-fate-none-removes')
    expect(noneRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    expect(modalEl('hilos-user-merge-fate-loser-removes')).toBeNull()
    const survivorRadio = document.getElementById(
      'hilos-user-merge-fate-survivor-field',
    )
    expect(survivorRadio?.getAttribute('aria-describedby')).toBe(
      'hilos-user-merge-fate-survivor-removes',
    )
    w1.unmount()
    mounted.splice(mounted.indexOf(w1), 1)

    // 2. Password on both accounts, survivor unconfirmed
    const survivorUnverifiedWorld = userContext(true, {
      detailHasPassword: true,
      detailUnverifiedPasswordAddress: 'alice@example.test',
      candidateHasPassword: true,
    })
    const w2 = mount(HilosUserPage, {
      props: { context: markRaw(survivorUnverifiedWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(
            survivorUnverifiedWorld.context.scopes,
          ),
        },
      },
    })
    mounted.push(w2)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()

    expect(modalEl('hilos-user-merge-fate-survivor-removes')).toBeNull()
    const loserRemoves = modalEl('hilos-user-merge-fate-loser-removes')
    expect(loserRemoves?.textContent).toContain(
      'alice@example.test is not confirmed and will be removed.',
    )
    w2.unmount()
    mounted.splice(mounted.indexOf(w2), 1)

    // 3. Both passwords confirmed
    const confirmedWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    const w3 = mount(HilosUserPage, {
      props: { context: markRaw(confirmedWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(confirmedWorld.context.scopes),
        },
      },
    })
    mounted.push(w3)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()

    expect(modalEl('hilos-user-merge-fate-survivor-removes')).toBeNull()
    expect(modalEl('hilos-user-merge-fate-loser-removes')).toBeNull()
    expect(modalEl('hilos-user-merge-fate-none-removes')).toBeNull()
    w3.unmount()
    mounted.splice(mounted.indexOf(w3), 1)

    // 4. Password only on one account
    const singlePasswordWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: false,
    })
    const w4 = mount(HilosUserPage, {
      props: { context: markRaw(singlePasswordWorld.context) },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(
            singlePasswordWorld.context.scopes,
          ),
        },
      },
    })
    mounted.push(w4)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()

    expect(modalEl('hilos-user-merge-fate-survivor')).toBeNull()
    expect(modalEl('hilos-user-merge-fate-survivor-removes')).toBeNull()
    w4.unmount()
    mounted.splice(mounted.indexOf(w4), 1)
  })

  it('opens on the committed name with save locked and the message line empty', async () => {
    const { context } = userContext()
    await openModal(context)

    expect(nameInput().value).toBe('Alice')
    expect(saveButton().disabled).toBe(true)
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(modalEl('hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', async () => {
    const { context, renameElsewhere } = userContext()
    await openModal(context)

    renameElsewhere('Alicia')
    await nextTick()
    await nextTick()

    expect(nameInput().value).toBe('Alicia')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    await typeDraft('Alicia B')
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, renameElsewhere, sent } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()

    nameInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', async () => {
    const { context, renameElsewhere, sent } = userContext()
    await openModal(context)
    await typeDraft('Mine')

    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(nameInput().value).toBe('Mine')

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('hilos_user_update')
    expect(sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', async () => {
    const { context, renameElsewhere } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(nameInput().value).toBe('Theirs')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', async () => {
    const { context, removeRow } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    removeRow()
    await nextTick()
    await nextTick()

    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(nameInput().value).toBe('Mine')
    expect(modalEl('modal')).not.toBeNull()
  })

  it('waits for the name it sent: Take theirs in flight keeps the modal open until that name lands', async () => {
    const { context, renameElsewhere, sent } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    saveButton().click()
    await nextTick()
    expect(sent.at(-1)?.data).toEqual({ id: 1, name: 'Mine' })

    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()
    modalEl('conflict-accept-theirs')?.click()
    await nextTick()
    expect(nameInput().value).toBe('Theirs')
    expect(modalEl('modal')).not.toBeNull()

    // A third name lands in the untouched form: the draft matches the live name
    // again, but that is not the name this rename sent.
    renameElsewhere('Other')
    await nextTick()
    await nextTick()
    expect(nameInput().value).toBe('Other')
    expect(modalEl('modal')).not.toBeNull()

    renameElsewhere('Mine')
    await nextTick()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })

  it('forgets the name it sent once the rename is refused', async () => {
    const { context, renameElsewhere, failRename } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    saveButton().click()
    await nextTick()

    failRename()
    await nextTick()
    expect(saveButton().disabled).toBe(false)

    renameElsewhere('Mine')
    await nextTick()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
  })

  it('holds the dialog and locks its body while the rename is in flight, and frees the same field after a refusal', async () => {
    const { context, renameElsewhere, failRename, sent } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    const field = nameInput()
    const body = bodyLock()
    const cancel = modalEl('hilos-user-cancel') as HTMLButtonElement
    const close = modalEl('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent.map((entry) => entry.action)).toEqual(['hilos_user_update'])
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    field.form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(sent).toHaveLength(1)

    failRename()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(bodyLock()).toBe(body)
    expect(nameInput()).toBe(field)
    expect(field.value).toBe('Mine')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await typeDraft('Mine again')
    saveButton().click()
    await nextTick()
    expect(sent.at(-1)?.data).toEqual({ id: 1, name: 'Mine again' })
    renameElsewhere('Mine again')
    await nextTick()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })

  it('asks before discarding a changed draft', async () => {
    const { context } = userContext()
    await openModal(context)
    await typeDraft('Mine')

    modalEl('modal-close')?.click()
    await nextTick()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosUserPage name hidden from a viewer of the admin view mode (HIL-1260)', () => {
  it('draws the mark and the person icon, and a rename window with the mark in place of the field', async () => {
    const { context } = userContext()
    context.scopes
      .page()!
      .entities.upsert({ type: 'user', id: 1 }, { name: { _hidden: true } })
    await openModal(context)

    expect(modalEl('hilos-page-head-title')?.textContent?.trim()).toBe('Hidden')
    expect(modalEl('hilos-avatar')?.querySelector('.bi-person')).not.toBeNull()
    expect(modalEl('modal')?.querySelector('.modal-title')?.textContent).toBe(
      'Rename · Hidden',
    )
    expect(nameInput()).toBeNull()
    expect(
      modalEl('modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('hilos-user-cancel')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).toBeNull()
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosUserPage lifecycle', () => {
  it('keeps own-account controls disabled with reasons and follows live rights', async () => {
    const { context } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }

    await change(() => {
      context.scopes.session.data.set('currentUser', { type: 'user', id: 1 })
      context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    })
    expect(find('hilos-user-rights')).not.toBeNull()
    expect(find('hilos-user-access')).not.toBeNull()
    expect((find('hilos-user-admin-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(find('hilos-user-admin-reason')?.textContent).toContain(
      'You cannot remove your own rights',
    )
    expect((find('hilos-user-block-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(find('hilos-user-block-reason')?.textContent).toContain(
      'You cannot block yourself',
    )
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(find('hilos-user-deletion-reason')?.textContent).toContain(
      'Delete your own account from your profile',
    )
    await change(() => {
      context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    })
    expect((find('hilos-user-admin-open') as HTMLButtonElement).disabled).toBe(
      false,
    )
    expect(find('hilos-user-deletion-reason')?.textContent).toContain(
      'Remove the admin rights first',
    )
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: false })
    })
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('names the deletion grace period and follows its live date and cancellation', async () => {
    const { context } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(find('hilos-user-deletion-reason')?.textContent?.trim()).toBe('')
    await change(() => {
      context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    })
    await click('hilos-user-deletion-open')
    expect(find('modal')?.textContent).toContain('After 30 days')
    await click('hilos-user-lifecycle-cancel')
    const effectiveAt = Date.now() + 30 * 86_400_000
    await change(() => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: { deletionEffectiveAt: effectiveAt },
      })
    })
    expect(find('hilos-user-deletion-state')?.textContent).toContain(
      formatCalendarDate(effectiveAt),
    )
    expect(find('hilos-user-deletion-state')?.textContent).toContain(
      '30 days left',
    )
    expect(find('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )
    await change(() => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: { deletionEffectiveAt: null },
      })
    })
    expect(find('hilos-user-deletion-open')?.textContent).toContain(
      'Delete account',
    )
  })

  it('waits for the tracked block reply, keeps a refusal in the modal and closes on success', async () => {
    const { context } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }
    let resolve!: (value: ActionResult) => void
    let reject!: (reason: unknown) => void
    await click('hilos-user-block-open')
    expect(find('modal')?.textContent).toContain('No reason is stored')
    const dispatch = vi
      .spyOn(context.actions, 'dispatch')
      .mockImplementation(() => ({
        requestId: 'lifecycle-test',
        loading: createSignal(false),
        done: new Promise<ActionResult>((yes, no) => {
          resolve = yes
          reject = no
        }),
      }))
    await click('hilos-user-lifecycle-confirm')
    expect(dispatch).toHaveBeenLastCalledWith('hilos_user_block_set', {
      userId: 1,
      block: true,
    })
    expect(
      find('hilos-user-lifecycle-confirm')?.getAttribute('aria-disabled'),
    ).toBe('true')
    expect(
      (find('hilos-user-lifecycle-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    await change(() => {
      find('modal')?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    })
    expect(find('modal')).not.toBeNull()
    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'No longer an administrator',
        ),
      )
    })
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'No longer an administrator',
    )
    expect(find('modal')).not.toBeNull()
    await click('hilos-user-lifecycle-confirm')
    await change(() => {
      resolve({ message: 'Account blocked. Sessions ended: 1' })
    })
    expect(find('hilos-user-lifecycle-confirm')).toBeNull()
  })

  it('closes idle confirmation modals when background data invalidates the choice across all three pairs in both directions', async () => {
    const { context, standingFrame, renameElsewhere } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }
    const initialDispatchCount = vi.spyOn(context.actions, 'dispatch')

    // 1. Admin pair: grant -> admin becomes true
    let dispatches: number
    await click('hilos-user-admin-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_admin_set',
      expect.anything(),
    )

    // 1b. Admin pair: revoke -> admin becomes false
    await click('hilos-user-admin-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: false })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_admin_set',
      expect.anything(),
    )

    // 2. Block pair: block -> standing becomes blocked
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_block_set',
      expect.anything(),
    )

    // 2b. Block pair: unblock -> standing becomes unblocked
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'none', blocked: false }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_block_set',
      expect.anything(),
    )

    // 3. Deletion pair: delete -> deletion scheduled
    await click('hilos-user-deletion-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'deletion_scheduled',
          deletionEffectiveAt: 100_000_000,
        }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_deletion_set',
      expect.anything(),
    )

    // 3b. Deletion pair: cancelDeletion -> deletion cleared
    await click('hilos-user-deletion-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'none',
          deletionEffectiveAt: null,
        }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_deletion_set',
      expect.anything(),
    )

    // Independent update (rename) keeps modal open
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    await change(() => {
      renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })

  it('closes the modal if choice is invalidated during delayed step-up opening', async () => {
    const { context, standingFrame } = userContext(false, { stepUp: 'ask' })
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }

    await click('hilos-user-block-open')
    expect(find('hilos-user-lifecycle-step-up')).not.toBeNull()

    // Standing changes while step-up prompt is open
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).toBeNull()
  })

  it('closes the modal when user row disappears', async () => {
    const { context, removeRow } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    await change(() => {
      removeRow()
    })
    expect(find('modal')).toBeNull()
  })

  it('does not dispatch an invalidated action if clicked between render and submit', async () => {
    const { context, standingFrame } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    const dispatch = vi.spyOn(context.actions, 'dispatch')
    // Simulate invalidation without awaiting watcher settlement
    standingFrame({
      userId: 1,
      accountStanding: standing({ shown: 'blocked', blocked: true }),
    })
    await click('hilos-user-lifecycle-confirm')
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('keeps modal open when standing changes while action is in flight, preserving readable refusal with disabled confirm', async () => {
    const { context, standingFrame, renameElsewhere } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const find = modalEl
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const change = async (fn: () => void) => {
      fn()
      await settle()
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle()
    }
    let reject!: (reason: unknown) => void

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    vi.spyOn(context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'test-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))

    await click('hilos-user-lifecycle-confirm')
    // Standing changes while in flight
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    // Busy=true kept the modal open
    expect(find('modal')).not.toBeNull()

    // Reject with refusal
    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'Operation refused by server',
        ),
      )
    })

    // Modal stays open, showing refusal
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    // Extra tick/render does not dismiss the refusal
    await change(() => {
      renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    // Cancel closes the refusal modal
    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })
})

describe('HilosUserPage confirmation step (HIL-1275)', () => {
  /** Mount the card with the fixture's step-up answer. */
  function mountCard(
    stepUp: 'skip' | 'ask' | 'refused',
    accountMerge = false,
  ): ReturnType<typeof userContext> {
    const world = userContext(accountMerge, { stepUp })
    mounted.push(
      mount(HilosUserPage, {
        props: { context: markRaw(world.context) },
        attachTo: document.body,
        global: {
          provide: { [hilosRouterKey as symbol]: router(world.context.scopes) },
        },
      }),
    )

    return world
  }

  async function click(id: string): Promise<void> {
    modalEl(id)?.click()
    await flushPromises()
    await nextTick()
  }

  async function typePassword(text: string): Promise<void> {
    const input = modalEl('step-up-password') as HTMLInputElement
    input.value = text
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
  }

  it('asks by the merge operation and shows no other account before the step is passed', async () => {
    const world = mountCard('ask', true)
    await nextTick()
    await click('hilos-user-merge-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'merge_accounts' },
    })
    expect(modalEl('modal')?.textContent).toContain("Confirm it's you")
    expect(modalEl('step-up-password')).not.toBeNull()
    expect(modalEl('hilos-user-merge-row-2')).toBeNull()
    expect(modalEl('hilos-user-merge-next')).toBeNull()

    await typePassword('secret')
    await click('hilos-user-merge-step-up-confirm')

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({
      data: { operation: 'merge_accounts', password: 'secret' },
    })
    expect(modalEl('step-up-password')).toBeNull()
    expect(modalEl('hilos-user-merge-row-2')).not.toBeNull()
    expect(modalEl('hilos-user-merge-next')).not.toBeNull()
  })

  it('opens the merge on its first step when no confirmation is needed', async () => {
    const world = mountCard('skip', true)
    await nextTick()
    await click('hilos-user-merge-open')

    expect(world.sent[0]?.action).toBe('hilos_step_up_start')
    expect(modalEl('step-up')).toBeNull()
    expect(modalEl('hilos-user-merge-row-2')).not.toBeNull()
    // A candidate's name and id stay apart in its row: "Bob #2", not "Bob#2".
    expect(
      modalEl('hilos-user-merge-row-2')?.closest('tr')?.textContent,
    ).toContain('Bob #2')
  })

  it('draws a refusal with Cancel alone', async () => {
    mountCard('refused', true)
    await nextTick()
    await click('hilos-user-merge-open')

    expect(modalEl('step-up-error')?.textContent).toContain(STEP_UP_REFUSAL)
    expect(modalEl('hilos-user-merge-cancel')).not.toBeNull()
    expect(modalEl('hilos-user-merge-step-up-confirm')).toBeNull()
    expect(modalEl('hilos-user-merge-next')).toBeNull()
    expect(modalEl('hilos-user-merge-row-2')).toBeNull()
  })

  it('asks before granting rights, then shows the confirmation text; Enter in the field confirms', async () => {
    const world = mountCard('ask')
    await nextTick()
    await click('hilos-user-admin-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'grant_admin' },
    })
    expect(modalEl('hilos-user-lifecycle-step-up-confirm')).not.toBeNull()
    expect(modalEl('hilos-user-lifecycle-confirm')).toBeNull()

    await typePassword('secret')
    modalEl('hilos-user-lifecycle-step-up')?.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await flushPromises()
    await nextTick()

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'grant_admin' } })
    expect(modalEl('step-up')).toBeNull()
    expect(modalEl('hilos-user-lifecycle-confirm')).not.toBeNull()
    expect(modalEl('modal')?.textContent).not.toContain("Confirm it's you")
  })

  it('opens Lift the block without asking the server', async () => {
    const world = mountCard('ask')
    await nextTick()
    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { block: true })
    await flushPromises()
    await nextTick()
    await click('hilos-user-block-open')

    expect(world.sent).toEqual([])
    expect(modalEl('step-up')).toBeNull()
    expect(modalEl('hilos-user-lifecycle-confirm')).not.toBeNull()
  })
})

/** The installation's impersonation settings as the card's first answer carries them. */
function impersonationSettings(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    allowed: true,
    scope: 'act',
    carryAdmin: false,
    blocked: true,
    frozen: true,
    equal: true,
    ...overrides,
  }
}

describe('HilosUserPage impersonation (HIL-1170)', () => {
  /** Mount the card with the fixture's settings and step-up answer. */
  function mountCard(
    options: Parameters<typeof userContext>[1] = {},
  ): ReturnType<typeof userContext> {
    const world = userContext(false, options)
    mounted.push(
      mount(HilosUserPage, {
        props: { context: markRaw(world.context) },
        attachTo: document.body,
        global: {
          provide: { [hilosRouterKey as symbol]: router(world.context.scopes) },
        },
      }),
    )

    return world
  }

  async function settle(): Promise<void> {
    await flushPromises()
    await nextTick()
  }

  async function click(id: string): Promise<void> {
    modalEl(id)?.click()
    await settle()
  }

  afterEach(() => {
    hilosToasts.clear()
  })

  it('draws no section while the card carries no settings or impersonation is off', async () => {
    mountCard()
    await settle()
    expect(modalEl('hilos-user-impersonate-open')).toBeNull()

    for (const wrapper of mounted.splice(0)) wrapper.unmount()
    mountCard({ impersonation: impersonationSettings({ allowed: false }) })
    await settle()
    expect(modalEl('hilos-user-impersonate-open')).toBeNull()
  })

  it('says what the takeover will be by the settings', async () => {
    mountCard({ impersonation: impersonationSettings() })
    await settle()
    const open = modalEl('hilos-user-impersonate-open') as HTMLButtonElement
    const section = open.closest('section')

    expect(section?.querySelector('h2')?.textContent).toBe(
      'Acting as another person',
    )
    expect(section?.querySelector('h3')?.textContent).toBe('Impersonation')
    expect(section?.textContent).toContain('Act as Alice')
    expect(section?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights',
    )
    expect(open.textContent?.trim()).toBe('Impersonate')
    expect(open.disabled).toBe(false)
    expect(modalEl('hilos-user-impersonate-reason')?.textContent).toBe('')

    for (const wrapper of mounted.splice(0)) wrapper.unmount()
    mountCard({ impersonation: impersonationSettings({ scope: 'view' }) })
    await settle()
    expect(
      modalEl('hilos-user-impersonate-open')?.closest('section')?.textContent,
    ).toContain('You will only look: nothing can be changed')
  })

  it('switches the button off with its reason on the own card and on an excluded administrator', async () => {
    const world = mountCard({
      impersonation: impersonationSettings({ equal: false }),
      currentUserId: 1,
    })
    await settle()
    const open = () =>
      modalEl('hilos-user-impersonate-open') as HTMLButtonElement

    expect(open().disabled).toBe(true)
    expect(open().getAttribute('aria-describedby')).toBe(
      'hilos-user-impersonate-reason',
    )
    expect(modalEl('hilos-user-impersonate-reason')?.textContent).toBe(
      'You cannot impersonate yourself',
    )

    world.context.scopes.session.data.set('currentUser', {
      type: 'user',
      id: 99,
    })
    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    await settle()

    expect(open().disabled).toBe(true)
    expect(modalEl('hilos-user-impersonate-reason')?.textContent).toBe(
      'Impersonating another administrator is switched off',
    )
  })

  it('opens the window after the server says no step is needed, and closes it on the takeover', async () => {
    const world = mountCard({ impersonation: impersonationSettings() })
    await settle()
    await click('hilos-user-impersonate-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'impersonate' },
    })
    const modal = modalEl('modal')
    expect(modal?.textContent).toContain('Impersonate · Alice')
    expect(modal?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights.',
    )
    expect(modal?.textContent).toContain(
      'Everything you do in their name is written to the journal as done by you.',
    )

    await click('hilos-user-impersonate-confirm')
    expect(
      world.sent.find((entry) => entry.action === 'hilos_impersonate_start'),
    ).toMatchObject({ data: { targetUserId: 1 } })
    expect(
      modalEl('hilos-user-impersonate-confirm')?.getAttribute('aria-disabled'),
    ).toBe('true')

    world.answerImpersonate()
    await settle()

    expect(modalEl('modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refusal in the window and toasts it', async () => {
    const world = mountCard({ impersonation: impersonationSettings() })
    await settle()
    await click('hilos-user-impersonate-open')
    await click('hilos-user-impersonate-confirm')

    world.answerImpersonate('Impersonating a blocked person is switched off')
    await settle()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-user-impersonate-error')?.textContent).toContain(
      'Impersonating a blocked person is switched off',
    )
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Impersonating a blocked person is switched off']])

    await click('hilos-user-impersonate-cancel')
    expect(modalEl('modal')).toBeNull()
  })

  it('asks for the confirmation step first when the operation is on', async () => {
    const world = mountCard({
      impersonation: impersonationSettings(),
      stepUp: 'ask',
    })
    await settle()
    await click('hilos-user-impersonate-open')

    expect(modalEl('hilos-user-impersonate-step-up')).not.toBeNull()
    expect(modalEl('hilos-user-impersonate-confirm')).toBeNull()

    const input = modalEl('step-up-password') as HTMLInputElement
    input.value = 'secret'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    await click('hilos-user-impersonate-step-up-confirm')

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'impersonate', password: 'secret' } })
    expect(modalEl('hilos-user-impersonate-step-up')).toBeNull()
    expect(modalEl('hilos-user-impersonate-confirm')).not.toBeNull()
  })

  it('updates an open confirmation and closes it when impersonation becomes unavailable', async () => {
    const world = mountCard({ impersonation: impersonationSettings() })
    await settle()
    await click('hilos-user-impersonate-open')
    expect(modalEl('modal')).not.toBeNull()

    // Changing allowed to false via live session policy closes the window and hides the section.
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: false,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle()

    expect(modalEl('modal')).toBeNull()
    expect(modalEl('hilos-user-impersonate-open')).toBeNull()

    // Bringing allowed back to true restores the section.
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle()
    expect(modalEl('hilos-user-impersonate-open')).not.toBeNull()

    // Open the window again; updating the policy to equal=false when target user is an admin closes the window.
    await click('hilos-user-impersonate-open')
    expect(modalEl('modal')).not.toBeNull()

    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: true,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle()
    expect(modalEl('modal')?.textContent).toContain(
      'You will only look: nothing can be changed',
    )

    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: false,
    })
    await settle()

    expect(modalEl('modal')).toBeNull()
    const open = modalEl('hilos-user-impersonate-open') as HTMLButtonElement
    expect(open.disabled).toBe(true)
  })
})

describe('HilosUserPage standing (HIL-945)', () => {
  it('shows one badge for the standing shown and the freeze as a row without a control', async () => {
    const { context, standingFrame } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const settle = async () => {
      await nextTick()
      await flushPromises()
    }
    const badge = () => modalEl('user-standing-badge')

    // No verdict yet: no badge and no freeze row.
    await settle()
    expect(badge()).toBeNull()
    expect(modalEl('hilos-user-frozen-state')).toBeNull()

    context.scopes.page()?.data.set(
      'accountStanding',
      standing({
        shown: 'frozen',
        frozen: true,
        lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
      }),
    )
    await settle()
    expect(badge()?.textContent?.trim()).toBe('Frozen')
    expect(badge()?.classList.contains('text-bg-info')).toBe(true)
    expect(badge()?.querySelector('.bi-snow')).not.toBeNull()
    expect(
      modalEl('hilos-user-frozen-state')
        ?.querySelector('p')
        ?.textContent?.trim(),
    ).toBe('Frozen')
    expect(modalEl('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Terms of use — deadline passed 1 September 2026',
    )
    expect(modalEl('hilos-user-frozen-open')).toBeNull()
    const states = Array.from(
      document.querySelectorAll(
        '[data-id="hilos-user-access"] [data-id$="-state"]',
      ),
    ).map((node) => node.getAttribute('data-id'))
    expect(states).toEqual([
      'hilos-user-block-state',
      'hilos-user-frozen-state',
      'hilos-user-deletion-state',
    ])

    // A live frame about the person moves the badge and the rows together.
    standingFrame({
      userId: 1,
      accountStanding: standing({ shown: 'blocked', blocked: true }),
    })
    await settle()
    expect(badge()?.textContent?.trim()).toBe('Blocked')
    expect(badge()?.classList.contains('text-bg-danger')).toBe(true)
    expect(
      modalEl('hilos-user-block-state')
        ?.querySelector('p')
        ?.textContent?.trim(),
    ).toBe('Blocked')
    expect(modalEl('hilos-user-block-open')?.textContent).toContain(
      'Lift the block',
    )
    expect(modalEl('hilos-user-frozen-state')?.textContent).toContain(
      'Not frozen',
    )

    standingFrame({
      userId: 1,
      accountStanding: standing({
        lapsed: [{ document: 'privacy', deadline: '2026-09-15' }],
      }),
    })
    await settle()
    expect(badge()).toBeNull()
    expect(modalEl('hilos-user-frozen-state')?.textContent).toContain(
      'Past deadline — reminders only',
    )
    expect(modalEl('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Privacy policy — deadline passed 15 September 2026',
    )

    // Hidden from a viewer of the admin view mode: no standing to show.
    standingFrame({
      userId: { _hidden: true },
      accountStanding: { _hidden: true },
    })
    await settle()
    expect(badge()).toBeNull()
    expect(modalEl('hilos-user-frozen-state')).not.toBeNull()
    standingFrame({ userId: 1, accountStanding: { _hidden: true } })
    await settle()
    expect(modalEl('hilos-user-frozen-state')).toBeNull()
  })

  it('reads a scheduled deletion from the verdict', async () => {
    const { context } = userContext()
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    const effectiveAt = Date.now() + 5 * 86_400_000

    context.scopes.page()?.data.set(
      'accountStanding',
      standing({
        shown: 'deletion_scheduled',
        deletionEffectiveAt: effectiveAt,
      }),
    )
    await nextTick()
    await flushPromises()

    expect(modalEl('user-standing-badge')?.textContent?.trim()).toBe(
      'Deletion scheduled',
    )
    expect(
      modalEl('user-standing-badge')?.classList.contains('text-bg-warning'),
    ).toBe(true)
    expect(modalEl('hilos-user-deletion-state')?.textContent).toContain(
      '5 days left',
    )
    expect(modalEl('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )
  })
})

describe('HilosUserPage in the admin view mode', () => {
  const sessionReleases: (() => void)[] = []

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
  })

  function bindSession() {
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
    sessionReleases.push(bindAdminAccess(scopes))

    return {
      scopes,
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

  async function mountViewModePage(context: HilosUsersContext) {
    const wrapper = mount(HilosUserPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()
    await flushPromises()

    return wrapper
  }

  it('a guest opens admin, block and deletion lifecycle dialogs directly and has nothing to send', async () => {
    const { scopes, handshake } = bindSession()
    handshake(null, true)
    const world = userContext(false, { scopes, graceDays: HIDDEN_VALUE })
    await mountViewModePage(world.context)

    for (const key of ['admin', 'block', 'deletion'] as const) {
      const openBtn = modalEl(`hilos-user-${key}-open`) as HTMLButtonElement
      expect(openBtn).not.toBeNull()
      expect(openBtn.disabled).toBe(false)

      openBtn.click()
      await flushPromises()
      await nextTick()

      expect(modalEl('hilos-user-lifecycle-step-up')).toBeNull()
      const confirmBtn = modalEl(
        'hilos-user-lifecycle-confirm',
      ) as HTMLButtonElement
      expect(confirmBtn).not.toBeNull()
      expect(confirmBtn.disabled).toBe(true)
      expect(confirmBtn.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )

      confirmBtn.click()
      await flushPromises()
      expect(world.sent).toHaveLength(0)

      modalEl('hilos-user-lifecycle-cancel')?.click()
      await flushPromises()
      await nextTick()
    }
  })

  it('a guest opens the merge dialog directly, chooses a candidate with hidden fields, and confirm is disabled', async () => {
    const { scopes, handshake } = bindSession()
    handshake(null, true)
    const world = userContext(true, {
      scopes,
      candidateNameHidden: true,
      candidateIdentitiesHidden: true,
    })
    await mountViewModePage(world.context)

    const openBtn = modalEl('hilos-user-merge-open') as HTMLButtonElement
    expect(openBtn).not.toBeNull()
    expect(openBtn.disabled).toBe(false)

    openBtn.click()
    await flushPromises()
    await nextTick()

    expect(modalEl('hilos-user-merge-step-up')).toBeNull()
    expect(world.sent).toHaveLength(0)

    const rowRadio = modalEl('hilos-user-merge-row-2') as HTMLInputElement
    expect(rowRadio).not.toBeNull()
    expect(rowRadio.getAttribute('aria-label')).toBe('Merge #2')
    const row2 = rowRadio.closest('tr')
    expect(row2?.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)

    rowRadio.click()
    await nextTick()

    const nextBtn = modalEl('hilos-user-merge-next') as HTMLButtonElement
    expect(nextBtn).not.toBeNull()
    nextBtn.click()
    await nextTick()

    const summary = modalEl('hilos-user-merge-summary')
    expect(summary?.textContent).toContain('Hidden')

    const confirmBtn = modalEl('hilos-user-merge-confirm') as HTMLButtonElement
    expect(confirmBtn).not.toBeNull()
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmBtn.click()
    await flushPromises()
    expect(world.sent).toHaveLength(0)

    modalEl('hilos-user-merge-cancel')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()
  })

  it('a guest opens the takeover window directly and its confirm is disabled by the mode (HIL-1170)', async () => {
    const { scopes, handshake } = bindSession()
    handshake(null, true)
    const world = userContext(false, {
      scopes,
      impersonation: impersonationSettings(),
    })
    await mountViewModePage(world.context)

    const openBtn = modalEl('hilos-user-impersonate-open') as HTMLButtonElement
    expect(openBtn.disabled).toBe(false)
    openBtn.click()
    await flushPromises()
    await nextTick()

    expect(modalEl('hilos-user-impersonate-step-up')).toBeNull()
    const confirmBtn = modalEl(
      'hilos-user-impersonate-confirm',
    ) as HTMLButtonElement
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    confirmBtn.click()
    await flushPromises()
    expect(world.sent).toHaveLength(0)
  })

  it('a logged-in non-admin on their own card sees block disabled by ownBlockReason without strip text id', async () => {
    const { scopes, handshake } = bindSession()
    handshake({ id: 1, admin: false }, true)
    const world = userContext(false, { scopes, currentUserId: 1 })
    await mountViewModePage(world.context)

    const blockBtn = modalEl('hilos-user-block-open') as HTMLButtonElement
    expect(blockBtn).not.toBeNull()
    expect(blockBtn.disabled).toBe(true)
    expect(blockBtn.getAttribute('aria-describedby')).toBe(
      'hilos-user-block-reason',
    )
    expect(blockBtn.getAttribute('aria-describedby')).not.toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
  })

  it('an admin on a node in view mode retains full access and sends step-up start', async () => {
    const { scopes, handshake } = bindSession()
    handshake({ id: 99, admin: true }, true)
    const world = userContext(false, { scopes, stepUp: 'skip' })
    await mountViewModePage(world.context)

    const adminBtn = modalEl('hilos-user-admin-open') as HTMLButtonElement
    expect(adminBtn).not.toBeNull()
    expect(adminBtn.disabled).toBe(false)

    adminBtn.click()
    await flushPromises()
    await nextTick()

    expect(world.sent[0]?.action).toBe('hilos_step_up_start')
  })
})

describe('HilosUserPage merged account (HIL-1292)', () => {
  /**
   * The standing of an account folded into another one, as the wire carries it.
   *
   * @param facts The facts that differ from a merge into Bob (#9).
   */
  function merged(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      shown: 'merged',
      blocked: true,
      frozen: false,
      deletionEffectiveAt: null,
      lapsed: [],
      window: [],
      mergedInto: 9,
      mergedIntoName: 'Bob',
      ...facts,
    }
  }

  async function settle(): Promise<void> {
    await flushPromises()
    await nextTick()
  }

  it('says where the account went and offers nothing to press', async () => {
    const { context, standingFrame } = userContext(true, {
      impersonation: impersonationSettings(),
    })
    mounted.push(
      mount(HilosUserPage, {
        props: { context: markRaw(context) },
        attachTo: document.body,
        global: {
          provide: { [hilosRouterKey as symbol]: router(context.scopes) },
        },
      }),
    )
    await settle()
    expect(modalEl('hilos-user-edit')).not.toBeNull()
    expect(modalEl('hilos-user-access')).not.toBeNull()
    expect(modalEl('hilos-user-merge-zone')).not.toBeNull()
    expect(modalEl('hilos-user-impersonate-open')).not.toBeNull()

    // The merge lands under the open card: the badge turns, the notice names the
    // survivor, and every control goes - the server refuses them all.
    standingFrame({ userId: 1, accountStanding: merged() })
    await settle()
    const badge = modalEl('user-standing-badge')
    expect(badge?.textContent?.trim()).toBe('Merged')
    expect(badge?.classList.contains('text-bg-secondary')).toBe(true)
    expect(badge?.querySelector('.bi-sign-merge-left')).not.toBeNull()
    const notice = modalEl('hilos-user-merged')
    expect(notice?.textContent).toContain('This account was merged into')
    expect(notice?.textContent).toContain('Bob')
    expect(notice?.textContent).toContain('(#9)')
    const link = modalEl('hilos-user-merged-link')
    expect(link?.getAttribute('href')).toBe('/hilos/user/9')
    expect(link?.textContent).toBe('Open card')
    expect(modalEl('hilos-user-edit')).toBeNull()
    expect(modalEl('hilos-user-rights')).toBeNull()
    expect(modalEl('hilos-user-access')).toBeNull()
    expect(modalEl('hilos-user-merge-zone')).toBeNull()
    expect(modalEl('hilos-user-impersonate-open')).toBeNull()
    expect(modalEl('hilos-user-sessions')).not.toBeNull()

    // A viewer of the admin view mode keeps the number and the way, not the name.
    standingFrame({
      userId: 1,
      accountStanding: merged({ mergedIntoName: { _hidden: true } }),
    })
    await settle()
    expect(
      modalEl('hilos-user-merged')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(modalEl('hilos-user-merged')?.textContent).toContain('(#9)')
    expect(modalEl('hilos-user-merged-link')).not.toBeNull()

    // A chain that leads nowhere: merged, with nobody to point at.
    standingFrame({
      userId: 1,
      accountStanding: merged({ mergedInto: null, mergedIntoName: null }),
    })
    await settle()
    expect(modalEl('hilos-user-merged')?.textContent?.trim()).toBe(
      'This account was merged into another one.',
    )
    expect(modalEl('hilos-user-merged-link')).toBeNull()
  })

  it('closes an open window when the merge lands', async () => {
    const { context, standingFrame } = userContext(true)
    await openModal(context)
    expect(modalEl('modal')).not.toBeNull()

    standingFrame({ userId: 1, accountStanding: merged() })
    await settle()

    expect(modalEl('modal')).toBeNull()
    expect(modalEl('hilos-user-merged')).not.toBeNull()
  })
})

describe('HilosUserPage link to the person in Analytics (HIL-1420)', () => {
  async function settle(): Promise<void> {
    await flushPromises()
    await nextTick()
  }

  /**
   * Mount the card over a router that knows the framework addresses.
   *
   * @param context The card's project context.
   * @param overrides Router members the test replaces.
   */
  function mountCard(
    context: HilosUsersContext,
    overrides: Partial<HilosRouter> = {},
  ): void {
    mounted.push(
      mount(HilosUserPage, {
        props: { context: markRaw(context) },
        attachTo: document.body,
        global: {
          provide: {
            [hilosRouterKey as symbol]: router(context.scopes, {
              resolvePath: resolveHilosPath,
              ...overrides,
            }),
          },
        },
      }),
    )
  }

  it('draws no link before the card says Analytics is served or while it is off', async () => {
    const { context } = userContext()
    mountCard(context)
    await settle()
    expect(modalEl('hilos-user-detail')).not.toBeNull()
    expect(modalEl('hilos-page-head-action')).toBeNull()

    context.scopes.page()?.data.set('analyticsEnabled', false)
    await settle()
    expect(modalEl('hilos-page-head-action')).toBeNull()
  })

  it('opens the person in Analytics from the shared head', async () => {
    const { context } = userContext()
    context.scopes.page()?.data.set('analyticsEnabled', true)
    const navigate = vi.fn()
    mountCard(context, { navigate })
    await settle()

    const link = modalEl('hilos-page-head-action')
    expect(link?.tagName).toBe('A')
    expect(link?.textContent?.trim()).toBe('Actions in Analytics')
    expect(link?.getAttribute('href')).toBe('/hilos/analytics/people/1')
    expect(link?.closest('[data-id="hilos-page-head-block"]')).not.toBeNull()
    link?.click()
    expect(navigate).toHaveBeenCalledWith('/hilos/analytics/people/1')
    expect(modalEl('modal')).toBeNull()

    context.scopes.page()?.data.set('analyticsEnabled', false)
    await settle()
    expect(modalEl('hilos-page-head-action')).toBeNull()
  })

  it('draws no link when the application has no address for the page', async () => {
    const { context } = userContext()
    context.scopes.page()?.data.set('analyticsEnabled', true)
    mountCard(context, { resolvePath: () => undefined })
    await settle()
    expect(modalEl('hilos-user-detail')).not.toBeNull()
    expect(modalEl('hilos-page-head-action')).toBeNull()
  })

  it('keeps the link on a merged card that offers nothing to press', async () => {
    const { context, standingFrame } = userContext(true, {
      impersonation: impersonationSettings(),
    })
    context.scopes.page()?.data.set('analyticsEnabled', true)
    mountCard(context)
    await settle()
    standingFrame({
      userId: 1,
      accountStanding: {
        shown: 'merged',
        blocked: true,
        frozen: false,
        deletionEffectiveAt: null,
        lapsed: [],
        window: [],
        mergedInto: 9,
        mergedIntoName: 'Bob',
      },
    })
    await settle()

    expect(modalEl('hilos-user-merged')).not.toBeNull()
    expect(modalEl('hilos-user-edit')).toBeNull()
    expect(modalEl('hilos-user-access')).toBeNull()
    expect(modalEl('hilos-user-merge-zone')).toBeNull()
    expect(modalEl('hilos-user-impersonate-open')).toBeNull()
    expect(modalEl('hilos-page-head-action')?.getAttribute('href')).toBe(
      '/hilos/analytics/people/1',
    )
  })
})

describe('HilosUserPage presence (HIL-1566)', () => {
  /**
   * The header's presence: the room the twin holds, and the badge drawn over it.
   */
  function presence(): {
    room: HTMLElement | null
    twin: HTMLElement | null
    badge: HTMLElement | null
  } {
    const room = document.querySelector<HTMLElement>(
      '[data-id="hilos-page-head-block"] .hilos-stack.d-inline-grid',
    )

    return {
      room,
      twin: room?.querySelector<HTMLElement>('.badge.invisible') ?? null,
      badge: room?.querySelector<HTMLElement>('.badge:not(.invisible)') ?? null,
    }
  }

  it('says the list word in the header from the first answer through every live change', async () => {
    const { context, standingFrame } = userContext()
    mounted.push(
      mount(HilosUserPage, {
        props: { context: markRaw(context) },
        attachTo: document.body,
        global: {
          provide: { [hilosRouterKey as symbol]: router(context.scopes) },
        },
      }),
    )
    const settle = async (): Promise<void> => {
      await nextTick()
      await flushPromises()
    }
    const connections = (presence: string, onlineSessionCount: number) => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        connections: { presence, onlineSessionCount },
      })
    }
    const sessions = () => modalEl('hilos-user-sessions')?.textContent?.trim()
    await settle()

    expect(presence().badge?.textContent?.trim()).toBe('online')
    expect(presence().badge?.classList.contains('text-bg-success')).toBe(true)
    expect(presence().twin?.textContent?.trim()).toBe('probably offline')
    expect(presence().twin?.getAttribute('aria-hidden')).toBe('true')
    // The twin is the only other badge in the card, and it is not seen.
    expect(
      Array.from(
        document.querySelectorAll('[data-id="hilos-page-head-block"] .badge'),
      ).filter((badge) => !badge.classList.contains('invisible')),
    ).toEqual([presence().badge])

    connections('probably_offline', 17)
    await settle()
    expect(presence().badge?.textContent?.trim()).toBe('probably offline')
    expect(presence().badge?.classList.contains('text-bg-warning')).toBe(true)
    expect(presence().badge?.classList.contains('text-bg-secondary')).toBe(
      false,
    )
    expect(sessions()).toBe('17')
    expect(document.body.textContent).not.toContain('probably_offline')

    // The standing badge stands beside it and does not change it.
    context.scopes
      .page()
      ?.data.set(
        'accountStanding',
        standing({ shown: 'blocked', blocked: true }),
      )
    await settle()
    expect(modalEl('user-standing-badge')?.textContent?.trim()).toBe('Blocked')
    expect(presence().badge?.textContent?.trim()).toBe('probably offline')
    standingFrame({ userId: 1, accountStanding: standing({}) })
    await settle()

    connections('online', 2)
    await settle()
    expect(presence().badge?.textContent?.trim()).toBe('online')
    expect(presence().badge?.classList.contains('text-bg-success')).toBe(true)
    expect(sessions()).toBe('2')

    connections('offline', 0)
    await settle()
    expect(presence().badge?.textContent?.trim()).toBe('offline')
    expect(presence().badge?.classList.contains('text-bg-secondary')).toBe(true)
    expect(presence().twin?.textContent?.trim()).toBe('probably offline')
    expect(sessions()).toBe('0')
  })
})
