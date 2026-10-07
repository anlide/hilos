import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  bindAdminAccess,
  bindSessionScope,
  createSignal,
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
  const connection = {
    on(event: string, listener: (frame: unknown) => void) {
      if (event === 'tableWindow')
        windowListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'tableViewportDelta')
        deltaListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'projectSignal')
        sessionListeners.add(listener as (signal: ProjectSignal) => void)
      return () => {
        windowListeners.delete(listener as (frame: { data: unknown }) => void)
        deltaListeners.delete(listener as (frame: { data: unknown }) => void)
        sessionListeners.delete(listener as (signal: ProjectSignal) => void)
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
  } as unknown as HilosConnection
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
    context: { connection, scopes } as HilosAppearanceContext,
    router,
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
    window() {
      const frame = {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rows: [
            row(HilosAppearanceSettingKey.switchingEnabled, true, true),
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

describe('Appearance page', () => {
  it('shows a placeholder, then typed values and defaults to an admin view mode viewer', async () => {
    const h = harness()
    h.viewer()
    const view = mount(HilosAppearancePage, {
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
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
      expect(view.find('button').exists()).toBe(false)

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
    } finally {
      view.unmount()
      h.dispose()
    }
  })
})
