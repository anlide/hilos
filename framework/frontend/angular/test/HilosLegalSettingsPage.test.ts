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
} from '@hilos/core/base'

/**
 * The live window is real; only its transport and the rejected action are replaced.
 *
 * @param deferred Keep each reply in flight until the test answers it.
 * @param initialRefusal Refuse the carrier in the first page response.
 */
function harness(deferred = false, initialRefusal = false) {
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const calls: Array<{ action: string; payload: unknown }> = []
  let answerAction: ((outcome: 'success' | 'fail') => void) | undefined
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_SETTINGS)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal' && initialRefusal)
          listener({
            type: 'page_response',
            data: {
              page: HilosPages.LEGAL_SETTINGS,
              payload: {
                data: {},
                refusedWindows: {
                  hilosLegalSettings: { errorCode: 'internal_error' },
                },
              },
            },
          } as never)
        return () => group.delete(listener)
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
        const done = deferred
          ? new Promise<unknown>((resolve, reject) => {
              answerAction = (outcome) => {
                if (outcome === 'success') {
                  resolve({})
                } else {
                  reject(
                    new ActionError(
                      'legal_setting_set',
                      'fail',
                      'Server refused this choice',
                    ),
                  )
                }
              }
            })
          : Promise.reject(
              new ActionError(
                'legal_setting_set',
                'fail',
                'Server refused this choice',
              ),
            )
        return {
          requestId: '1',
          loading: createSignal(false),
          done,
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
    answer(outcome: 'success' | 'fail') {
      answerAction?.(outcome)
    },
    /** The server refuses a later window of the settings table. */
    refuse() {
      for (const listener of listeners.get('tableWindowRefused') ?? [])
        listener({
          type: 'table_window_refused',
          data: {
            page: HilosPages.LEGAL_SETTINGS,
            tableKey: 'hilosLegalSettings',
            errorCode: 'internal_error',
          },
        } as never)
    },
    /**
     * A settings window carrying both rows.
     *
     * @param value The consent setting's value.
     * @param options The refusal setting's value and both defaults.
     */
    push(
      value: unknown,
      {
        refusal = 'freeze',
        consentDefault = 'checkbox',
        refusalDefault = 'freeze',
      }: {
        refusal?: unknown
        consentDefault?: unknown
        refusalDefault?: unknown
      } = {},
    ) {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          data: {
            page: HilosPages.LEGAL_SETTINGS,
            tableKey: 'hilosLegalSettings',
            rows: [
              {
                rowKey: 'legal.consent_form',
                slots: { setting: { value, defaultValue: consentDefault } },
              },
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: {
                  setting: { value: refusal, defaultValue: refusalDefault },
                },
              },
            ],
            totalCount: 2,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
          },
        } as never)
    },
  }
}

/**
 * The one element a selector names; fails the test when it is absent.
 *
 * @param root Where to look.
 * @param selector What to find.
 */
function get<T extends Element = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const element = root.querySelector<T>(selector)
  expect(element, selector).not.toBeNull()

  return element!
}

/**
 * The settings sections of the page by the text of the heading that names them.
 *
 * @param root The mounted page.
 */
function sections(root: HTMLElement): Map<string, HTMLElement> {
  return new Map(
    Array.from(
      root.querySelectorAll<HTMLElement>('section[aria-labelledby]'),
      (section) => [
        document
          .getElementById(section.getAttribute('aria-labelledby') ?? '')
          ?.textContent?.trim() ?? '',
        section,
      ],
    ),
  )
}

afterEach(() => TestBed.resetTestingModule())
describe('legal setting Angular sections', () => {
  it('names both settings sections and draws their live values, defaults and edit controls', async () => {
    const h = harness()
    const page = await mountPage(h)
    h.push('line', { refusal: 'remind' })
    page.fixture.detectChanges()

    expect(page.root.textContent).toContain(
      'Two settings control how consent is given and what happens after a deadline. Document texts and deadlines stay in code.',
    )
    const named = sections(page.root)
    expect(named.size).toBe(2)
    for (const [key, title, hint, value, defaultValue] of [
      [
        'legal.consent_form',
        'Consent at registration',
        'How a person agrees when creating an account.',
        'Line below the button',
        'Checkbox',
      ],
      [
        'legal.refusal_after_deadline',
        'Refusal after the deadline',
        'What happens when the acceptance window ends.',
        'Keep reminding',
        'Freeze access',
      ],
    ] as const) {
      const section = named.get(title)!
      expect(section, title).toBeDefined()
      expect(section.textContent).toContain(hint)
      expect(
        get(
          section,
          `[data-id="legal-setting-value-${key}"]`,
        ).textContent?.trim(),
      ).toBe(value)
      expect(get(section, 'dt').textContent?.trim()).toBe('Default:')
      expect(get(section, 'dd').textContent?.trim()).toBe(defaultValue)
      const edit = get<HTMLButtonElement>(
        section,
        `[data-id="legal-setting-edit-${key}"]`,
      )
      expect(edit.tagName).toBe('BUTTON')
      expect(edit.getAttribute('aria-label')).toBe(`Edit ${title}`)
      expect(edit.disabled).toBe(false)
    }
    expect(page.root.querySelector('input, select, textarea')).toBeNull()

    h.push('checkbox', {
      refusal: 'freeze',
      consentDefault: 'line',
      refusalDefault: 'remind',
    })
    page.fixture.detectChanges()
    expect(
      get(
        page.root,
        '[data-id="legal-setting-value-legal.consent_form"]',
      ).textContent?.trim(),
    ).toBe('Checkbox')
    expect(
      get(named.get('Consent at registration')!, 'dd').textContent?.trim(),
    ).toBe('Line below the button')
    expect(
      get(
        page.root,
        '[data-id="legal-setting-value-legal.refusal_after_deadline"]',
      ).textContent?.trim(),
    ).toBe('Freeze access')
    expect(
      get(named.get('Refusal after the deadline')!, 'dd').textContent?.trim(),
    ).toBe('Keep reminding')

    get<HTMLButtonElement>(
      page.root,
      '[data-id="legal-setting-edit-legal.refusal_after_deadline"]',
    ).click()
    page.fixture.detectChanges()
    await page.fixture.whenStable()
    expect(get(page.root, '[role="dialog"]').getAttribute('aria-label')).toBe(
      'Refusal after the deadline',
    )
    expect(page.input()?.value).toBe('freeze')
    page.fixture.destroy()
  })

  it.each([true, false])(
    'says the standard "unavailable" while the settings table is refused and restores the sections and editor (first response: %s)',
    async (firstResponse) => {
      const h = harness(false, firstResponse)
      const page = await mountPage(h)
      if (!firstResponse) {
        h.push('checkbox')
        page.fixture.detectChanges()
        expect(sections(page.root).size).toBe(2)
        h.refuse()
      }
      page.fixture.detectChanges()
      const unavailable = get(page.root, '[data-id="hilos-table-unavailable"]')
      expect(unavailable.getAttribute('role')).toBe('status')
      expect(
        get(
          unavailable,
          '[data-id="hilos-table-unavailable-title"]',
        ).textContent?.trim(),
      ).toBe('List unavailable')
      expect(sections(page.root).size).toBe(0)
      expect(page.root.querySelector('[data-id="hilos-hidden"]')).toBeNull()
      expect(page.root.textContent).toContain(
        'Two settings control how consent is given',
      )
      expect(page.root.textContent).not.toContain('Server refused this choice')
      expect(page.root.querySelector('[role="dialog"]')).toBeNull()
      expect(h.calls).toHaveLength(0)

      h.push('line', { refusal: 'remind' })
      page.fixture.detectChanges()
      expect(
        page.root.querySelector('[data-id="hilos-table-unavailable"]'),
      ).toBeNull()
      expect(sections(page.root).size).toBe(2)
      expect(
        get(
          page.root,
          '[data-id="legal-setting-value-legal.consent_form"]',
        ).textContent?.trim(),
      ).toBe('Line below the button')
      expect(page.edit().getAttribute('aria-label')).toBe(
        'Edit Consent at registration',
      )
      page.edit().click()
      page.fixture.detectChanges()
      expect(page.input()?.value).toBe('line')
      page.fixture.destroy()
    },
  )
})

describe('legal setting Angular modal', () => {
  it('opens and reopens on the nondefault choice from the live row', async () => {
    const h = harness()
    const page = await mountPage(h)
    h.push('line')
    page.fixture.detectChanges()
    page.edit().click()
    page.fixture.detectChanges()
    expect(page.input()?.value).toBe('line')
    expect(page.input()?.selectedOptions[0]?.value).toBe('line')
    page.cancel().click()
    page.fixture.detectChanges()
    expect(page.input()).toBeNull()
    page.edit().click()
    page.fixture.detectChanges()
    expect(page.input()?.value).toBe('line')
    h.push('checkbox')
    page.fixture.detectChanges()
    expect(page.input()?.value).toBe('checkbox')
    expect(page.input()?.selectedOptions[0]?.value).toBe('checkbox')
    expect(page.save().disabled).toBe(true)
  })

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

  it('locks the legal edit through saving, preserves a refusal and closes on success', async () => {
    const h = harness(true)
    const page = await mountPage(h)
    h.push('checkbox')
    page.fixture.detectChanges()
    page.edit().click()
    page.fixture.detectChanges()
    const field = page.input()!
    field.value = 'line'
    field.dispatchEvent(new Event('change', { bubbles: true }))
    page.fixture.detectChanges()
    const modal = page.root.querySelector<HTMLElement>('[data-id="modal"]')!

    page.save().click()
    page.fixture.detectChanges()

    const close = page.root.querySelector(
      '[data-id="modal-close"]',
    ) as HTMLButtonElement
    const cancel = page.cancel()
    expect(h.calls).toHaveLength(1)
    expect(field.matches(':disabled')).toBe(true)
    expect(page.input()).toBe(field)
    expect(field.value).toBe('line')
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    close.click()
    cancel.click()
    modal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    modal.click()
    page.fixture.detectChanges()
    expect(page.root.querySelector('[data-id="modal"]')).toBe(modal)
    expect(page.root.querySelector('[data-id="modal-confirm"]')).toBeNull()

    h.answer('fail')
    await new Promise((resolve) => setTimeout(resolve, 0))
    await page.fixture.whenStable()
    page.fixture.detectChanges()
    expect(page.root.querySelector('[data-id="modal"]')).toBe(modal)
    expect(page.input()).toBe(field)
    expect(field.value).toBe('line')
    expect(field.matches(':disabled')).toBe(false)
    expect(close.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(page.root.querySelector('[data-id="modal-confirm"]')).toBeNull()

    page.save().click()
    page.fixture.detectChanges()
    expect(h.calls).toHaveLength(2)
    h.answer('success')
    await new Promise((resolve) => setTimeout(resolve, 0))
    await page.fixture.whenStable()
    page.fixture.detectChanges()
    expect(page.root.querySelector('[data-id="modal"]')).toBeNull()
    page.fixture.destroy()
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
    h.push(
      { _hidden: true },
      {
        refusal: { _hidden: true },
        consentDefault: { _hidden: true },
        refusalDefault: { _hidden: true },
      },
    )
    page.fixture.detectChanges()

    const cell = page.root.querySelector(
      '[data-id="legal-setting-value-legal.consent_form"]',
    )
    expect(
      cell?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    for (const key of ['legal.consent_form', 'legal.refusal_after_deadline']) {
      expect(
        get(
          page.root,
          `[data-id="legal-setting-value-${key}"]`,
        ).textContent?.trim(),
      ).toBe('Hidden')
    }
    for (const section of sections(page.root).values()) {
      expect(get(section, 'dt').textContent?.trim()).toBe('Default:')
      expect(get(section, 'dd').textContent?.trim()).toBe('Hidden')
    }
    expect(page.root.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(
      4,
    )

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
