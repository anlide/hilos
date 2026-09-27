import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalSettingsPage } from '../src/admin/legal/HilosLegalSettingsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
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

afterEach(() => TestBed.resetTestingModule())
describe('legal setting Angular modal', () => {
  it('merges a pristine incoming value, locks an unchanged save and keeps a refused draft', async () => {
    const h = harness()
    await TestBed.configureTestingModule({
      imports: [HilosLegalSettingsPage],
      providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
    }).compileComponents()
    const fixture = TestBed.createComponent(HilosLegalSettingsPage)
    fixture.componentRef.setInput('context', h.context)
    fixture.detectChanges()
    await fixture.whenStable()
    const root = fixture.nativeElement as HTMLElement
    const input = () =>
      root.querySelector('[data-id="legal-setting-input"]') as HTMLSelectElement
    const save = () =>
      root.querySelector('[data-id="legal-setting-save"]') as HTMLButtonElement
    h.push('checkbox')
    fixture.detectChanges()
    ;(
      root.querySelector(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      ) as HTMLButtonElement
    ).click()
    fixture.detectChanges()
    expect(input().value).toBe('checkbox')
    expect(save().disabled).toBe(true)
    h.push('line')
    fixture.detectChanges()
    expect(input().value).toBe('line')
    expect(save().disabled).toBe(true)
    expect(
      root.querySelector('[data-id="legal-setting-notice"]')?.textContent,
    ).toContain('Updated just now')
    input().value = 'checkbox'
    input().dispatchEvent(new Event('change', { bubbles: true }))
    fixture.detectChanges()
    expect(save().disabled).toBe(false)
    save().click()
    fixture.detectChanges()
    await fixture.whenStable()
    fixture.detectChanges()
    expect(h.calls).toEqual([
      {
        action: 'legal_setting_set',
        payload: { key: 'legal.consent_form', value: 'checkbox' },
      },
    ])
    expect(input().value).toBe('checkbox')
    expect(
      root.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('Server refused this choice')
    fixture.destroy()
  })
})
