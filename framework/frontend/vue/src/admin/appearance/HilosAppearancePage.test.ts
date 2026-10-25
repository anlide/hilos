import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosRouter,
  type ProjectSignal,
  type Hideable,
  type ThemePosition,
} from '@hilos/core/base'
import {
  HilosAppearanceSettingKey,
  HILOS_APPEARANCE_SETTINGS_TABLE,
  type HilosAppearanceContext,
} from '@hilos/core/admin/appearance'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosAppearancePage from './HilosAppearancePage.vue'

type SettingValue = Hideable<boolean | string>

/** The catalog identity the Appearance answer carries, one Overview tab and no children. */
function appearanceIdentity(): HilosPageIdentity {
  return {
    label: 'Appearance',
    lead: 'Theme switching and the default theme.',
    breadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.APPEARANCE, label: 'Appearance' },
    ],
    children: [],
    pageHeadOwner: {
      page: HilosPages.APPEARANCE,
      label: 'Appearance',
      lead: 'Theme switching and the default theme.',
      icon: 'bi-palette',
    },
    pageTabs: [{ page: HilosPages.APPEARANCE, label: 'Overview' }],
  }
}

function harness() {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.APPEARANCE)
  const windowListeners = new Set<(frame: { data: unknown }) => void>()
  const deltaListeners = new Set<(frame: { data: unknown }) => void>()
  const sessionListeners = new Set<(signal: ProjectSignal) => void>()
  const replyListeners = new Map<string, Set<(frame: unknown) => void>>()
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
  const connection = {
    on(event: string, listener: (frame: unknown) => void) {
      if (event === 'tableWindow')
        windowListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'tableViewportDelta')
        deltaListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'projectSignal')
        sessionListeners.add(listener as (signal: ProjectSignal) => void)
      if (event === 'actionSuccess' || event === 'actionError') {
        const listeners = replyListeners.get(event) ?? new Set()
        listeners.add(listener)
        replyListeners.set(event, listeners)
      }
      return () => {
        windowListeners.delete(listener as (frame: { data: unknown }) => void)
        deltaListeners.delete(listener as (frame: { data: unknown }) => void)
        sessionListeners.delete(listener as (signal: ProjectSignal) => void)
        replyListeners.get(event)?.delete(listener)
      }
    },
    registerTableWindow() {},
    unregisterTableWindow() {},
    sendTableViewport() {
      return true
    },
    sendTableRendered() {
      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string) {
      focus.push({ page, tableKey, rowKey })
      return true
    },
    sendAction(
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) {
      sent.push({ action, payload, requestId })
      return true
    },
  } as unknown as HilosConnection
  const actions = new ActionLifecycle(connection)
  const identity = createSignal<HilosPageIdentity | undefined>(undefined)
  const router: HilosRouter = {
    currentRoute: createSignal({
      page: HilosPages.APPEARANCE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/appearance'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      page === HilosPages.DASHBOARD
        ? '/hilos'
        : page === HilosPages.APPEARANCE
          ? '/hilos/appearance'
          : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  bindSessionScope(connection, scopes)
  const releaseAdmin = bindAdminAccess(scopes)

  function row(key: string, value: SettingValue, defaultValue: SettingValue) {
    return {
      rowKey: key,
      slots: { setting: { rowKey: key, value, defaultValue } },
    }
  }

  return {
    context: { connection, scopes, actions } satisfies HilosAppearanceContext,
    router,
    identity,
    sent,
    focus,
    answer(outcome: 'success' | 'fail', message: string) {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          ...(outcome === 'success' ? { message } : { reason: message }),
        })
      }
    },
    viewer() {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: {
          entities: { currentUser: null },
          data: { adminViewMode: true },
        },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of sessionListeners) listener(signal)
    },
    window(
      enabled = true,
      defaultEnabled = true,
      defaultTheme: SettingValue = 'system',
      catalogDefault: SettingValue = 'system',
    ) {
      const frame = {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rows: [
            row(
              HilosAppearanceSettingKey.switchingEnabled,
              enabled,
              defaultEnabled,
            ),
            row(
              HilosAppearanceSettingKey.defaultTheme,
              defaultTheme,
              catalogDefault,
            ),
          ],
          totalCount: 2,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 2,
        },
      }
      for (const listener of windowListeners) listener(frame)
    },
    change(key: string, value: SettingValue, defaultValue: SettingValue) {
      const frame = {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          kind: 'row_updated',
          rowKey: key,
          row: row(key, value, defaultValue),
        },
      }
      for (const listener of deltaListeners) listener(frame)
    },
    dispose() {
      releaseAdmin()
    },
  }
}

const mounted: ReturnType<typeof mount>[] = []
const releases: Array<() => void> = []

afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  for (const release of releases.splice(0)) release()
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function mountPage(h: ReturnType<typeof harness>) {
  releases.push(() => h.dispose())
  const view = mount(HilosAppearancePage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  mounted.push(view)
  return view
}

function input(): HTMLInputElement {
  return document.querySelector(
    '[data-id="appearance-switching-input"]',
  ) as HTMLInputElement
}

function save(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="appearance-switching-save"]',
  ) as HTMLButtonElement
}

async function settle(): Promise<void> {
  for (let tick = 0; tick < 4; tick += 1) await nextTick()
}

async function openEdit(
  h: ReturnType<typeof harness>,
  enabled = true,
  defaultEnabled = true,
): Promise<void> {
  mountPage(h)
  h.window(enabled, defaultEnabled)
  await nextTick()
  const pencil = document.querySelector<HTMLButtonElement>(
    'table [data-id="appearance-setting-edit-theme.switching_enabled"]',
  )
  pencil!.click()
  await nextTick()
}

async function choose(enabled: boolean): Promise<void> {
  input().checked = enabled
  input().dispatchEvent(new Event('change', { bubbles: true }))
  await nextTick()
}

// The fieldset the modal stands its body in; a save in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/**
 * Every way a person closes a window: the ×, its Cancel, Escape and the backdrop.
 *
 * @param cancel The window's Cancel button.
 */
function tryClosing(cancel: HTMLButtonElement): void {
  document.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
  cancel.click()
  const modal = document.querySelector<HTMLElement>('[data-id="modal"]')
  modal?.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modal?.click()
}

describe('Appearance page', () => {
  it('shows a placeholder, then typed values and defaults to an admin view mode viewer', async () => {
    const h = harness()
    h.viewer()
    const view = mountPage(h)
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(view.find('[data-id="hilos-table-loading"]').exists()).toBe(true)

    h.window()
    await nextTick()
    expect(
      view
        .get(
          `[data-id="appearance-setting-value-${HilosAppearanceSettingKey.switchingEnabled}"]`,
        )
        .text(),
    ).toBe('On')
    expect(
      view
        .get(
          `[data-id="appearance-setting-default-${HilosAppearanceSettingKey.defaultTheme}"]`,
        )
        .text(),
    ).toBe('System')
    expect(view.text()).toContain('Theme switching')
    expect(view.text()).toContain('Default theme')
    const pencils = view.findAll(
      'table button[data-id^="appearance-setting-edit-"]',
    )
    expect(pencils).toHaveLength(2)
    expect(pencils[0]!.attributes('data-id')).toBe(
      'appearance-setting-edit-theme.switching_enabled',
    )
    expect(pencils[0]!.attributes('aria-label')).toBe('Edit Theme switching')
    expect(pencils[0]!.attributes('title')).toBe('Edit Theme switching')
    expect(pencils[0]!.find('i').attributes('aria-hidden')).toBe('true')
    expect(pencils[0]!.get('span').classes()).toContain('d-md-none')
    expect(pencils[1]!.attributes('data-id')).toBe(
      'appearance-setting-edit-theme.default',
    )
    expect(pencils[1]!.attributes('aria-label')).toBe('Edit Default theme')
    expect(pencils[1]!.attributes('title')).toBe('Edit Default theme')
    expect(pencils[1]!.find('i').attributes('aria-hidden')).toBe('true')
    expect(pencils[1]!.get('span').text()).toBe('Edit')
    expect(pencils[1]!.get('span').classes()).toContain('d-md-none')
    expect(
      view
        .find(
          '[data-id="hilos-table-cards"] button[data-id="appearance-setting-edit-theme.switching_enabled"]',
        )
        .exists(),
    ).toBe(true)
    expect(
      view
        .find(
          '[data-id="hilos-table-cards"] button[data-id="appearance-setting-edit-theme.default"]',
        )
        .exists(),
    ).toBe(true)

    h.change(HilosAppearanceSettingKey.switchingEnabled, false, true)
    await nextTick()
    expect(
      view
        .get(
          `[data-id="appearance-setting-value-${HilosAppearanceSettingKey.switchingEnabled}"]`,
        )
        .text(),
    ).toBe('Off')
    expect(
      view
        .get(
          `[data-id="appearance-setting-default-${HilosAppearanceSettingKey.switchingEnabled}"]`,
        )
        .text(),
    ).toBe('On')
  })

  it.each([false, true])(
    'draws one catalog section head once identity arrives (viewer=%s)',
    async (viewer) => {
      const h = harness()
      if (viewer) h.viewer()
      const view = mountPage(h)
      const skeleton = view.get('[data-id="hilos-page-head-skeleton"]')
      expect(skeleton.attributes('aria-hidden')).toBe('true')
      expect(skeleton.find('.rounded-circle').exists()).toBe(true)
      expect(skeleton.find('.rounded-3').exists()).toBe(false)
      expect(view.find('h1').exists()).toBe(false)
      expect(view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
      expect(view.find('.bi-palette').exists()).toBe(false)
      expect(view.text()).not.toContain('Appearance')
      expect(view.text()).not.toContain(
        'Theme switching and the default theme for this installation.',
      )

      h.identity.set(appearanceIdentity())
      await nextTick()
      expect(view.findAll('[data-id="hilos-page-head"]')).toHaveLength(1)
      expect(view.findAll('h1')).toHaveLength(1)
      const heading = view.get('h1')
      expect(heading.text()).toBe('Appearance')
      expect(heading.attributes('data-id')).toBe('hilos-page-head-title')
      const circle = view.get(
        '[data-id="hilos-page-head-block"] > .rounded-circle',
      )
      expect(circle.attributes('aria-hidden')).toBe('true')
      expect(circle.get('i').classes()).toEqual(['bi', 'bi-palette'])
      const block = view.get('[data-id="hilos-page-head-block"]')
      expect(block.findAll('p')).toHaveLength(1)
      expect(block.get('p').text()).toBe(
        'Theme switching and the default theme.',
      )
      const crumbs = view.get('[data-id="hilos-breadcrumb"]')
      expect(crumbs.attributes('aria-label')).toBe('Breadcrumb')
      const items = crumbs.findAll('li')
      expect(items).toHaveLength(2)
      expect(items[0]!.text()).toBe('Hilos')
      expect(items[0]!.get('a').attributes('href')).toBe('/hilos')
      expect(items[1]!.text()).toBe('Appearance')
      expect(items[1]!.attributes('aria-current')).toBe('page')
      expect(items[1]!.find('a').exists()).toBe(false)
      expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
        false,
      )
      expect(view.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-title-skeleton"]').exists()).toBe(
        false,
      )
      expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
      expect(view.text()).not.toContain(
        'Theme switching and the default theme for this installation.',
      )
      expect(
        view
          .get('[data-id="hilos-page-head"]')
          .element.nextElementSibling?.getAttribute('data-id'),
      ).toBe('hilos-viewport-table')

      h.window()
      await nextTick()
      const headingId = heading.attributes('id')
      expect(headingId).toBeTruthy()
      expect(document.getElementById(headingId!)).toBe(heading.element)
      expect(view.get('table').attributes('aria-labelledby')).toBe(headingId)
      expect(
        view
          .get('[data-id="hilos-table-cards"] [role="list"]')
          .attributes('aria-labelledby'),
      ).toBe(headingId)
    },
  )

  it.each([true, false])(
    'opens with the saved value %s, its default, and Save locked',
    async (enabled) => {
      const h = harness()
      await openEdit(h, enabled, enabled)
      expect(input().checked).toBe(enabled)
      expect(input().getAttribute('role')).toBe('switch')
      expect(input().hasAttribute('data-autofocus')).toBe(true)
      expect(input().disabled).toBe(false)
      expect(save().disabled).toBe(true)
      expect(
        document.querySelector('[data-id="modal"]')?.textContent,
      ).toContain(`default: ${enabled ? 'On' : 'Off'}`)
      expect(
        document.querySelector('[data-id="modal"]')?.textContent,
      ).toContain('Edit · Theme switching')
      expect(
        document.querySelector('[data-id="hilos-action-error-slot"]'),
      ).not.toBeNull()
      expect(
        document.querySelector('[data-id="appearance-switching-notice-slot"]'),
      ).not.toBeNull()
      expect(h.focus).toEqual([
        {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rowKey: HilosAppearanceSettingKey.switchingEnabled,
        },
      ])
      expect(h.sent).toEqual([])
    },
  )

  it('sends the changed boolean, waits for success, releases focus and toasts the server sentence', async () => {
    const h = harness()
    await openEdit(h)
    await choose(false)
    expect(save().disabled).toBe(false)
    save().click()
    await nextTick()
    expect(h.sent).toEqual([
      {
        action: 'appearance_theme_switching_set',
        payload: { enabled: false },
        requestId: expect.any(String),
      },
    ])
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(save().getAttribute('aria-disabled')).toBe('true')
    expect(
      document.querySelector(
        'table [data-id="appearance-setting-value-theme.switching_enabled"]',
      )?.textContent,
    ).toBe('On')
    h.answer('success', 'Theme switching saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Theme switching saved.']])
  })

  it('keeps a refused write and its draft in the dialog', async () => {
    const h = harness()
    await openEdit(h)
    await choose(false)
    save().click()
    await nextTick()
    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(input().checked).toBe(false)
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('The setting could not be saved.')
    expect(save().disabled).toBe(false)
  })

  it('holds the window and locks its body while the save is in flight, and frees the same switch after a refusal', async () => {
    const h = harness()
    await openEdit(h)
    await choose(false)
    const field = input()
    const body = bodyLock()
    const cancel = document.querySelector<HTMLButtonElement>(
      '[data-id="appearance-switching-cancel"]',
    )!
    const close = document.querySelector<HTMLButtonElement>(
      '[data-id="modal-close"]',
    )!
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    save().click()
    await nextTick()
    expect(h.sent).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing(cancel)
    submitWindowForm()
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(h.sent).toHaveLength(1)

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(bodyLock()).toBe(body)
    expect(input()).toBe(field)
    expect(field.checked).toBe(false)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    save().click()
    await nextTick()
    expect(h.sent[1]?.payload).toEqual({ enabled: false })
    h.answer('success', 'Theme switching saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('follows a pristine update from another administrator through the focused row', async () => {
    const h = harness()
    await openEdit(h)
    h.change(HilosAppearanceSettingKey.switchingEnabled, false, true)
    await settle()
    expect(input().checked).toBe(false)
    expect(
      document.querySelector('[data-id="appearance-switching-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(save().disabled).toBe(true)
  })

  it('asks to discard a dirty draft when Cancel is pressed', async () => {
    const h = harness()
    await openEdit(h)
    await choose(false)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="appearance-switching-cancel"]',
      )!
      .click()
    await nextTick()
    expect(document.body.textContent).toContain('Discard changes?')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(h.sent).toEqual([])
  })

  it('lets a viewer open and change the draft while Save stays disabled and described by the banner', async () => {
    const h = harness()
    h.viewer()
    await openEdit(h)
    await choose(false)
    expect(input().disabled).toBe(false)
    expect(input().checked).toBe(false)
    expect(save().disabled).toBe(true)
    expect(save().getAttribute('aria-describedby')).toContain(
      'hilos-view-mode-strip-text',
    )
    save().click()
    await nextTick()
    expect(h.sent).toEqual([])
    // Enter submits the form; a viewer's stays as still as the locked Save.
    submitWindowForm()
    await nextTick()
    expect(h.sent).toEqual([])
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="appearance-switching-cancel"]',
      )!.disabled,
    ).toBe(false)
  })

  it("saves on an administrator's Enter the way Save does", async () => {
    const h = harness()
    await openEdit(h)
    await choose(false)
    submitWindowForm()
    await nextTick()
    expect(h.sent).toEqual([
      {
        action: 'appearance_theme_switching_set',
        payload: { enabled: false },
        requestId: expect.any(String),
      },
    ])
  })
})

/** Submit the open window's form, as Enter in one of its fields does. */
function submitWindowForm(): void {
  document
    .querySelector('[data-id="modal"] form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}

function themeRadio(position: ThemePosition): HTMLInputElement {
  return document.querySelector<HTMLInputElement>(
    `[data-id="appearance-default-theme-${position}"]`,
  )!
}

function themeSave(): HTMLButtonElement {
  return document.querySelector<HTMLButtonElement>(
    '[data-id="appearance-default-theme-save"]',
  )!
}

async function openDefaultThemeEdit(
  h: ReturnType<typeof harness>,
  value: SettingValue = 'system',
  catalogDefault: SettingValue = 'system',
): Promise<HTMLButtonElement> {
  mountPage(h)
  h.window(true, true, value, catalogDefault)
  await nextTick()
  const pencil = document.querySelector<HTMLButtonElement>(
    'table [data-id="appearance-setting-edit-theme.default"]',
  )!
  pencil.focus()
  pencil.click()
  await settle()
  return pencil
}

async function chooseTheme(position: ThemePosition): Promise<void> {
  const radio = themeRadio(position)
  radio.checked = true
  radio.dispatchEvent(new Event('change', { bubbles: true }))
  await nextTick()
}

describe('Appearance default theme window', () => {
  it.each<ThemePosition>(['light', 'dark', 'system'])(
    'opens with %s checked, named radios, the catalog default and Save locked',
    async (position) => {
      const h = harness()
      await openDefaultThemeEdit(h, position)
      expect(document.activeElement).toBe(themeRadio(position))
      const modal = document.querySelector('[data-id="modal"]')!
      expect(modal.textContent).toContain('Edit · Default theme')
      expect(modal.querySelector('legend')?.textContent).toBe('Default theme')
      expect(modal.querySelectorAll('input[type="radio"]')).toHaveLength(3)
      for (const [value, label, icon] of [
        ['light', 'Light', 'bi-sun'],
        ['dark', 'Dark', 'bi-moon-stars'],
        ['system', 'System', 'bi-circle-half'],
      ] as const) {
        const radio = themeRadio(value)
        expect(radio.checked).toBe(value === position)
        expect(radio.disabled).toBe(false)
        expect(radio.name).toBe('appearance-default-theme')
        expect(radio.hasAttribute('data-autofocus')).toBe(true)
        const named = modal.querySelector(`label[for="${radio.id}"]`)!
        expect(named.textContent).toContain(label)
        expect(
          named.querySelector(`i.${icon}`)?.getAttribute('aria-hidden'),
        ).toBe('true')
        expect(named.querySelector('.badge') !== null).toBe(value === 'system')
      }
      expect(
        modal.querySelector('.badge')?.classList.contains('bg-body-tertiary'),
      ).toBe(true)
      expect(modal.querySelector('.badge')?.textContent?.trim()).toBe('default')
      // The group's own fieldset, the one its legend names; the modal locks
      // its body with a fieldset of its own around it.
      expect(
        modal
          .querySelector('legend')
          ?.closest('fieldset')
          ?.getAttribute('aria-describedby'),
      ).toBe('appearance-default-theme-hint')
      expect(
        modal.querySelector('#appearance-default-theme-hint')?.textContent,
      ).toContain(
        'Everyone who has not chosen a theme switches at once, in every open tab. People who chose one keep it.',
      )
      expect(
        modal.querySelector('[data-id="hilos-action-error-slot"]'),
      ).not.toBeNull()
      expect(
        modal.querySelector('[data-id="appearance-default-theme-notice-slot"]'),
      ).not.toBeNull()
      expect(themeSave().disabled).toBe(true)
      expect(h.focus).toEqual([
        {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rowKey: HilosAppearanceSettingKey.defaultTheme,
        },
      ])
      expect(h.sent).toEqual([])
    },
  )

  it('marks the catalog default rather than the saved position', async () => {
    const h = harness()
    await openDefaultThemeEdit(h, 'dark', 'light')
    const badge = document.querySelector('[data-id="modal"] .badge')!
    expect(badge.closest('label')?.getAttribute('for')).toBe(
      themeRadio('light').id,
    )
    expect(themeRadio('dark').checked).toBe(true)
  })

  it('waits for success, preserves the table until echo, returns focus and toasts the server sentence', async () => {
    const h = harness()
    const pencil = await openDefaultThemeEdit(h)
    await chooseTheme('dark')
    expect(themeSave().disabled).toBe(false)
    themeSave().click()
    await nextTick()
    expect(h.sent).toEqual([
      {
        action: 'appearance_default_theme_set',
        payload: { defaultTheme: 'dark' },
        requestId: expect.any(String),
      },
    ])
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(themeSave().getAttribute('aria-disabled')).toBe('true')
    expect(
      document.querySelector(
        'table [data-id="appearance-setting-value-theme.default"]',
      )?.textContent,
    ).toBe('System')
    h.change(HilosAppearanceSettingKey.defaultTheme, 'dark', 'system')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    h.answer('success', 'Default theme saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(document.activeElement).toBe(pencil)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Default theme saved.']])
  })

  it('keeps a refused choice and shows the server error', async () => {
    const h = harness()
    await openDefaultThemeEdit(h)
    await chooseTheme('dark')
    themeSave().click()
    await nextTick()
    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(themeRadio('dark').checked).toBe(true)
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('The setting could not be saved.')
    expect(themeSave().disabled).toBe(false)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'The setting could not be saved.']])
  })

  it('holds the window and locks its body while the save is in flight, and frees the same radios after a refusal', async () => {
    const h = harness()
    await openDefaultThemeEdit(h)
    await chooseTheme('dark')
    const radio = themeRadio('dark')
    const body = bodyLock()
    const cancel = document.querySelector<HTMLButtonElement>(
      '[data-id="appearance-default-theme-cancel"]',
    )!
    const close = document.querySelector<HTMLButtonElement>(
      '[data-id="modal-close"]',
    )!
    expect(body?.contains(radio)).toBe(true)
    expect(body?.disabled).toBe(false)

    themeSave().click()
    await nextTick()
    expect(h.sent).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing(cancel)
    submitWindowForm()
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(h.sent).toHaveLength(1)

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(bodyLock()).toBe(body)
    expect(themeRadio('dark')).toBe(radio)
    expect(radio.checked).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await chooseTheme('light')
    themeSave().click()
    await nextTick()
    expect(h.sent[1]?.payload).toEqual({ defaultTheme: 'light' })
    h.answer('success', 'Default theme saved.')
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('takes a pristine incoming value and clears its notice when the draft changes', async () => {
    const h = harness()
    await openDefaultThemeEdit(h)
    h.change(HilosAppearanceSettingKey.defaultTheme, 'light', 'system')
    await settle()
    expect(themeRadio('light').checked).toBe(true)
    expect(
      document.querySelector('[data-id="appearance-default-theme-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(themeSave().disabled).toBe(true)
    await chooseTheme('dark')
    expect(
      document.querySelector('[data-id="appearance-default-theme-notice"]'),
    ).toBeNull()
  })

  it.each(['mine', 'theirs'] as const)(
    'blocks a conflict, offers both values and accepts %s',
    async (choice) => {
      const h = harness()
      await openDefaultThemeEdit(h)
      await chooseTheme('dark')
      h.change(HilosAppearanceSettingKey.defaultTheme, 'light', 'system')
      await settle()
      expect(themeRadio('dark').checked).toBe(true)
      expect(themeSave().disabled).toBe(true)
      expect(
        document.querySelector('[data-id="appearance-default-theme-notice"]')
          ?.textContent,
      ).toContain('Changed elsewhere to "Light".')
      expect(
        document.querySelector('[data-id="conflict-accept-mine"]')?.textContent,
      ).toContain('Keep mine')
      expect(
        document.querySelector('[data-id="conflict-accept-theirs"]')
          ?.textContent,
      ).toContain('Take theirs')
      expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
      themeSave().click()
      expect(h.sent).toEqual([])
      document
        .querySelector<HTMLButtonElement>(
          `[data-id="conflict-accept-${choice}"]`,
        )!
        .click()
      await settle()
      expect(document.querySelector('[data-id="conflict-choices"]')).toBeNull()
      expect(themeRadio(choice === 'mine' ? 'dark' : 'light').checked).toBe(
        true,
      )
      expect(themeSave().disabled).toBe(choice === 'theirs')
    },
  )

  it('asks to discard a dirty choice and releases focus only after confirmation', async () => {
    const h = harness()
    const pencil = await openDefaultThemeEdit(h)
    await chooseTheme('dark')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="appearance-default-theme-cancel"]',
      )!
      .click()
    await settle()
    expect(
      document.querySelector('[data-id="modal-confirm"]')?.textContent,
    ).toContain('Discard changes?')
    expect(h.focus.at(-1)?.rowKey).toBe(HilosAppearanceSettingKey.defaultTheme)
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-cancel"]')!
      .click()
    await settle()
    expect(themeRadio('dark').checked).toBe(true)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="appearance-default-theme-cancel"]',
      )!
      .click()
    await settle()
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')!
      .click()
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(document.activeElement).toBe(pencil)
    expect(h.sent).toEqual([])
  })

  it('lets a viewer move the radios while Save stays disabled and described by the banner', async () => {
    const h = harness()
    h.viewer()
    await openDefaultThemeEdit(h)
    await chooseTheme('dark')
    expect(themeRadio('dark').disabled).toBe(false)
    expect(themeRadio('dark').checked).toBe(true)
    expect(themeSave().disabled).toBe(true)
    expect(themeSave().getAttribute('aria-describedby')).toContain(
      'hilos-view-mode-strip-text',
    )
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="appearance-default-theme-cancel"]',
      )!.disabled,
    ).toBe(false)
    themeSave().click()
    await nextTick()
    expect(h.sent).toEqual([])
    submitWindowForm()
    await nextTick()
    expect(h.sent).toEqual([])
  })

  it('shows a hidden mark instead of radios for a hidden value', async () => {
    const h = harness()
    h.viewer()
    await openDefaultThemeEdit(h, HIDDEN_VALUE)
    const modal = document.querySelector('[data-id="modal"]')!
    expect(modal.textContent).toContain('Hidden')
    expect(modal.querySelector('input')).toBeNull()
    expect(themeSave().disabled).toBe(true)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="appearance-default-theme-cancel"]',
      )!
      .click()
    await settle()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('never checks a fallback radio or writes an unknown value', async () => {
    const h = harness()
    await openDefaultThemeEdit(h, 'sepia')
    for (const position of ['light', 'dark', 'system'] as const) {
      expect(themeRadio(position).checked).toBe(false)
    }
    expect(themeSave().disabled).toBe(true)
    document
      .querySelector('[data-id="modal"] form')!
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await nextTick()
    expect(h.sent).toEqual([])
  })
})
