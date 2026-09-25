// The Angular peer of the Vue/React settings-page live-merge tests (HIL-986).
// The merge itself lives in the core row-edit helper; this file covers the thin view:
// that a live row update reloads a pristine modal, that a dirty one shows the
// conflict chrome without Merge, and that Take theirs adopts the incoming value.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosSettingsContext,
  PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface SettingSlot {
  key: string
  type: string
  value: string | null
  overrideValue: string | null
  defaultValue: string | null
  defaultReferenceKey: string | null
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
  const actions = new ActionLifecycle({
    sendAction: () => false,
    on: () => () => {},
  })

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
