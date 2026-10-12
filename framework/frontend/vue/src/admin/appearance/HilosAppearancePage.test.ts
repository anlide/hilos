import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
  HilosAppearanceSettingKey,
  HILOS_APPEARANCE_SETTINGS_TABLE,
  HilosPages,
  ScopeManager,
  type HilosAppearanceContext,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosAppearancePage from './HilosAppearancePage.vue'

type SettingValue = boolean | string

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
  const router = {
    currentRoute: createSignal({
      page: HilosPages.APPEARANCE,
      params: {},
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
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
    window(enabled = true, defaultEnabled = true) {
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
            row(HilosAppearanceSettingKey.defaultTheme, 'system', 'system'),
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

describe('Appearance page', () => {
  it('shows a placeholder, then typed values and defaults to an admin view mode viewer', async () => {
    const h = harness()
    h.viewer()
    const view = mountPage(h)
    expect(view.find('[data-id="hilos-admin-title-skeleton"]').exists()).toBe(
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
    ).toBe('As the system')
    expect(view.text()).toContain('Theme switching')
    expect(view.text()).toContain('Default theme')
    const pencils = view.findAll(
      'table button[data-id^="appearance-setting-edit-"]',
    )
    expect(pencils).toHaveLength(1)
    expect(pencils[0]!.attributes('data-id')).toBe(
      'appearance-setting-edit-theme.switching_enabled',
    )
    expect(pencils[0]!.attributes('aria-label')).toBe('Edit Theme switching')
    expect(pencils[0]!.attributes('title')).toBe('Edit Theme switching')
    expect(pencils[0]!.find('i').attributes('aria-hidden')).toBe('true')
    expect(pencils[0]!.get('span').classes()).toContain('d-md-none')
    expect(
      view
        .find(
          '[data-id="hilos-table-cards"] button[data-id="appearance-setting-edit-theme.switching_enabled"]',
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
    expect(save().disabled).toBe(true)
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
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="appearance-switching-cancel"]',
      )!.disabled,
    ).toBe(false)
  })
})
