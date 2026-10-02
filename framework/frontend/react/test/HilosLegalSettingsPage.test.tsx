import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalSettingsPage } from '../src/admin/legal/HilosLegalSettingsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  ActionError,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
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
    push(value: unknown) {
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

describe('legal setting React modal with the value hidden (HIL-1260)', () => {
  it('draws the mark in the cell and in the modal, with no list and nothing to discard', () => {
    const h = harness()
    const view = render(
      <HilosRouterContext.Provider value={h.router}>
        <HilosLegalSettingsPage context={h.context} />
      </HilosRouterContext.Provider>,
    )
    act(() => h.push({ _hidden: true }))

    const cell = view.container.querySelector(
      '[data-id="legal-setting-value-legal.consent_form"]',
    )
    expect(
      cell?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')

    fireEvent.click(
      view.container.querySelector(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      ) as Element,
    )
    expect(document.querySelector('[data-id="legal-setting-input"]')).toBeNull()
    const modal = document.querySelector('[data-id="modal"]')
    expect(
      modal?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(save().disabled).toBe(true)

    fireEvent.click(
      document.querySelector('[data-id="legal-setting-cancel"]') as Element,
    )
    expect(
      document.querySelector('[data-id="modal-confirm-discard"]'),
    ).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })
})

describe('legal setting React modal in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   *
   * @param scopes The context's scopes, the ones the page reads its access from.
   */
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
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
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
    const h = harness()
    bindSession(h.context.scopes).handshake(null, true)
    const view = render(
      <HilosRouterContext.Provider value={h.router}>
        <HilosLegalSettingsPage context={h.context} />
      </HilosRouterContext.Provider>,
    )
    act(() => h.push('checkbox'))

    const editButton = view.container.querySelector<HTMLButtonElement>(
      '[data-id="legal-setting-edit-legal.consent_form"]',
    )
    expect(editButton?.disabled).toBe(false)
    fireEvent.click(editButton as Element)
    expect(input().disabled).toBe(false)
    fireEvent.change(input(), { target: { value: 'line' } })
    expect(save().disabled).toBe(true)
    expect(save().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await act(async () => {
      fireEvent.click(save())
    })
    expect(h.calls).toEqual([])

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="legal-setting-cancel"]',
    )
    expect(cancelButton?.disabled).toBe(false)
    fireEvent.click(cancelButton as Element)
    expect(document.querySelector('[data-id="legal-setting-input"]')).toBeNull()
  })

  it('an admin on a node in the mode edits as today', () => {
    const h = harness()
    bindSession(h.context.scopes).handshake({ id: 1, admin: true }, true)
    const view = render(
      <HilosRouterContext.Provider value={h.router}>
        <HilosLegalSettingsPage context={h.context} />
      </HilosRouterContext.Provider>,
    )
    act(() => h.push('checkbox'))

    const editButton = view.container.querySelector<HTMLButtonElement>(
      '[data-id="legal-setting-edit-legal.consent_form"]',
    )
    expect(editButton?.disabled).toBe(false)
    fireEvent.click(editButton as Element)
    expect(input().disabled).toBe(false)
    fireEvent.change(input(), { target: { value: 'line' } })
    expect(save().disabled).toBe(false)
    expect(save().getAttribute('aria-describedby')).toBeNull()
  })
})
