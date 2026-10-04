// The Angular peer of the Vue/React settings-page live-merge tests (HIL-986).
// The merge itself lives in the core row-edit helper; this file covers the thin view:
// that a live row update reloads a pristine modal, that a dirty one shows the
// conflict chrome without Merge, and that Take theirs adopts the incoming value;
// the ↺ that resets only through a confirm dialog (HIL-1147); and what a viewer
// of the admin view mode finds there — the hidden mark in place of a value, and
// nothing to send it with (HIL-1272).
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  Hideable,
  HilosConnection,
  HilosRouter,
  HilosSettingsContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosSettingsPage } from '../src/admin/settings/HilosSettingsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SETTINGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface SettingSlot {
  key: string
  type: string
  value: Hideable<string | null>
  overrideValue: Hideable<string | null>
  defaultValue: Hideable<string | null>
  defaultReferenceKey: Hideable<string | null>
  valueSource: string
}

function slot(
  over: Partial<SettingSlot> & { key: string; valueSource: string },
): SettingSlot {
  return {
    type: 'string',
    value: 'v',
    overrideValue: null,
    defaultValue: 'd',
    defaultReferenceKey: null,
    ...over,
  }
}

function seededContext(initial: SettingSlot[]): {
  context: HilosSettingsContext
  pushUpdate: (next: SettingSlot) => void
  pushRemove: (key: string) => void
  pushLeave: (next: SettingSlot) => void
  answer: (outcome: 'success' | 'fail') => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SETTINGS)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (
    page: string = HilosPages.SETTINGS,
    tableKey: string = 'settings',
  ): void => {
    const data = {
      page,
      tableKey,
      rows: rows.map((settings) => ({
        rowKey: settings.key,
        slots: { settings },
      })),
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(page: string, tableKey: string): boolean {
      serveWindow(page, tableKey)

      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection: connection as unknown as HilosSettingsContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(next: SettingSlot): void {
      rows = rows.map((row) => (row.key === next.key ? next : row))
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_updated',
            rowKey: next.key,
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
    },
    pushRemove(key: string): void {
      rows = rows.filter((row) => row.key !== key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: key,
            reason: 'deleted',
          },
        })
      }
    },
    // The row left this tab's window alive — out of its search, past its edge — and the frame
    // that takes it off the screen carries the row for the dialog holding it in focus.
    pushLeave(next: SettingSlot): void {
      rows = rows.filter((row) => row.key !== next.key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: next.key,
            reason: 'left_set',
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
    },
    // Answer the last action sent, the way the server replies to it.
    answer(outcome: 'success' | 'fail'): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The setting refused the reset.',
        })
      }
    },
    sent,
    focus,
  }
}

function mountPage(
  context: HilosSettingsContext,
): ComponentFixture<HilosSettingsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSettingsPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

function el(root: HTMLElement, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

describe('HilosSettingsPage', () => {
  it('renders a row per setting with its key', () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelectorAll('[data-id^="hilos-table-row-"]').length).toBe(
      1,
    )
    expect(root.textContent).toContain('site_name')
  })

  it('draws a cell under every declared column, aligned the way the column says', () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    const cells = Array.from(
      root.querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-row-site_name"] td',
      ),
    )

    // The page writes the content only; the cell and its class come from the
    // declaration, so the row's controls stay right-aligned without the page
    // saying so.
    expect(cells).toHaveLength(root.querySelectorAll('thead th').length)
    expect(cells[0]?.querySelector('code')?.textContent).toBe('site_name')
    expect(
      cells[2]?.querySelector('[data-id="hilos-settings-edit-site_name"]'),
    ).not.toBeNull()
    expect(cells[2]?.classList.contains('text-end')).toBe(true)
  })

  it('reloads a pristine edit when the live row changes elsewhere', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    const input = el(root, 'hilos-settings-edit-value') as HTMLInputElement
    expect(input.value).toBe('Hilos')

    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Elsewhere',
        overrideValue: 'Elsewhere',
      }),
    )
    fixture.detectChanges()

    expect(input.value).toBe('Elsewhere')
    expect(el(root, 'conflict-badge')).toBeNull()
    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(
      (el(root, 'hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)

    // The person types over the taken value: the note about it goes out.
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    expect(el(root, 'hilos-settings-edit-notice')).toBeNull()
  })

  it('holds the room for the message line while nothing happened elsewhere', () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()

    expect(el(root, 'hilos-settings-edit-notice')).toBeNull()
    expect(el(root, 'hilos-settings-edit-notice-idle')).not.toBeNull()
  })

  it('leaves the effective value under the switch after a reset elsewhere', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
        defaultValue: 'Default',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()

    // The other side reset the key: the switch goes off and the dialog says so.
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'default',
        value: 'Default',
        overrideValue: null,
        defaultValue: 'Default',
      }),
    )
    fixture.detectChanges()
    const custom = el(root, 'hilos-settings-edit-custom') as HTMLInputElement
    expect(custom.checked).toBe(false)
    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )

    // Turning the switch back on starts from the value now in effect, not from
    // the override the other side just removed.
    custom.click()
    fixture.detectChanges()
    expect(
      (el(root, 'hilos-settings-edit-value') as HTMLInputElement).value,
    ).toBe('Default')
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    const input = el(root, 'hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    fixture.detectChanges()

    input.form?.dispatchEvent(new Event('submit', { cancelable: true }))
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(root, 'conflict-badge')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit and hides merge', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    const input = el(root, 'hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    fixture.detectChanges()

    expect(el(root, 'conflict-badge')).not.toBeNull()
    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(el(root, 'conflict-merge')).toBeNull()
    expect(
      (el(root, 'hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('Take theirs sets the field to the live value and locks save', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    const input = el(root, 'hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    fixture.detectChanges()
    el(root, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(input.value).toBe('Theirs')
    expect(el(root, 'conflict-badge')).toBeNull()
    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(
      (el(root, 'hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('locks save and names the gone row when it is removed under the modal', () => {
    const { context, pushRemove } = seededContext([
      slot({
        key: 'legacy',
        valueSource: 'orphan',
        value: 'x',
        overrideValue: 'x',
        defaultValue: null,
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-legacy')?.click()
    fixture.detectChanges()
    pushRemove('legacy')
    fixture.detectChanges()

    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    const save = el(root, 'hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.textContent?.trim()).toBe('Deleted')
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', () => {
    const { context, pushLeave } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    // The other tab's save took the row out of this tab's search: the screen gates the
    // departure, the dialog holding the row in focus reads the body off the same frame.
    pushLeave(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    fixture.detectChanges()
    fixture.detectChanges()

    expect(
      (el(root, 'hilos-settings-edit-value') as HTMLInputElement).value,
    ).toBe('Theirs')
    expect(el(root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(
      (
        el(root, 'hilos-settings-edit-save') as HTMLButtonElement
      ).textContent?.trim(),
    ).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', () => {
    const { context, focus } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    el(root, 'hilos-settings-edit-site_name')?.click()
    fixture.detectChanges()
    expect(focus).toEqual(['site_name'])

    el(root, 'modal-close')?.click()
    fixture.detectChanges()
    expect(focus).toEqual(['site_name', ''])
  })
})

describe('HilosSettingsPage reset dialog', () => {
  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: 'Mine',
    overrideValue: 'Mine',
    defaultValue: 'Hilos',
  })

  function resetButton(
    root: HTMLElement,
    key: string,
  ): HTMLButtonElement | null {
    return root.querySelector(`table [data-id="hilos-settings-reset-${key}"]`)
  }

  function openReset(
    context: HilosSettingsContext,
  ): ComponentFixture<HilosSettingsPage> {
    const fixture = mountPage(context)
    resetButton(fixture.nativeElement as HTMLElement, 'site_name')?.click()
    fixture.detectChanges()

    return fixture
  }

  function cancelButton(root: HTMLElement): HTMLButtonElement {
    return Array.from(
      root.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  /** Answer the action and let the page take the reply. */
  async function reply(
    fixture: ComponentFixture<unknown>,
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    answer(outcome)
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()
  }

  it('offers ↺ on a catalog row, locked on its default, and none on an orphan', () => {
    const fixture = mountPage(
      seededContext([
        CUSTOM,
        slot({ key: 'on_default', valueSource: 'default' }),
        slot({
          key: 'orphan',
          valueSource: 'orphan',
          overrideValue: 'x',
          defaultValue: null,
        }),
      ]).context,
    )
    const root = fixture.nativeElement as HTMLElement

    expect(resetButton(root, 'site_name')?.disabled).toBe(false)
    expect(resetButton(root, 'on_default')?.disabled).toBe(true)
    expect(resetButton(root, 'orphan')).toBeNull()
    expect(
      root.querySelector('table [data-id="hilos-settings-delete-orphan"]'),
    ).not.toBeNull()
  })

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    const root = openReset(context).nativeElement as HTMLElement

    expect(el(root, 'modal')?.textContent).toContain('Reset · site_name')
    expect(focus).toEqual(['site_name'])
    expect(sent).toEqual([])
  })

  it('shows the own value now and the catalog default it goes back to', () => {
    const { context } = seededContext([CUSTOM])
    const root = openReset(context).nativeElement as HTMLElement

    expect(el(root, 'hilos-settings-reset-now')?.textContent).toContain('Mine')
    const back = el(root, 'hilos-settings-reset-default')
    expect(back?.textContent).toContain('Hilos')
    expect(back?.textContent).not.toContain('custom')
  })

  it('names the referenced key when the default is a reference', () => {
    const { context } = seededContext([
      { ...CUSTOM, defaultReferenceKey: 'app.title' },
    ])
    const root = openReset(context).nativeElement as HTMLElement

    expect(el(root, 'hilos-settings-reset-default')?.textContent).toContain(
      'app.title',
    )
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([CUSTOM])
    const fixture = openReset(context)
    const root = fixture.nativeElement as HTMLElement

    el(root, 'hilos-settings-reset-confirm')?.click()
    fixture.detectChanges()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'setting_reset', payload: { key: 'site_name' } },
    ])

    await reply(fixture, answer, 'success')
    expect(el(root, 'modal')).toBeNull()
    expect(focus).toEqual(['site_name', ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    const fixture = openReset(context)
    const root = fixture.nativeElement as HTMLElement

    cancelButton(root).click()
    fixture.detectChanges()

    expect(el(root, 'modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual(['site_name', ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([CUSTOM])
    const fixture = openReset(context)
    const root = fixture.nativeElement as HTMLElement

    el(root, 'hilos-settings-reset-confirm')?.click()
    fixture.detectChanges()
    await reply(fixture, answer, 'fail')

    expect(el(root, 'modal')).not.toBeNull()
    expect(el(root, 'hilos-action-error')?.textContent).toContain(
      'The setting refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext([CUSTOM])
    const fixture = openReset(context)
    const root = fixture.nativeElement as HTMLElement
    expect(el(root, 'hilos-settings-reset-gone')).toBeNull()

    pushUpdate({
      ...CUSTOM,
      valueSource: 'default',
      value: 'Hilos',
      overrideValue: null,
    })
    fixture.detectChanges()

    expect(el(root, 'hilos-settings-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(
      (el(root, 'hilos-settings-reset-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
  })
})

describe('HilosSettingsPage in the admin view mode', () => {
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

  // The row's controls stand in the document twice — once in the table and once
  // in the card the same row becomes on a narrow screen — so a row button is
  // looked up through the table, which says which of the two is clicked.
  function rowButton(root: HTMLElement, id: string): HTMLButtonElement | null {
    return root.querySelector(`table [data-id="${id}"]`)
  }

  function cancelButton(root: HTMLElement): HTMLButtonElement | undefined {
    return Array.from(
      root.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
  }

  it('a viewer opens a setting, sees the hidden mark instead of the input, and finds Save disabled', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    const edit = rowButton(root, 'hilos-settings-edit-site_name')
    expect(edit?.disabled).toBe(false)
    edit?.click()
    fixture.detectChanges()

    expect(el(root, 'hilos-settings-edit-value')).toBeNull()
    expect(
      el(root, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()

    const save = el(root, 'hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)

    save.click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = el(root, 'hilos-settings-edit-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    cancel.click()
    fixture.detectChanges()
    expect(el(root, 'modal')).toBeNull()
  })

  it('a viewer opens the reset of a setting and has nothing to reset it with', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
        defaultValue: { _hidden: true },
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    const reset = rowButton(root, 'hilos-settings-reset-site_name')
    expect(reset?.disabled).toBe(false)
    reset?.click()
    fixture.detectChanges()

    expect(
      el(root, 'hilos-settings-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      el(root, 'hilos-settings-reset-default')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    const confirm = el(
      root,
      'hilos-settings-reset-confirm',
    ) as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirm.click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    cancelButton(root)?.click()
    fixture.detectChanges()

    expect(el(root, 'modal')).toBeNull()
  })

  it('a viewer opens the deletion of an orphan and has nothing to delete it with', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'orphan',
        valueSource: 'orphan',
        overrideValue: { _hidden: true },
        defaultValue: null,
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    const del = rowButton(root, 'hilos-settings-delete-orphan')
    expect(del?.disabled).toBe(false)
    del?.click()
    fixture.detectChanges()

    const confirm = el(
      root,
      'hilos-settings-delete-confirm',
    ) as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirm.click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    cancelButton(root)?.click()
    fixture.detectChanges()

    expect(el(root, 'modal')).toBeNull()
  })

  it('an admin on a node in the mode edits a setting as today', () => {
    bindSession().handshake({ id: 1, admin: true }, true)
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    const edit = rowButton(root, 'hilos-settings-edit-site_name')
    expect(edit?.disabled).toBe(false)
    edit?.click()
    fixture.detectChanges()

    const input = el(root, 'hilos-settings-edit-value') as HTMLInputElement
    expect(input.disabled).toBe(false)
    input.value = 'Other'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()

    const save = el(root, 'hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(false)
    expect(save.getAttribute('aria-describedby')).toBeNull()
  })
})
