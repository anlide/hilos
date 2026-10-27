import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindSessionScope,
  createAppPageRouter,
  createSignal,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  ScopeManager,
} from '@hilos/core/base'
import type {
  Hideable,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  TableViewportDescriptor,
} from '@hilos/core/base'
import type { HilosSettingsContext } from '@hilos/core/admin/settings'

import HilosSettingsPage from './HilosSettingsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const SETTINGS_IDENTITY: HilosPageIdentity = {
  label: 'Settings',
  lead: 'Application setting catalog: catalog defaults, overrides, and references.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.SETTINGS, label: 'Settings' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.SETTINGS,
    label: 'Settings',
    lead: 'Application setting catalog: catalog defaults, overrides, and references.',
    icon: 'bi-sliders',
  },
  pageTabs: [{ page: HilosPages.SETTINGS, label: 'Overview' }],
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SETTINGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/settings'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: createAppPageRouter({}).path,
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

interface SettingSlot {
  key: string
  type: string
  value: Hideable<string | null>
  overrideValue: Hideable<string | null>
  defaultValue: Hideable<string | null>
  defaultReferenceKey: Hideable<string | null>
  valueSource: string
}

function slot(
  over: Partial<SettingSlot> & { key: string; valueSource: string },
): SettingSlot {
  return {
    type: 'string',
    value: 'v',
    overrideValue: null,
    defaultValue: 'd',
    defaultReferenceKey: null,
    ...over,
  }
}

function seededContext(initial: SettingSlot[]): {
  context: HilosSettingsContext
  pushUpdate: (next: SettingSlot) => void
  pushRemove: (key: string) => void
  pushLeave: (next: SettingSlot) => void
  answer: (outcome: 'success' | 'fail') => void
  disconnect: () => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SETTINGS)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (
    page: string = HilosPages.SETTINGS,
    tableKey: string = 'settings',
    descriptor?: TableViewportDescriptor,
  ): void => {
    const search = String(descriptor?.filter.search ?? '')
    const shown = rows.filter((row) => row.key.includes(search))
    const data = {
      page,
      tableKey,
      requestId: descriptor?.requestId,
      rows: shown.map((settings) => ({
        rowKey: settings.key,
        slots: { settings },
      })),
      totalCount: shown.length,
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

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: TableViewportDescriptor,
    ): boolean {
      serveWindow(page, tableKey, descriptor)

      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
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
  const stateListeners = new Set<(state: 'disconnected') => void>()
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
      if (event === 'state') {
        const stateListener = listener as unknown as (
          state: 'disconnected',
        ) => void
        stateListeners.add(stateListener)

        return () => stateListeners.delete(stateListener)
      }
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection: connection as unknown as HilosSettingsContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(next: SettingSlot): void {
      rows = rows.map((row) => (row.key === next.key ? next : row))
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_updated',
            rowKey: next.key,
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
    },
    pushRemove(key: string): void {
      rows = rows.filter((row) => row.key !== key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: key,
            reason: 'deleted',
          },
        })
      }
    },
    // The row left this tab's window alive — out of its search, past its edge — and the frame
    // that takes it off the screen carries the row for the dialog holding it in focus.
    pushLeave(next: SettingSlot): void {
      rows = rows.filter((row) => row.key !== next.key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: next.key,
            reason: 'left_set',
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
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
          reason: 'The setting refused the reset.',
        })
      }
    },
    disconnect(): void {
      for (const listener of stateListeners) {
        listener('disconnected')
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
  vi.useRealTimers()
  vi.restoreAllMocks()
})

async function mountPage(
  context: HilosSettingsContext,
  navigation: HilosRouter = router(SETTINGS_IDENTITY),
) {
  const wrapper = mount(HilosSettingsPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

// The row's controls stand in the document twice — once in the table and once in the
// card the same row becomes on a narrow screen — so the button is looked up through
// the table, which says which of the two is clicked.
function editButton(key: string): HTMLElement {
  return document.querySelector(
    `table [data-id="hilos-settings-edit-${key}"]`,
  ) as HTMLElement
}

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

async function type(value: string): Promise<void> {
  const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await settle()
}

// Enter in the edit dialog: the submit of its form.
function submitEdit(): void {
  document
    .querySelector('[data-id="modal"] form')
    ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}

function spinner(save: HTMLButtonElement): Element | null {
  return save.querySelector('[data-id="loading-button-spinner"]')
}

// The fieldset the modal stands its body in; a save in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the dialog: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  modalEl('modal-close')?.click()
  modalEl('hilos-settings-edit-cancel')?.click()
  modalEl('modal')?.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modalEl('modal')?.click()
}

describe('HilosSettingsPage', () => {
  it('replaces the section skeleton with the adopted Settings head and names the live table', async () => {
    const { context } = seededContext([
      slot({ key: 'site_name', valueSource: 'default' }),
    ])
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const navigation = { ...router(undefined), pageIdentity: identity }
    const wrapper = await mountPage(context, navigation)

    const skeleton = wrapper.get('[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.attributes('aria-hidden')).toBe('true')
    expect(skeleton.find('.rounded-circle').exists()).toBe(true)
    expect(skeleton.find('.rounded-3').exists()).toBe(false)
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-head-block"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(false)
    expect(wrapper.find('.bi-sliders').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Settings')
    expect(wrapper.text()).not.toContain(SETTINGS_IDENTITY.lead)

    identity.set(SETTINGS_IDENTITY)
    await nextTick()

    expect(wrapper.findAll('[data-id="hilos-page-head"]')).toHaveLength(1)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    const heading = wrapper.get('h1')
    expect(heading.attributes('data-id')).toBe('hilos-page-head-title')
    expect(heading.text()).toBe('Settings')
    const block = wrapper.get('[data-id="hilos-page-head-block"]')
    const circle = wrapper.get(
      '[data-id="hilos-page-head-block"] > .rounded-circle',
    )
    expect(circle.attributes('aria-hidden')).toBe('true')
    expect(circle.get('i').classes()).toEqual(['bi', 'bi-sliders'])
    expect(block.findAll('p')).toHaveLength(1)
    expect(block.get('p').text()).toBe(
      'Application setting catalog: catalog defaults, overrides, and references.',
    )
    expect(wrapper.text().split(SETTINGS_IDENTITY.lead)).toHaveLength(2)

    const crumbs = wrapper.get('[data-id="hilos-breadcrumb"]')
    expect(crumbs.attributes('aria-label')).toBe('Breadcrumb')
    const items = crumbs.findAll('li')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toBe('Hilos')
    expect(items[0]!.get('a').attributes('href')).toBe('/hilos')
    expect(items[0]!.attributes('aria-current')).toBeUndefined()
    expect(items[1]!.text()).toBe('Settings')
    expect(items[1]!.attributes('aria-current')).toBe('page')
    expect(items[1]!.find('a').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
    expect(
      wrapper
        .get('[data-id="hilos-page-head"]')
        .element.nextElementSibling?.getAttribute('data-id'),
    ).toBe('hilos-viewport-table')

    const headingId = heading.attributes('id')
    expect(headingId).toBeTruthy()
    expect(document.getElementById(headingId!)).toBe(heading.element)
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(headingId)
    expect(
      wrapper
        .get('[data-id="hilos-table-cards"] [role="list"]')
        .attributes('aria-labelledby'),
    ).toBe(headingId)
    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(1)
    expect(wrapper.get('table').text()).toContain('site_name')
  })

  it('renders a row per setting with its key', async () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const wrapper = await mountPage(context)

    expect(wrapper.findAll('[data-id^="hilos-table-row-"]').length).toBe(1)
    expect(wrapper.text()).toContain('site_name')
  })

  it('opens the edit dialog from a row action', async () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    expect(modalEl('modal')).toBeNull()
    editButton('site_name').click()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    // Nothing happened elsewhere yet: the message line stands empty, its room
    // held by the twin, so a message later moves nothing.
    expect(modalEl('hilos-settings-edit-notice')).toBeNull()
    expect(modalEl('hilos-settings-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    expect(input.value).toBe('Hilos')

    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Elsewhere',
        overrideValue: 'Elsewhere',
      }),
    )
    await nextTick()
    await nextTick()

    expect(input.value).toBe('Elsewhere')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)

    // The person types over the taken value: the note about it goes out.
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(modalEl('hilos-settings-edit-notice')).toBeNull()
  })

  it('leaves the effective value under the switch after a reset elsewhere', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
        defaultValue: 'Default',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()

    // The other side reset the key: the switch goes off and the dialog says so.
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'default',
        value: 'Default',
        overrideValue: null,
        defaultValue: 'Default',
      }),
    )
    await nextTick()
    await nextTick()
    const custom = modalEl('hilos-settings-edit-custom') as HTMLInputElement
    expect(custom.checked).toBe(false)
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )

    // Turning the switch back on starts from the value now in effect, not from
    // the override the other side just removed.
    custom.click()
    await nextTick()
    expect(
      (modalEl('hilos-settings-edit-value') as HTMLInputElement).value,
    ).toBe('Default')
  })

  it('surfaces a conflict on a dirty edit and hides merge', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()

    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(modalEl('conflict-merge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    input.form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('Keep mine unlocks save on a dirty conflict', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('Take theirs sets the field to the live value and locks save', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()
    expect(input.value).toBe('Theirs')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('locks save and names the gone row when it is removed under the modal', async () => {
    const { context, pushRemove } = seededContext([
      slot({
        key: 'legacy',
        valueSource: 'orphan',
        value: 'x',
        overrideValue: 'x',
        defaultValue: null,
      }),
    ])
    await mountPage(context)
    editButton('legacy').click()
    await nextTick()
    pushRemove('legacy')
    await nextTick()
    await nextTick()

    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.textContent?.trim()).toBe('Deleted')
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', async () => {
    const { context, pushLeave } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    // The other tab's save took the row out of this tab's search: the screen gates the
    // departure, the dialog holding the row in focus reads the body off the same frame.
    pushLeave(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    expect(input.value).toBe('Theirs')
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.textContent?.trim()).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', async () => {
    const { context, focus } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    expect(focus).toEqual(['site_name'])

    modalEl('modal-close')?.click()
    await nextTick()
    expect(focus).toEqual(['site_name', ''])
  })

  // The production LoadingButton and ActionLifecycle together: the button's
  // own 300 ms timer is the only delay, so the spinner never waits for the
  // lifecycle's deferred loading on top of it.
  it('locks Save at once on the first send and greys it only with its spinner at 300 ms', async () => {
    vi.useFakeTimers()
    const { context, sent, answer } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(false)
    save.click()
    await settle()
    expect(sent.map((entry) => entry.action)).toEqual(['setting_add'])
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(save.classList.contains('disabled')).toBe(false)
    save.click()
    submitEdit()
    await settle()
    expect(sent).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(299)
    await settle()
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(save.classList.contains('disabled')).toBe(false)
    expect(spinner(save)).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    await settle()
    expect(spinner(save)).not.toBeNull()
    expect(save.classList.contains('disabled')).toBe(true)
    expect(save.disabled).toBe(false)
    save.click()
    submitEdit()
    await settle()
    expect(sent).toHaveLength(1)
    answer('success')
    await settle()
    expect(modalEl('modal')).toBeNull()
  })

  it('holds the dialog and locks its body while the save is in flight, and frees the same field after a refusal', async () => {
    const { context, sent, answer, focus } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    const field = modalEl('hilos-settings-edit-value') as HTMLInputElement
    const body = bodyLock()
    const cancel = modalEl('hilos-settings-edit-cancel') as HTMLButtonElement
    const close = modalEl('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    modalEl('hilos-settings-edit-save')?.click()
    await settle()
    expect(sent.map((entry) => entry.action)).toEqual(['setting_add'])
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await settle()
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(focus).toEqual(['site_name'])

    answer('fail')
    await settle()
    expect(modalEl('modal')).not.toBeNull()
    expect(bodyLock()).toBe(body)
    expect(modalEl('hilos-settings-edit-value')).toBe(field)
    expect(field.value).toBe('Mine')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await type('Mine again')
    modalEl('hilos-settings-edit-save')?.click()
    await settle()
    expect(sent[1]?.payload).toMatchObject({
      key: 'site_name',
      value: 'Mine again',
    })
    answer('success')
    await settle()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual(['site_name', ''])
  })

  it('turns Save off at once for a draft with nothing to save', async () => {
    vi.useFakeTimers()
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    await type('Hilos')
    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.getAttribute('aria-busy')).toBeNull()
    save.click()
    submitEdit()
    await vi.advanceTimersByTimeAsync(300)
    await settle()
    expect(spinner(save)).toBeNull()
    expect(sent).toHaveLength(0)
  })
})

describe('HilosSettingsPage typed edit and refusals', () => {
  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: '1',
    overrideValue: '1',
    defaultValue: '0',
  })

  function valueInput(): HTMLInputElement {
    return modalEl('hilos-settings-edit-value') as HTMLInputElement
  }

  function customSwitch(): HTMLInputElement {
    return modalEl('hilos-settings-edit-custom') as HTMLInputElement
  }

  function expectInvalid(input: HTMLInputElement, invalid: boolean): void {
    expect(input.classList.contains('is-invalid')).toBe(invalid)
    expect(input.getAttribute('aria-invalid')).toBe(invalid ? 'true' : null)
    expect(input.getAttribute('aria-describedby')).toBe(
      invalid ? 'hilos-settings-edit-error' : null,
    )
  }

  it.each([
    ['string', 'text', null],
    ['integer', 'number', null],
    ['float', 'number', 'any'],
    ['boolean', 'checkbox', null],
  ] as const)(
    'shows the server type %s beside the existing title',
    async (type, inputType, step) => {
      const key = 'a_very_long_setting_key_'.repeat(8)
      const { context } = seededContext([{ ...CUSTOM, key, type }])
      await mountPage(context)
      editButton(key).click()
      await settle()

      const badge = modalEl('hilos-settings-edit-type')!
      expect(badge.textContent).toBe(type)
      expect(badge.classList.contains('font-monospace')).toBe(true)
      expect(badge.classList.contains('flex-shrink-0')).toBe(true)
      const header = badge.parentElement!
      expect(header.classList.contains('flex-grow-1')).toBe(true)
      expect(header.classList.contains('min-w-0')).toBe(true)
      const title = header.querySelector('h5')!
      expect(title.textContent).toBe(`Edit · ${key}`)
      expect(title.classList.contains('text-break')).toBe(true)
      expect(
        header.parentElement?.querySelector('[data-id="modal-close"]'),
      ).not.toBeNull()
      expect(header.contains(modalEl('modal-close'))).toBe(false)
      expect(valueInput().type).toBe(inputType)
      expect(valueInput().getAttribute('step')).toBe(step)
    },
  )

  it('omits a missing server type without inferring it from the value', async () => {
    const { context } = seededContext([{ ...CUSTOM, type: '' }])
    await mountPage(context)
    editButton('site_name').click()
    await settle()

    expect(modalEl('hilos-settings-edit-type')).toBeNull()
    expect(valueInput().type).toBe('text')
  })

  it.each(['string', 'integer', 'float'])(
    'keeps the same %s input and its zero draft through custom off and on',
    async (settingType) => {
      const { context, sent, answer } = seededContext([
        {
          ...CUSTOM,
          type: settingType,
          valueSource: 'default',
          value: '5',
          overrideValue: null,
          defaultValue: '5',
        },
      ])
      await mountPage(context)
      editButton('site_name').click()
      await settle()
      const input = valueInput()
      const group = input.parentElement!
      const notice = modalEl('hilos-settings-edit-notice-idle')
      expect(input.value).toBe('5')
      expect(input.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(notice).not.toBeNull()

      customSwitch().click()
      await settle()
      expect(valueInput()).toBe(input)
      expect(input.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      await type('0')
      customSwitch().click()
      await settle()
      expect(valueInput()).toBe(input)
      expect(input.value).toBe('0')
      expect(input.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(modalEl('hilos-settings-edit-notice-idle')).toBe(notice)

      customSwitch().click()
      await settle()
      expect(valueInput()).toBe(input)
      expect(input.value).toBe('0')
      expect(input.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      submitEdit()
      await settle()
      expect(sent[0]).toMatchObject({
        action: 'setting_add',
        payload: { key: 'site_name', value: '0' },
      })
      answer('success')
      await settle()
      expect(modalEl('modal')).toBeNull()
    },
  )

  it.each(['default', 'override'])(
    'keeps the same boolean input and false draft through off and on from %s',
    async (valueSource) => {
      const { context, sent, answer } = seededContext([
        {
          ...CUSTOM,
          type: 'boolean',
          valueSource,
          value: '0',
          overrideValue: valueSource === 'default' ? null : '0',
        },
      ])
      await mountPage(context)
      editButton('site_name').click()
      await settle()
      const input = valueInput()
      const group = input.parentElement!.parentElement!
      expect(input.checked).toBe(false)
      expect(input.disabled).toBe(valueSource === 'default')
      expect(group.classList.contains('opacity-50')).toBe(
        valueSource === 'default',
      )
      if (valueSource === 'default') {
        customSwitch().click()
        await settle()
      }
      input.click()
      await settle()
      expect(input.checked).toBe(true)
      input.click()
      await settle()
      expect(input.checked).toBe(false)

      customSwitch().click()
      await settle()
      expect(valueInput()).toBe(input)
      expect(input.disabled).toBe(true)
      expect(input.checked).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(modalEl('hilos-settings-edit-notice-idle')).not.toBeNull()
      customSwitch().click()
      await settle()
      expect(valueInput()).toBe(input)
      expect(input.disabled).toBe(false)
      expect(input.checked).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      if (valueSource === 'default') {
        submitEdit()
        await settle()
        expect(sent[0]).toMatchObject({
          action: 'setting_add',
          payload: { key: 'site_name', value: '0' },
        })
        answer('success')
        await settle()
      }
    },
  )

  it.each(['string', 'integer', 'float', 'boolean'])(
    'marks only a real refusal of the custom %s value and clears on run and change',
    async (settingType) => {
      const { context, sent, answer } = seededContext([
        { ...CUSTOM, type: settingType },
      ])
      await mountPage(context)
      editButton('site_name').click()
      await settle()
      const input = valueInput()
      if (settingType === 'boolean') {
        input.click()
        await settle()
      } else {
        await type('2')
      }
      const draft = settingType === 'boolean' ? input.checked : input.value
      const error = document.getElementById('hilos-settings-edit-error')!
      expect(error).not.toBeNull()
      expect(error.getAttribute('role')).toBeNull()
      expect(error.getAttribute('aria-live')).toBeNull()
      expectInvalid(input, false)
      submitEdit()
      await settle()
      answer('fail')
      await settle()

      expect(modalEl('modal')).not.toBeNull()
      expect(valueInput()).toBe(input)
      expect(settingType === 'boolean' ? input.checked : input.value).toBe(
        draft,
      )
      expectInvalid(input, true)
      expect(error.contains(modalEl('hilos-action-error'))).toBe(true)
      expect(modalEl('hilos-action-error')?.textContent).toContain(
        'The setting refused the reset.',
      )
      expect(
        input.classList.contains(
          settingType === 'boolean' ? 'form-check-input' : 'form-control',
        ),
      ).toBe(true)

      submitEdit()
      await settle()
      expect(sent).toHaveLength(2)
      expectInvalid(input, false)
      expect(modalEl('hilos-action-error')).toBeNull()
      answer('fail')
      await settle()
      expectInvalid(input, true)
      if (settingType === 'boolean') {
        input.click()
        await settle()
      } else {
        await type('3')
      }
      expectInvalid(input, false)
      expect(modalEl('hilos-action-error')).toBeNull()
      expect(document.getElementById('hilos-settings-edit-error')).toBe(error)
    },
  )

  it.each(['timeout', 'disconnected', 'invalid-reply'])(
    'keeps a %s message without marking the value invalid',
    async (outcome) => {
      vi.useFakeTimers()
      const { context, answer, disconnect } = seededContext([CUSTOM])
      if (outcome === 'invalid-reply') {
        // Exercise the real lifecycle's schema failure through the same tracker.
        const dispatch = context.actions.dispatch.bind(context.actions)
        vi.spyOn(context.actions, 'dispatch').mockImplementation(
          (action, data) => dispatch(action, data, { replySchema: z.string() }),
        )
      }
      await mountPage(context)
      editButton('site_name').click()
      await settle()
      await type('Mine')
      const input = valueInput()
      submitEdit()
      await settle()
      if (outcome === 'timeout') {
        await vi.advanceTimersByTimeAsync(30000)
      } else if (outcome === 'disconnected') {
        disconnect()
      } else {
        answer('success')
      }
      await settle()

      expect(modalEl('modal')).not.toBeNull()
      expect(valueInput()).toBe(input)
      expect(input.value).toBe('Mine')
      expectInvalid(input, false)
      const message =
        outcome === 'timeout'
          ? 'The action timed out. Please try again.'
          : outcome === 'disconnected'
            ? 'The connection dropped before the action completed. Please try again.'
            : 'The server answered in a form this page could not read. Please try again.'
      expect(modalEl('hilos-action-error')?.textContent).toContain(message)
    },
  )

  it('sends only reset while custom is off, leaving a refused disabled draft unmarked', async () => {
    const { context, sent, answer } = seededContext([CUSTOM])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Unsaved draft')
    const input = valueInput()
    customSwitch().click()
    await settle()
    submitEdit()
    await settle()
    expect(sent[0]?.action).toBe('setting_reset')
    expect(sent[0]?.payload).toEqual({ key: 'site_name' })
    answer('fail')
    await settle()

    expect(valueInput()).toBe(input)
    expect(input.value).toBe('Unsaved draft')
    expect(input.disabled).toBe(true)
    expectInvalid(input, false)
    expect(modalEl('hilos-action-error')).not.toBeNull()
    customSwitch().click()
    await settle()
    expect(input.value).toBe('Unsaved draft')
    expectInvalid(input, false)
    expect(modalEl('hilos-action-error')).toBeNull()
  })

  it('keeps refusal on conflict-only changes and Keep mine, clearing it when Take theirs changes the form', async () => {
    const { context, pushUpdate, answer } = seededContext([CUSTOM])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    const input = valueInput()
    submitEdit()
    await settle()
    answer('fail')
    await settle()
    pushUpdate({ ...CUSTOM, value: 'Theirs', overrideValue: 'Theirs' })
    await settle()
    expect(input.value).toBe('Mine')
    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-settings-edit-type')?.textContent).toBe('string')
    expectInvalid(input, true)
    expect(modalEl('hilos-action-error')).not.toBeNull()
    modalEl('conflict-accept-mine')?.click()
    await settle()
    expect(modalEl('conflict-badge')).toBeNull()
    expectInvalid(input, true)
    expect(modalEl('hilos-action-error')).not.toBeNull()

    pushUpdate({ ...CUSTOM, value: 'New theirs', overrideValue: 'New theirs' })
    await settle()
    modalEl('conflict-accept-theirs')?.click()
    await settle()
    expect(input.value).toBe('New theirs')
    expectInvalid(input, false)
    expect(modalEl('hilos-action-error')).toBeNull()
  })

  it('clears a refusal when an automatic live merge changes the accepted form text', async () => {
    const { context, pushUpdate, answer } = seededContext([CUSTOM])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    submitEdit()
    await settle()
    answer('fail')
    await settle()
    expect(modalEl('hilos-action-error')).not.toBeNull()
    const input = valueInput()
    expect(input.value).toBe('Mine')

    pushUpdate({ ...CUSTOM, value: 'Mine', overrideValue: 'Mine' })
    await settle()
    expect(input.value).toBe('Mine')
    expectInvalid(input, true)
    expect(modalEl('hilos-action-error')).not.toBeNull()
    pushUpdate({ ...CUSTOM, value: 'Next', overrideValue: 'Next' })
    await settle()
    expect(valueInput()).toBe(input)
    expect(input.value).toBe('Next')
    expect(input.disabled).toBe(false)
    expect(modalEl('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(modalEl('hilos-action-error')).toBeNull()
    expectInvalid(input, false)
  })

  it('clears the old refusal on opening another row, including a hidden orphan', async () => {
    const hidden = { _hidden: true } as const
    const { context, sent, answer } = seededContext([
      CUSTOM,
      slot({
        key: 'hidden_orphan',
        valueSource: 'orphan',
        value: hidden,
        overrideValue: hidden,
        defaultValue: null,
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await settle()
    await type('Mine')
    submitEdit()
    await settle()
    answer('fail')
    await settle()
    expectInvalid(valueInput(), true)
    modalEl('modal-close')?.click()
    await settle()
    modalEl('modal-confirm-discard')?.click()
    await settle()
    expect(modalEl('modal')).toBeNull()

    editButton('hidden_orphan').click()
    await settle()
    expect(valueInput()).toBeNull()
    expect(modalEl('hilos-settings-edit-custom')).toBeNull()
    expect(modalEl('hilos-hidden')).not.toBeNull()
    expect(modalEl('hilos-settings-edit-orphan')?.textContent).toBe(
      'not in catalog',
    )
    expect(modalEl('hilos-action-error')).toBeNull()
    expect(modalEl('modal')?.querySelector('.is-invalid')).toBeNull()
    expect(modalEl('modal')?.textContent).not.toContain('Mine')
    submitEdit()
    await settle()
    expect(sent).toHaveLength(1)
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosSettingsPage orphan and empty states', () => {
  const ORPHAN = slot({
    key: 'legacy',
    valueSource: 'orphan',
    value: 'legacy value',
    overrideValue: 'legacy value',
    defaultValue: null,
  })

  it('labels an orphan in both table projections and above its edit field, then updates it', async () => {
    const { context, sent, answer } = seededContext([ORPHAN])
    const wrapper = await mountPage(context)
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const badges = wrapper.findAll(
        `${projection} [data-id="hilos-setting-not-in-catalog"]`,
      )
      expect(badges).toHaveLength(1)
      expect(badges[0]!.text()).toBe('not in catalog')
      expect(badges[0]!.attributes('title')).toBe(
        'This key is not in the project catalog',
      )
      expect(badges[0]!.classes()).toEqual([
        'badge',
        'rounded-pill',
        'bg-warning-subtle',
        'text-warning-emphasis',
        'border',
        'border-warning-subtle',
        'flex-shrink-0',
      ])
      expect(wrapper.get(projection).text()).toContain('legacy value')
    }
    editButton('legacy').click()
    await settle()
    const badge = modalEl('hilos-settings-edit-orphan')!
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    expect(badge.textContent).toBe('not in catalog')
    expect(badge.title).toBe('This key is not in the project catalog')
    expect(
      badge.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(input.disabled).toBe(false)
    expect(modalEl('hilos-settings-edit-custom')).toBeNull()
    expect(modalEl('modal')?.textContent).not.toContain('Catalog default')
    await type('New legacy value')
    submitEdit()
    await settle()
    expect(sent[0]).toMatchObject({
      action: 'setting_update',
      payload: { key: 'legacy', value: 'New legacy value' },
    })
    answer('success')
    await settle()
    expect(modalEl('modal')).toBeNull()
  })

  it('keeps hidden orphan marks and badges in both projections without rendering a value', async () => {
    const hidden = { _hidden: true } as const
    const { context } = seededContext([
      { ...ORPHAN, value: hidden, overrideValue: hidden },
    ])
    const wrapper = await mountPage(context)
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const cell = wrapper.get(`${projection} [data-id="setting-value"]`)
      expect(cell.find('[data-id="hilos-hidden"]').exists()).toBe(true)
      expect(cell.get('[data-id="hilos-setting-not-in-catalog"]').text()).toBe(
        'not in catalog',
      )
      expect(cell.find('input').exists()).toBe(false)
      expect(cell.text()).not.toContain('legacy value')
      expect(cell.html()).not.toContain('_hidden')
    }
    editButton('legacy').click()
    await settle()
    expect(modalEl('hilos-settings-edit-orphan')).not.toBeNull()
    expect(modalEl('hilos-settings-edit-value')).toBeNull()
    expect(modalEl('hilos-hidden')).not.toBeNull()
    expect(modalEl('modal')?.textContent).not.toContain('legacy value')
  })

  it('preserves catalog default, reference, custom, false, zero and empty values without orphan badges', async () => {
    const { context } = seededContext([
      slot({ key: 'empty', valueSource: 'default', value: '' }),
      slot({
        key: 'zero',
        type: 'integer',
        valueSource: 'reference',
        value: '0',
        defaultReferenceKey: 'source.key',
      }),
      slot({
        key: 'off',
        type: 'boolean',
        valueSource: 'override',
        value: '0',
        overrideValue: '0',
      }),
    ])
    const wrapper = await mountPage(context)
    expect(
      wrapper.findAll('[data-id="hilos-setting-not-in-catalog"]'),
    ).toHaveLength(0)
    for (const prefix of ['hilos-table-row', 'hilos-table-card']) {
      expect(wrapper.get(`[data-id="${prefix}-empty"]`).text()).toContain(
        'empty',
      )
      expect(wrapper.get(`[data-id="${prefix}-empty"]`).text()).toContain(
        'default',
      )
      const zero = wrapper.get(
        `[data-id="${prefix}-zero"] [data-id="setting-value"]`,
      )
      expect(zero.get('.fst-italic').text()).toBe('0')
      expect(zero.text()).toContain('source.key')
      const off = wrapper.get(
        `[data-id="${prefix}-off"] [data-id="setting-value"]`,
      )
      expect((off.get('input').element as HTMLInputElement).checked).toBe(false)
      expect(off.text()).toContain('custom')
    }
  })

  it('draws the declared empty title and hint in both projections', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)
    expect(
      wrapper
        .findAll('[data-id="hilos-table-empty-title"]')
        .map((title) => title.text()),
    ).toEqual([
      'This project declares no settings',
      'This project declares no settings',
    ])
    expect(
      wrapper
        .findAll('[data-id="hilos-table-empty-hint"]')
        .map((hint) => hint.text()),
    ).toEqual([
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
    ])
    expect(wrapper.find('[data-id="hilos-table-empty-action"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
  })

  it('keeps a search without matches distinct from an empty catalog and resets it', async () => {
    const { context } = seededContext([ORPHAN])
    const wrapper = await mountPage(context)
    await wrapper
      .get('[data-id="hilos-table-search"]')
      .setValue('no-such-setting')
    await settle()
    const states = wrapper.findAll('[data-id="hilos-table-no-matches"]')
    expect(states).toHaveLength(2)
    for (const state of states) {
      expect(state.text()).toContain('Nothing found')
      expect(state.get('[data-id="hilos-table-no-matches-terms"]').text()).toBe(
        'No rows match “no-such-setting”',
      )
    }
    expect(wrapper.find('[data-id="hilos-table-empty-title"]').exists()).toBe(
      false,
    )
    expect(wrapper.text()).not.toContain('This project declares no settings')
    await wrapper
      .get('[data-id="hilos-table-no-matches-reset"]')
      .trigger('click')
    await settle()
    expect(wrapper.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('table').text()).toContain('legacy')
  })
})

describe('HilosSettingsPage reset dialog', () => {
  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: 'Mine',
    overrideValue: 'Mine',
    defaultValue: 'Hilos',
  })

  function resetButton(key: string): HTMLButtonElement | null {
    return document.querySelector(
      `table [data-id="hilos-settings-reset-${key}"]`,
    )
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-settings-reset-confirm') as HTMLButtonElement
  }

  async function settle(): Promise<void> {
    await nextTick()
    await nextTick()
    await nextTick()
  }

  async function openReset(context: HilosSettingsContext): Promise<void> {
    await mountPage(context)
    resetButton('site_name')?.click()
    await nextTick()
  }

  it('offers ↺ on a catalog row, locked on its default, and none on an orphan', async () => {
    const { context } = seededContext([
      CUSTOM,
      slot({ key: 'on_default', valueSource: 'default' }),
      slot({
        key: 'orphan',
        valueSource: 'orphan',
        overrideValue: 'x',
        defaultValue: null,
      }),
    ])
    await mountPage(context)

    expect(resetButton('site_name')?.disabled).toBe(false)
    expect(resetButton('on_default')?.disabled).toBe(true)
    expect(resetButton('orphan')).toBeNull()
    expect(
      document.querySelector('table [data-id="hilos-settings-delete-orphan"]'),
    ).not.toBeNull()
  })

  it('opens on ↺ with the row in focus and sends nothing', async () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    await openReset(context)

    expect(modalEl('modal')?.textContent).toContain('Reset · site_name')
    expect(focus).toEqual(['site_name'])
    expect(sent).toEqual([])
  })

  it('shows the own value now and the catalog default it goes back to', async () => {
    const { context } = seededContext([CUSTOM])
    await openReset(context)

    expect(modalEl('hilos-settings-reset-now')?.textContent).toContain('Mine')
    const back = modalEl('hilos-settings-reset-default')
    expect(back?.textContent).toContain('Hilos')
    expect(back?.textContent).not.toContain('custom')
  })

  it('names the referenced key when the default is a reference', async () => {
    const { context } = seededContext([
      { ...CUSTOM, defaultReferenceKey: 'app.title' },
    ])
    await openReset(context)

    expect(modalEl('hilos-settings-reset-default')?.textContent).toContain(
      'app.title',
    )
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([CUSTOM])
    await openReset(context)

    confirmButton().click()
    await nextTick()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'setting_reset', payload: { key: 'site_name' } },
    ])

    answer('success')
    await settle()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual(['site_name', ''])
  })

  it('Cancel sends nothing and lets the row go', async () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    await openReset(context)

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual(['site_name', ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([CUSTOM])
    await openReset(context)

    confirmButton().click()
    await nextTick()
    answer('fail')
    await settle()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      'The setting refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', async () => {
    const { context, pushUpdate } = seededContext([CUSTOM])
    await openReset(context)
    expect(modalEl('hilos-settings-reset-gone')).toBeNull()

    pushUpdate({
      ...CUSTOM,
      valueSource: 'default',
      value: 'Hilos',
      overrideValue: null,
    })
    await nextTick()

    expect(modalEl('hilos-settings-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(confirmButton().disabled).toBe(true)
  })
})

describe('HilosSettingsPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
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

  it('a viewer opens a setting, sees the hidden mark instead of the input, and finds Save disabled', async () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
      }),
    ])
    await mountPage(context)

    const edit = editButton('site_name') as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    edit.click()
    await nextTick()

    expect(modalEl('hilos-settings-edit-value')).toBeNull()
    expect(modalEl('hilos-hidden')).not.toBeNull()

    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)

    save.click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = modalEl('hilos-settings-edit-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    cancel.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })

  // A viewer's value is always hidden (HIL-1258): Save is off from the first
  // frame, natively, and neither a click nor Enter sends anything.
  it('turns Save off at once in the admin view mode', async () => {
    vi.useFakeTimers()
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await settle()

    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.getAttribute('aria-busy')).toBeNull()
    save.click()
    submitEdit()
    await vi.advanceTimersByTimeAsync(300)
    await settle()
    expect(spinner(save)).toBeNull()
    expect(sent).toHaveLength(0)
  })

  it('a viewer opens the reset of a setting and has nothing to reset it with', async () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
        defaultValue: { _hidden: true },
      }),
    ])
    await mountPage(context)

    const reset = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-reset-site_name"]',
    )
    expect(reset?.disabled).toBe(false)
    reset?.click()
    await nextTick()

    expect(
      modalEl('hilos-settings-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      modalEl('hilos-settings-reset-default')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    const confirm = modalEl('hilos-settings-reset-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirm.click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
  })

  it('a viewer opens the deletion of an orphan and has nothing to delete it with', async () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'orphan',
        valueSource: 'orphan',
        overrideValue: { _hidden: true },
        defaultValue: null,
      }),
    ])
    await mountPage(context)

    const del = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-delete-orphan"]',
    )
    expect(del?.disabled).toBe(false)
    del?.click()
    await nextTick()

    const confirm = modalEl(
      'hilos-settings-delete-confirm',
    ) as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirm.click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
  })

  it('an admin on a node in the mode edits a setting as today', async () => {
    bindSession().handshake({ id: 1, admin: true }, true)
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)

    const edit = editButton('site_name') as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    edit.click()
    await nextTick()

    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    expect(input.disabled).toBe(false)
    input.value = 'Other'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()

    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(false)
    expect(save.getAttribute('aria-describedby')).toBeNull()
  })
})
