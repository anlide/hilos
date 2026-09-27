import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import HilosLegalSettingsPage from './HilosLegalSettingsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  ActionError,
  createSignal,
  HilosPages,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
} from '@hilos/core'

/** The live window is real; only its transport and the rejected action are replaced. */
function harness() {
  const listeners: Array<(frame: never) => void> = []
  const calls: Array<{ action: string; payload: unknown }> = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_SETTINGS)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        if (event === 'tableWindow') listeners.push(listener)
        return () => {}
      },
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
      sendTableRowFocus() {},
    },
    actions: {
      dispatch(action: string, payload: unknown) {
        calls.push({ action, payload })
        return {
          requestId: '1',
          loading: createSignal(false),
          done: Promise.reject(
            new ActionError(
              'legal_setting_set',
              'fail',
              'Server refused this choice',
            ),
          ),
        }
      },
    },
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_SETTINGS,
      params: {},
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return {
    context,
    router,
    calls,
    push(value: string) {
      for (const listener of listeners)
        listener({
          data: {
            page: HilosPages.LEGAL_SETTINGS,
            tableKey: 'hilosLegalSettings',
            rows: [
              {
                rowKey: 'legal.consent_form',
                slots: { setting: { value, defaultValue: 'checkbox' } },
              },
            ],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
          },
        } as never)
    },
  }
}

/** A modal is teleported outside the mounted table. */
function input(): HTMLSelectElement {
  return document.querySelector(
    '[data-id="legal-setting-input"]',
  ) as HTMLSelectElement
}
function save(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="legal-setting-save"]',
  ) as HTMLButtonElement
}

describe('legal setting Vue modal', () => {
  it('merges a pristine incoming value, locks an unchanged save and keeps a refused draft', async () => {
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      await view
        .find('[data-id="legal-setting-edit-legal.consent_form"]')
        .trigger('click')
      expect(input().value).toBe('checkbox')
      expect(save().disabled).toBe(true)
      h.push('line')
      await nextTick()
      expect(input().value).toBe('line')
      expect(save().disabled).toBe(true)
      expect(
        document.querySelector('[data-id="legal-setting-notice"]')?.textContent,
      ).toContain('Updated just now')
      input().value = 'checkbox'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()
      expect(save().disabled).toBe(false)
      save().click()
      await flushPromises()
      await nextTick()
      expect(h.calls).toEqual([
        {
          action: 'legal_setting_set',
          payload: { key: 'legal.consent_form', value: 'checkbox' },
        },
      ])
      expect(input().value).toBe('checkbox')
      expect(
        document.querySelector('[data-id="hilos-action-error"]')?.textContent,
      ).toContain('Server refused this choice')
    } finally {
      view.unmount()
    }
  })
})
