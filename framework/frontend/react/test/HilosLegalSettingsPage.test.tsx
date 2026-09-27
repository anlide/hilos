import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalSettingsPage } from '../src/admin/legal/HilosLegalSettingsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
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
afterEach(cleanup)
describe('legal setting React modal', () => {
  it('merges a pristine incoming value, locks an unchanged save and keeps a refused draft', async () => {
    const h = harness()
    const view = render(
      <HilosRouterContext.Provider value={h.router}>
        <HilosLegalSettingsPage context={h.context} />
      </HilosRouterContext.Provider>,
    )
    act(() => h.push('checkbox'))
    fireEvent.click(
      view.container.querySelector(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      ) as Element,
    )
    expect(input().value).toBe('checkbox')
    expect(save().disabled).toBe(true)
    act(() => h.push('line'))
    expect(input().value).toBe('line')
    expect(save().disabled).toBe(true)
    expect(
      document.querySelector('[data-id="legal-setting-notice"]')?.textContent,
    ).toContain('Updated just now')
    fireEvent.change(input(), { target: { value: 'checkbox' } })
    expect(save().disabled).toBe(false)
    await act(async () => {
      fireEvent.click(save())
    })
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
  })
})
