// The two-step verification setting's edit modal on the shared row-edit helper
// (HIL-1134): it holds the setting row in focus, reloads a pristine edit when
// the value changes elsewhere and says so, shows the conflict chrome without
// Merge on a changed one (the live value in words) and answers Keep mine / Take
// theirs, and locks save as "Deleted" when the row goes. The two first rows are
// switches (HIL-1303): the first, while on, opens the window that turns the
// factor off instead of moving, and off it dims and locks the rows below it.
import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  createAppPageRouter,
  pageIdentity,
} from '@hilos/core/base'
import { HilosSecondFactorSettingKey } from '@hilos/core/admin/security'
import type {
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'
import type { HilosTwoFactorContext } from '@hilos/core/admin/security'

import HilosSecurity2faPage from './HilosSecurity2faPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const SETTINGS_TABLE = 'hilosSecurityTwoFactor'
const ENABLED = HilosSecondFactorSettingKey.enabled
const FORGET = HilosSecondFactorSettingKey.forgetTrustsOnDisable
const REQUIRED = HilosSecondFactorSettingKey.required
const TRUST_DAYS = HilosSecondFactorSettingKey.trustDays

function router(scopes: ScopeManager): HilosRouter {
  const scope = scopes.page()
  if (!scope) throw new Error('The Security page scope must be open.')
  scope.data.set('pageLabel', 'Two-factor')
  scope.data.set('pageLead', 'Two-step verification settings.')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.SECURITY,
    label: 'Security Center',
    lead: 'Security policies and sign-in.',
    icon: 'bi-shield-lock',
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.SECURITY, label: 'Security Center' },
  ])
  scope.data.set('pageTabs', [
    { page: HilosPages.SECURITY, label: 'Overview' },
    { page: HilosPages.SECURITY_2FA, label: 'Two-factor' },
    {
      page: HilosPages.SECURITY_STEP_UP,
      label: 'Operations that ask for confirmation',
    },
    { page: HilosPages.SECURITY_OAUTH, label: 'OAuth providers' },
    { page: HilosPages.SECURITY_SIGN_IN_METHODS, label: 'Sign-in methods' },
    { page: HilosPages.SECURITY_IMPERSONATION, label: 'Impersonation' },
  ])
  scope.data.set('pageChildren', [])
  const paths = createAppPageRouter({})
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_2FA,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => paths.path(page, params),
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

/** A setting row's slot, with the given value. */
function settingSlot(rowKey: string, value: string): Record<string, unknown> {
  return {
    rowKey,
    value,
    defaultValue:
      rowKey === REQUIRED
        ? 'none'
        : rowKey === ENABLED || rowKey === FORGET
          ? 'true'
          : '30',
  }
}

/** A setting row's slot hidden from a viewer of the admin view mode. */
function hiddenSlot(rowKey: string): Record<string, unknown> {
  return { rowKey, value: { _hidden: true }, defaultValue: { _hidden: true } }
}

function seededContext(
  hiddenValues = false,
  enabled = 'true',
): {
  context: HilosTwoFactorContext
  pushUpdate: (rowKey: string, value: string) => void
  pushRemove: (rowKey: string) => void
  answer: (
    outcome: 'success' | 'fail',
    errorCode?: string,
    reply?: unknown,
  ) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>([
    [
      ENABLED,
      hiddenValues ? hiddenSlot(ENABLED) : settingSlot(ENABLED, enabled),
    ],
    [FORGET, hiddenValues ? hiddenSlot(FORGET) : settingSlot(FORGET, 'true')],
    [
      REQUIRED,
      hiddenValues
        ? {
            rowKey: REQUIRED,
            value: { _hidden: true },
            defaultValue: { _hidden: true },
          }
        : settingSlot(REQUIRED, 'admins'),
    ],
    [
      TRUST_DAYS,
      hiddenValues
        ? {
            rowKey: TRUST_DAYS,
            value: { _hidden: true },
            defaultValue: { _hidden: true },
          }
        : settingSlot(TRUST_DAYS, '30'),
    ],
  ])
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_2FA)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === SETTINGS_TABLE
        ? [...settings].map(([rowKey, setting]) => ({
            rowKey,
            slots: { setting },
          }))
        : []
    const data = {
      page: HilosPages.SECURITY_2FA,
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
          page: HilosPages.SECURITY_2FA,
          tableKey: SETTINGS_TABLE,
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
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(rowKey: string, value: string): void {
      const setting = settingSlot(rowKey, value)
      settings = new Map(settings).set(rowKey, setting)
      pushDelta({
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { setting } },
      })
    },
    pushRemove(rowKey: string): void {
      settings = new Map(settings)
      settings.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
    },
    answer(
      outcome: 'success' | 'fail',
      errorCode?: string,
      reply?: unknown,
    ): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The setting refused the write.',
          ...(errorCode !== undefined ? { errorCode } : {}),
          ...(reply !== undefined ? { reply } : {}),
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

function valueInput(): HTMLInputElement | HTMLSelectElement {
  return modalEl('hilos-2fa-input') as HTMLInputElement | HTMLSelectElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-2fa-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return modalEl('hilos-2fa-edit-notice')
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
  input.dispatchEvent(
    new Event(input instanceof HTMLSelectElement ? 'change' : 'input', {
      bubbles: true,
    }),
  )
  await nextTick()
}

/**
 * Mount the page and open the modal of one setting. The row's controls stand
 * in the document twice — the table and the narrow-screen card — so the button
 * is looked up through the table.
 */
async function openModal(
  context: HilosTwoFactorContext,
  rowKey: string,
): Promise<void> {
  const wrapper = mount(HilosSecurity2faPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router(context.scopes) } },
  })
  mounted.push(wrapper)
  await nextTick()
  ;(
    document.querySelector(
      `table [data-id="hilos-2fa-edit-${rowKey}"]`,
    ) as HTMLElement
  ).click()
  await nextTick()
}

/** Let a live frame reach the modal. */
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
}

describe('HilosSecurity2faPage setting modal', () => {
  it('opens on the live value with save locked, the message line empty, and the row in focus', async () => {
    const { context, focus } = seededContext()
    await openModal(context, TRUST_DAYS)

    expect(valueInput().value).toBe('30')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushUpdate(REQUIRED, 'none')
    await settle()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext()
    await openModal(context, TRUST_DAYS)

    pushUpdate(TRUST_DAYS, '14')
    await settle()

    expect(valueInput().value).toBe('14')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict in words, without Merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)

    pushUpdate(REQUIRED, 'none')
    await settle()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "Nobody"')
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })
  })

  it('Take theirs puts the live value in, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushUpdate(REQUIRED, 'none')
    await settle()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('none')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', async () => {
    const { context, pushRemove, focus, sent } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushRemove(REQUIRED)
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('everyone')
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
    expect(valueInput().value).toBe('everyone')
    expect(focus).toEqual([REQUIRED])
    modalEl('modal-confirm-cancel')?.click()
    await nextTick()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(modalEl('modal')).not.toBeNull()
    expect(valueInput()).toBe(field)
    expect(valueInput().value).toBe('everyone')
    expect(focus).toEqual([REQUIRED])

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()
    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([REQUIRED, ''])
    expect(sent).toHaveLength(0)
  })

  it('asks before discarding a changed draft, and closes a pristine one on Save without a request', async () => {
    const { context, sent } = seededContext()
    await openModal(context, TRUST_DAYS)
    await typeDraft('7')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()
    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    ;(
      document.querySelector(
        `table [data-id="hilos-2fa-edit-${TRUST_DAYS}"]`,
      ) as HTMLElement
    ).click()
    await nextTick()
    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(sent).toHaveLength(0)
    expect(modalEl('modal')).toBeNull()
  })

  it('holds the dialog and locks its body while the save is in flight, and frees the same field after a refusal', async () => {
    const { context, sent, answer, focus } = seededContext()
    await openModal(context, TRUST_DAYS)
    await typeDraft('7')
    const field = valueInput()
    const body = bodyLock()
    const cancel = cancelButton()
    const close = modalEl('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toMatchObject({ key: TRUST_DAYS, value: '7' })
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])

    answer('fail')
    await flushPromises()
    expect(modalEl('modal')).not.toBeNull()
    expect(bodyLock()).toBe(body)
    expect(valueInput()).toBe(field)
    expect(field.value).toBe('7')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await typeDraft('14')
    saveButton().click()
    await nextTick()
    expect(sent[1]?.payload).toMatchObject({ key: TRUST_DAYS, value: '14' })
    answer('success')
    await flushPromises()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([TRUST_DAYS, ''])
  })
})

describe('HilosSecurity2faPage', () => {
  it('no longer carries the operations that ask for confirmation (HIL-1204)', async () => {
    const { context } = seededContext()
    const wrapper = mount(HilosSecurity2faPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router(context.scopes) },
      },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(wrapper.find('[data-id="hilos-step-up-table"]').exists()).toBe(false)
  })
})

/** Mount the page over a context. */
async function mountPage(context: HilosTwoFactorContext): Promise<void> {
  const wrapper = mount(HilosSecurity2faPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router(context.scopes) } },
  })
  mounted.push(wrapper)
  await nextTick()
}

/** A row's control as the table draws it (the card draws it once more). */
function inTable<T extends HTMLElement>(id: string): T {
  return document.querySelector(`table [data-id="${id}"]`) as T
}

/** Let an answered action reach the view. */
async function replied(): Promise<void> {
  await flushPromises()
  await nextTick()
}

/** Click the first switch and answer the opening of its step. */
async function openDisable(
  world: ReturnType<typeof seededContext>,
  required: boolean,
): Promise<void> {
  inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).click()
  await nextTick()
  world.answer('success', undefined, {
    required,
    purpose: 'turn the second factor off',
    method: 'password',
  })
  await replied()
}

describe('HilosSecurity2faPage switches', () => {
  it('draws the two first rows as switches without a pencil, the rest with one', async () => {
    await mountPage(seededContext().context)

    expect(document.body.querySelectorAll('h1')).toHaveLength(1)
    expect(document.body.querySelector('h1')?.textContent?.trim()).toBe(
      'Security Center',
    )
    const tabs = document.body.querySelector(
      'nav[aria-label="Security Center pages"]',
    )
    expect(tabs?.querySelectorAll('a')).toHaveLength(6)
    expect(
      tabs?.querySelector('[aria-current="page"]')?.textContent?.trim(),
    ).toBe('Two-factor')
    expect(document.body.textContent).toContain(
      'Two-step verification settings.',
    )
    expect(
      document.body.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
    const table = document.body.querySelector('table')
    expect(table?.getAttribute('aria-labelledby')).toBe(
      document.body.querySelector('h1')?.id,
    )

    const first = inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`)
    expect(first.checked).toBe(true)
    expect(first.getAttribute('aria-label')).toBe('Two-factor enabled')
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).checked,
    ).toBe(true)
    expect(inTable(`hilos-2fa-edit-${ENABLED}`)).toBeNull()
    expect(inTable(`hilos-2fa-edit-${FORGET}`)).toBeNull()
    expect(inTable(`hilos-2fa-value-${ENABLED}`)).toBeNull()
    expect(inTable(`hilos-2fa-edit-${REQUIRED}`)).not.toBeNull()
  })

  it('sets the second switch by one write and moves it only with the row', async () => {
    const world = seededContext()
    await mountPage(world.context)

    const forget = inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`)
    forget.click()
    await nextTick()
    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'security_2fa_switch_set',
        payload: { key: FORGET, enabled: false },
      }),
    ])
    expect(forget.checked).toBe(true)

    world.answer('success')
    world.pushUpdate(FORGET, 'false')
    await replied()
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).checked,
    ).toBe(false)
  })

  it('turns the factor back on by one write, with no window', async () => {
    const world = seededContext(false, 'false')
    await mountPage(world.context)

    inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).click()
    await nextTick()

    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'security_2fa_switch_set',
        payload: { key: ENABLED, enabled: true },
      }),
    ])
    expect(modalEl('hilos-2fa-disable-window')).toBeNull()
  })

  it('dims the rows below the first and locks their pencils and the second switch while off', async () => {
    const world = seededContext(false, 'false')
    await mountPage(world.context)

    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).disabled,
    ).toBe(false)
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(true)
    expect(
      inTable<HTMLButtonElement>(`hilos-2fa-edit-${REQUIRED}`).disabled,
    ).toBe(true)
    expect(
      inTable(`hilos-2fa-value-${TRUST_DAYS}`).classList.contains('opacity-50'),
    ).toBe(true)

    world.pushUpdate(ENABLED, 'true')
    await replied()
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(false)
    expect(
      inTable<HTMLButtonElement>(`hilos-2fa-edit-${REQUIRED}`).disabled,
    ).toBe(false)
    expect(
      inTable(`hilos-2fa-value-${TRUST_DAYS}`).classList.contains('opacity-50'),
    ).toBe(false)
  })
})

describe('HilosSecurity2faPage turning the factor off', () => {
  it("opens the window instead of moving the switch: Confirm it's you, then what it does and Turn off", async () => {
    const world = seededContext()
    await mountPage(world.context)
    await openDisable(world, true)

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      payload: { operation: 'disable_second_factor' },
    })
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).checked,
    ).toBe(true)
    expect(modalEl('modal')?.textContent).toContain("Confirm it's you")
    const password = modalEl('step-up-password') as HTMLInputElement
    password.value = 'secret'
    password.dispatchEvent(new Event('input'))
    modalEl('hilos-2fa-disable-step-up-confirm')?.click()
    await nextTick()
    expect(world.sent[1]).toMatchObject({
      action: 'hilos_step_up_confirm',
      payload: { operation: 'disable_second_factor', password: 'secret' },
    })

    world.answer('success')
    await replied()
    expect(modalEl('modal')?.textContent).toContain('Turn two-factor off')
    const lines = Array.from(
      document.querySelectorAll(
        '[data-id="hilos-2fa-disable-consequences"] li',
      ),
    ).map((item) => item.textContent?.trim())
    expect(lines).toHaveLength(6)
    expect(lines[2]).toBe(
      'Trusted browsers are forgotten: once it is back on, they ask for the code again.',
    )

    modalEl('hilos-2fa-disable-confirm')?.click()
    await nextTick()
    expect(world.sent[2]).toMatchObject({
      action: 'security_2fa_switch_set',
      payload: { key: ENABLED, enabled: false },
    })
    world.answer('success')
    await replied()
    expect(modalEl('hilos-2fa-disable-window')).toBeNull()
  })

  it('keeps the window open with the refusal above the buttons', async () => {
    const world = seededContext()
    await mountPage(world.context)
    await openDisable(world, false)

    expect(modalEl('hilos-2fa-disable-refusal')).toBeNull()
    modalEl('hilos-2fa-disable-confirm')?.click()
    await nextTick()
    world.answer('fail')
    await replied()

    expect(modalEl('hilos-2fa-disable-refusal')?.textContent).toContain(
      'The setting refused the write.',
    )
    expect(modalEl('hilos-2fa-disable-confirm')).not.toBeNull()
  })

  it('says why the step could not open, with Cancel alone', async () => {
    const world = seededContext()
    await mountPage(world.context)
    inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).click()
    await nextTick()
    world.answer('fail')
    await replied()

    expect(modalEl('hilos-2fa-disable-refusal')?.textContent).toContain(
      'The setting refused the write.',
    )
    expect(modalEl('hilos-2fa-disable-confirm')).toBeNull()
    expect(modalEl('hilos-2fa-disable-step-up-confirm')).toBeNull()
    modalEl('hilos-2fa-disable-cancel')?.click()
    await nextTick()
    expect(modalEl('hilos-2fa-disable-window')).toBeNull()
  })

  it('closes by itself once the first row says off, whoever wrote it', async () => {
    const world = seededContext()
    await mountPage(world.context)
    await openDisable(world, false)
    expect(modalEl('hilos-2fa-disable-window')).not.toBeNull()

    world.pushUpdate(ENABLED, 'false')
    await replied()

    expect(modalEl('hilos-2fa-disable-window')).toBeNull()
  })
})

describe('HilosSecurity2faPage in the admin view mode', () => {
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

  it('a viewer opens a setting edit, sees the hidden mark instead of an input, and closes via Cancel', async () => {
    const { context, sent } = seededContext(true)
    bindSession(context.scopes).handshake(null, true)
    await openModal(context, REQUIRED)

    expect(modalEl('hilos-2fa-input')).toBeNull()
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

    expect(modalEl('modal-confirm-discard')).toBeNull()
    expect(modalEl('modal')).toBeNull()
  })

  it('a viewer finds both switches locked, and the window does not open', async () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake(null, true)
    await mountPage(context)

    const first = inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`)
    expect(first.disabled).toBe(true)
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(true)
    first.click()
    await nextTick()
    expect(sent).toEqual([])
    expect(modalEl('hilos-2fa-disable-window')).toBeNull()
  })

  it('an admin on a node in the mode has Save active after typing a draft', async () => {
    const { context } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    await openModal(context, REQUIRED)

    await typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()
  })
})
