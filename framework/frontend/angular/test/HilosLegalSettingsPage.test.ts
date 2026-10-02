import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalSettingsPage } from '../src/admin/legal/HilosLegalSettingsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
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

/**
 * Mount the page over the harness's live window, the way every case here does.
 *
 * @param h The harness whose context and router the page is mounted with.
 */
async function mountPage(h: ReturnType<typeof harness>) {
  await TestBed.configureTestingModule({
    imports: [HilosLegalSettingsPage],
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalSettingsPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()
  const root = fixture.nativeElement as HTMLElement
  return {
    fixture,
    root,
    input: () =>
      root.querySelector(
        '[data-id="legal-setting-input"]',
      ) as HTMLSelectElement | null,
    save: () =>
      root.querySelector('[data-id="legal-setting-save"]') as HTMLButtonElement,
    cancel: () =>
      root.querySelector(
        '[data-id="legal-setting-cancel"]',
      ) as HTMLButtonElement,
    edit: () =>
      root.querySelector(
        '[data-id="legal-setting-edit-legal.consent_form"]',
      ) as HTMLButtonElement,
  }
}

describe('legal setting Angular modal with the value hidden (HIL-1260)', () => {
  it('draws the mark in the cell and in the modal, with no list and nothing to discard', async () => {
    const h = harness()
    const page = await mountPage(h)
    h.push({ _hidden: true })
    page.fixture.detectChanges()

    const cell = page.root.querySelector(
      '[data-id="legal-setting-value-legal.consent_form"]',
    )
    expect(
      cell?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')

    page.edit().click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()
    expect(page.input()).toBeNull()
    const modal = page.root.querySelector('[data-id="modal"]')
    expect(
      modal?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(page.save().disabled).toBe(true)

    page.cancel().click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()
    expect(
      page.root.querySelector('[data-id="modal-confirm-discard"]'),
    ).toBeNull()
    expect(page.root.querySelector('[data-id="modal"]')).toBeNull()
    page.fixture.destroy()
  })
})

describe('legal setting Angular modal in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
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
    const session = bindSession()
    session.handshake(null, true)
    const h = harness()
    const page = await mountPage(h)
    h.push('checkbox')
    page.fixture.detectChanges()
    expect(page.edit().disabled).toBe(false)
    page.edit().click()
    page.fixture.detectChanges()

    expect(page.input()!.disabled).toBe(false)
    page.input()!.value = 'line'
    page.input()!.dispatchEvent(new Event('change', { bubbles: true }))
    page.fixture.detectChanges()

    expect(page.save().disabled).toBe(true)
    expect(page.save().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    page.save().click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()
    page.fixture.detectChanges()
    expect(h.calls).toEqual([])

    expect(page.cancel().disabled).toBe(false)
    page.cancel().click()
    page.fixture.detectChanges()
    expect(page.input()).toBeNull()
    page.fixture.destroy()
  })

  it('an admin on a node in the mode edits as today', async () => {
    const session = bindSession()
    session.handshake({ id: 1, admin: true }, true)
    const h = harness()
    const page = await mountPage(h)
    h.push('checkbox')
    page.fixture.detectChanges()
    expect(page.edit().disabled).toBe(false)
    page.edit().click()
    page.fixture.detectChanges()

    expect(page.input()!.disabled).toBe(false)
    page.input()!.value = 'line'
    page.input()!.dispatchEvent(new Event('change', { bubbles: true }))
    page.fixture.detectChanges()

    expect(page.save().disabled).toBe(false)
    expect(page.save().getAttribute('aria-describedby')).toBeNull()
    page.fixture.destroy()
  })

  // The e2e of the mode walks this one window: a viewer on a node in the mode
  // is sent the setting hidden, so the window holds the mark and no list, and
  // Save stands disabled under the strip while Cancel still closes it.
  it('a viewer opens a hidden setting: the mark, no list, Save under the strip and Cancel closes', async () => {
    const session = bindSession()
    session.handshake(null, true)
    const h = harness()
    const page = await mountPage(h)
    h.push({ _hidden: true })
    page.fixture.detectChanges()
    page.edit().click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()

    const modal = page.root.querySelector('[data-id="modal"]')
    expect(modal?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(page.input()).toBeNull()
    expect(page.save().disabled).toBe(true)
    expect(page.save().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    expect(page.cancel().disabled).toBe(false)
    page.cancel().click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()
    expect(
      page.root.querySelector('[data-id="modal-confirm-discard"]'),
    ).toBeNull()
    expect(page.root.querySelector('[data-id="modal"]')).toBeNull()
    page.fixture.destroy()
  })
})
