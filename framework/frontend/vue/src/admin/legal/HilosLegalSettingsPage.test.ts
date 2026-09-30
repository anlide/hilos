import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import HilosLegalSettingsPage from './HilosLegalSettingsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  ActionError,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  ScopeManager,
  type HilosConnection,
  type HilosLegalContext,
  type HilosRouter,
  type ProjectSignal,
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

describe('legal setting Vue modal in the admin view mode', () => {
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

  it('a viewer opens a setting, may pick a value and has nothing to save it with', async () => {
    const session = bindSession()
    session.handshake(null, true)
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      const editButton = view.find<HTMLButtonElement>(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      )
      expect(editButton.element.disabled).toBe(false)
      await editButton.trigger('click')

      expect(input().disabled).toBe(false)
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()

      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      save().click()
      await flushPromises()
      await nextTick()
      expect(h.calls).toEqual([])

      const cancelButton = document.querySelector<HTMLButtonElement>(
        '[data-id="legal-setting-cancel"]',
      )!
      expect(cancelButton.disabled).toBe(false)
      cancelButton.click()
      await nextTick()
      expect(input()).toBeNull()
    } finally {
      view.unmount()
    }
  })

  it('an admin on a node in the mode edits as today', async () => {
    const session = bindSession()
    session.handshake({ id: 1, admin: true }, true)
    const h = harness()
    const view = mount(HilosLegalSettingsPage, {
      attachTo: document.body,
      props: { context: h.context },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    try {
      h.push('checkbox')
      await nextTick()
      const editButton = view.find<HTMLButtonElement>(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      )
      expect(editButton.element.disabled).toBe(false)
      await editButton.trigger('click')

      expect(input().disabled).toBe(false)
      input().value = 'line'
      input().dispatchEvent(new Event('change', { bubbles: true }))
      await nextTick()

      expect(save().disabled).toBe(false)
      expect(save().getAttribute('aria-describedby')).toBeNull()
    } finally {
      view.unmount()
    }
  })
})
