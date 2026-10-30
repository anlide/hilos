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
  createAppPageRouter,
  createSignal,
} from '@hilos/core'
import type {
  Hideable,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  HilosSettingsContext,
  PageRouteMatch,
  ProjectSignal,
  TableViewportDescriptor,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'

import { HilosSettingsPage } from '../src/admin/settings/HilosSettingsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const SETTINGS_IDENTITY: HilosPageIdentity = {
  label: 'Settings',
  lead: 'Application setting catalog: catalog defaults, overrides, and references.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.SETTINGS, label: 'Settings' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.SETTINGS,
    label: 'Settings',
    lead: 'Application setting catalog: catalog defaults, overrides, and references.',
    icon: 'bi-sliders',
  },
  pageTabs: [{ page: HilosPages.SETTINGS, label: 'Overview' }],
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SETTINGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/settings'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: createAppPageRouter({}).path,
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
  disconnect: () => void
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
    descriptor?: TableViewportDescriptor,
  ): void => {
    const search = String(descriptor?.filter.search ?? '')
    const shown = rows.filter((row) => row.key.includes(search))
    const data = {
      page,
      tableKey,
      requestId: descriptor?.requestId,
      rows: shown.map((settings) => ({
        rowKey: settings.key,
        slots: { settings },
      })),
      totalCount: shown.length,
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
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: TableViewportDescriptor,
    ): boolean {
      serveWindow(page, tableKey, descriptor)

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
  const stateListeners = new Set<(state: string) => void>()
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
      if (event === 'state') {
        const stateListener = listener as unknown as (state: string) => void
        stateListeners.add(stateListener)

        return () => stateListeners.delete(stateListener)
      }
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
    disconnect(): void {
      for (const listener of stateListeners) listener('disconnected')
    },
    sent,
    focus,
  }
}

function mountPage(
  context: HilosSettingsContext,
  navigation: HilosRouter = router(SETTINGS_IDENTITY),
): ComponentFixture<HilosSettingsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: navigation }],
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
  it('replaces the section skeleton with the adopted Settings head and names the live table', () => {
    const { context } = seededContext([
      slot({ key: 'site_name', valueSource: 'default' }),
    ])
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const navigation = { ...router(undefined), pageIdentity: identity }
    const fixture = mountPage(context, navigation)
    const root = fixture.nativeElement as HTMLElement

    const skeleton = el(root, 'hilos-page-head-skeleton')
    expect(skeleton).not.toBeNull()
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton?.querySelector('.rounded-circle')).not.toBeNull()
    expect(skeleton?.querySelector('.rounded-3')).toBeNull()
    expect(root.querySelector('h1')).toBeNull()
    expect(el(root, 'hilos-page-head-block')).toBeNull()
    expect(el(root, 'hilos-breadcrumb')).toBeNull()
    expect(el(root, 'hilos-page-tabs')).toBeNull()
    expect(el(root, 'hilos-admin-children')).toBeNull()
    expect(el(root, 'hilos-admin-title-skeleton')).toBeNull()
    expect(root.querySelector('.bi-sliders')).toBeNull()
    expect(root.textContent).not.toContain('Settings')
    expect(root.textContent).not.toContain(SETTINGS_IDENTITY.lead)

    identity.set(SETTINGS_IDENTITY)
    fixture.detectChanges()

    expect(root.querySelectorAll('[data-id="hilos-page-head"]')).toHaveLength(1)
    expect(root.querySelectorAll('h1')).toHaveLength(1)
    const heading = root.querySelector('h1') as HTMLHeadingElement
    expect(heading.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(heading.textContent?.trim()).toBe('Settings')
    const block = el(root, 'hilos-page-head-block') as HTMLElement
    const circle = block.querySelector(':scope > .rounded-circle')
    expect(circle?.getAttribute('aria-hidden')).toBe('true')
    expect(circle?.querySelector('i')?.className).toBe('bi bi-sliders')
    expect(block.querySelectorAll('p')).toHaveLength(1)
    expect(block.querySelector('p')?.textContent?.trim()).toBe(
      'Application setting catalog: catalog defaults, overrides, and references.',
    )
    expect(root.textContent?.split(SETTINGS_IDENTITY.lead)).toHaveLength(2)

    const crumbs = el(root, 'hilos-breadcrumb') as HTMLElement
    expect(crumbs.getAttribute('aria-label')).toBe('Breadcrumb')
    const items = crumbs.querySelectorAll('li')
    expect(items).toHaveLength(2)
    expect(items[0].textContent?.trim()).toBe('Hilos')
    expect(items[0].querySelector('a')?.getAttribute('href')).toBe('/hilos')
    expect(items[0].hasAttribute('aria-current')).toBe(false)
    expect(items[0].querySelector('a')?.hasAttribute('aria-current')).toBe(
      false,
    )
    expect(items[1].textContent?.replace('/', '').trim()).toBe('Settings')
    expect(items[1].getAttribute('aria-current')).toBe('page')
    expect(items[1].querySelector('a')).toBeNull()
    expect(el(root, 'hilos-page-tabs')).toBeNull()
    expect(el(root, 'hilos-admin-children')).toBeNull()
    expect(el(root, 'hilos-admin-title')).toBeNull()
    expect(el(root, 'hilos-page-head-skeleton')).toBeNull()
    expect(el(root, 'hilos-admin-empty')).toBeNull()
    expect(
      root.querySelector('hilos-page-head')?.nextElementSibling?.tagName,
    ).toBe('HILOS-VIEWPORT-TABLE')

    expect(heading.id).toBeTruthy()
    expect(document.getElementById(heading.id)).toBe(heading)
    expect(root.querySelector('table')?.getAttribute('aria-labelledby')).toBe(
      heading.id,
    )
    expect(
      root
        .querySelector('[data-id="hilos-table-cards"] [role="list"]')
        ?.getAttribute('aria-labelledby'),
    ).toBe(heading.id)
    expect(root.querySelectorAll('[data-id^="hilos-table-row-"]')).toHaveLength(
      1,
    )
    expect(root.querySelector('table')?.textContent).toContain('site_name')
  })

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

describe('HilosSettingsPage typed editor', () => {
  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: '1',
    overrideValue: '1',
  })

  afterEach(() => vi.useRealTimers())

  function open(initial: SettingSlot[] = [CUSTOM], key = initial[0]!.key) {
    const source = seededContext(initial)
    const fixture = mountPage(source.context)
    const root = fixture.nativeElement as HTMLElement
    el(root, `hilos-settings-edit-${key}`)!.click()
    fixture.detectChanges()
    const field = () =>
      el(root, 'hilos-settings-edit-value') as HTMLInputElement
    const click = (id: string) => {
      el(root, id)!.click()
      fixture.detectChanges()
    }
    const type = (text: string) => {
      field().value = text
      field().dispatchEvent(new Event('input', { bubbles: true }))
      fixture.detectChanges()
    }
    const submit = () => {
      root
        .querySelector('form')!
        .dispatchEvent(new Event('submit', { cancelable: true }))
      fixture.detectChanges()
    }
    const reply = async (outcome: 'success' | 'fail' = 'fail') => {
      source.answer(outcome)
      await vi.advanceTimersByTimeAsync(0)
      fixture.detectChanges()
    }

    return { ...source, fixture, root, field, click, type, submit, reply }
  }

  function expectInvalid(field: HTMLInputElement, invalid: boolean): void {
    expect(field.classList.contains('is-invalid')).toBe(invalid)
    expect(field.getAttribute('aria-invalid')).toBe(invalid ? 'true' : null)
    expect(field.getAttribute('aria-describedby')).toBe(
      invalid ? 'hilos-settings-edit-error' : null,
    )
  }

  it.each([
    ['string', 'text', null],
    ['integer', 'number', null],
    ['float', 'number', 'any'],
    ['boolean', 'checkbox', null],
  ] as const)(
    'shows server type %s beside the wrapping conflict title',
    (type, inputType, step) => {
      const key = 'a_very_long_setting_key_'.repeat(8)
      const { root, field } = open([{ ...CUSTOM, key, type }])
      const badge = el(root, 'hilos-settings-edit-type')!
      expect(badge.textContent?.trim()).toBe(type)
      expect(badge.className).toBe(
        'font-monospace small px-1 border rounded bg-body-tertiary text-body-secondary flex-shrink-0 ms-auto',
      )
      const header = badge.parentElement!
      expect(header.classList.contains('flex-grow-1')).toBe(true)
      expect(header.classList.contains('min-w-0')).toBe(true)
      const title = header.querySelector('h5')!
      expect(title.textContent?.trim()).toBe(`Edit · ${key}`)
      expect(title.matches('.text-break.min-w-0')).toBe(true)
      expect(
        header.parentElement?.querySelector('[data-id="modal-close"]'),
      ).not.toBeNull()
      expect(header.contains(el(root, 'modal-close'))).toBe(false)
      expect(field().type).toBe(inputType)
      expect(field().getAttribute('step')).toBe(step)
    },
  )

  it('omits an empty server type without guessing from the value', () => {
    const { root, field } = open([{ ...CUSTOM, type: '' }])
    expect(el(root, 'hilos-settings-edit-type')).toBeNull()
    expect(field().type).toBe('text')
  })

  it('holds the modal on session saving after its tracked action settles', async () => {
    vi.useFakeTimers()
    const view = open()
    const field = view.field()
    view.type('2')
    view.submit()

    const modal = el(view.root, 'modal')!
    const close = el(view.root, 'modal-close') as HTMLButtonElement
    const cancel = el(
      view.root,
      'hilos-settings-edit-cancel',
    ) as HTMLButtonElement
    const save = el(view.root, 'hilos-settings-edit-save') as HTMLButtonElement
    expect(view.sent).toHaveLength(1)
    expect(field.matches(':disabled')).toBe(true)
    expect(view.field()).toBe(field)
    expect(field.value).toBe('2')
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)

    close.click()
    cancel.click()
    modal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    modal.click()
    view.fixture.detectChanges()
    expect(el(view.root, 'modal')).toBe(modal)
    expect(el(view.root, 'modal-confirm')).toBeNull()

    view.answer('fail')
    await Promise.resolve()
    view.fixture.detectChanges()
    expect(save.getAttribute('aria-busy')).toBeNull()
    expect(field.matches(':disabled')).toBe(true)
    await Promise.resolve()
    view.fixture.detectChanges()

    expect(el(view.root, 'modal')).toBe(modal)
    expect(view.field()).toBe(field)
    expect(field.value).toBe('2')
    expect(field.matches(':disabled')).toBe(false)
    expect(el(view.root, 'modal-confirm')).toBeNull()

    view.submit()
    await view.reply('success')
    expect(el(view.root, 'modal')).toBeNull()
  })

  it.each(['string', 'integer', 'float'])(
    'keeps the same %s input, notice and zero draft through custom off/on',
    async (type) => {
      vi.useFakeTimers()
      const view = open([
        {
          ...CUSTOM,
          type,
          valueSource: 'default',
          value: '5',
          overrideValue: null,
          defaultValue: '5',
        },
      ])
      const field = view.field()
      const group = field.parentElement!
      const notice = el(view.root, 'hilos-settings-edit-notice-idle')
      expect(field.value).toBe('5')
      expect(field.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(notice).not.toBeNull()
      view.click('hilos-settings-edit-custom')
      expect(view.field()).toBe(field)
      expect(field.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      view.type('0')
      view.click('hilos-settings-edit-custom')
      expect(view.field()).toBe(field)
      expect(field.value).toBe('0')
      expect(field.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(el(view.root, 'hilos-settings-edit-notice-idle')).toBe(notice)
      view.click('hilos-settings-edit-custom')
      expect(view.field()).toBe(field)
      expect(field.value).toBe('0')
      expect(field.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      view.click('hilos-settings-edit-save')
      expect(view.sent[0]).toMatchObject({
        action: 'setting_add',
        payload: { key: 'site_name', value: '0' },
      })
      await view.reply('success')
      expect(el(view.root, 'modal')).toBeNull()
    },
  )

  it.each(['default', 'override'])(
    'keeps the same boolean node and false draft from %s through off/on',
    async (valueSource) => {
      vi.useFakeTimers()
      const view = open([
        {
          ...CUSTOM,
          type: 'boolean',
          valueSource,
          value: '0',
          overrideValue: valueSource === 'default' ? null : '0',
        },
      ])
      const field = view.field()
      const group = field.parentElement!.parentElement!
      expect(field.checked).toBe(false)
      expect(field.disabled).toBe(valueSource === 'default')
      expect(group.classList.contains('opacity-50')).toBe(
        valueSource === 'default',
      )
      if (valueSource === 'default') view.click('hilos-settings-edit-custom')
      view.click('hilos-settings-edit-value')
      expect(field.checked).toBe(true)
      view.click('hilos-settings-edit-value')
      expect(field.checked).toBe(false)
      view.click('hilos-settings-edit-custom')
      expect(view.field()).toBe(field)
      expect(field.checked).toBe(false)
      expect(field.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(el(view.root, 'hilos-settings-edit-notice-idle')).not.toBeNull()
      view.click('hilos-settings-edit-custom')
      expect(view.field()).toBe(field)
      expect(field.checked).toBe(false)
      expect(field.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      if (valueSource === 'default') {
        view.submit()
        expect(view.sent[0]).toMatchObject({
          action: 'setting_add',
          payload: { key: 'site_name', value: '0' },
        })
        await view.reply('success')
      }
    },
  )

  it.each(['string', 'integer', 'float', 'boolean'])(
    'marks a real %s refusal, clears on rerun and form change, and marks a new refusal',
    async (type) => {
      vi.useFakeTimers()
      const view = open([{ ...CUSTOM, type }])
      const field = view.field()
      if (type === 'boolean') view.click('hilos-settings-edit-value')
      else view.type('2')
      const draft = type === 'boolean' ? field.checked : field.value
      const error = view.root.querySelector('#hilos-settings-edit-error')!
      expect(error).not.toBeNull()
      expect(error.hasAttribute('role')).toBe(false)
      expect(error.hasAttribute('aria-live')).toBe(false)
      expectInvalid(field, false)
      view.submit()
      await view.reply()
      expect(el(view.root, 'modal')).not.toBeNull()
      expect(view.field()).toBe(field)
      expect(type === 'boolean' ? field.checked : field.value).toBe(draft)
      expectInvalid(field, true)
      expect(
        field.classList.contains(
          type === 'boolean' ? 'form-check-input' : 'form-control',
        ),
      ).toBe(true)
      expect(error.contains(el(view.root, 'hilos-action-error'))).toBe(true)
      expect(error.textContent).toContain('The setting refused the reset.')
      view.submit()
      expect(view.sent).toHaveLength(2)
      expectInvalid(field, false)
      expect(el(view.root, 'hilos-action-error')).toBeNull()
      await view.reply()
      expectInvalid(field, true)
      if (type === 'boolean') view.click('hilos-settings-edit-value')
      else view.type('3')
      expectInvalid(field, false)
      expect(el(view.root, 'hilos-action-error')).toBeNull()
      expect(view.root.querySelector('#hilos-settings-edit-error')).toBe(error)
    },
  )

  it.each(['timeout', 'disconnected', 'invalid-reply'])(
    'keeps a %s message without invalid styling',
    async (outcome) => {
      vi.useFakeTimers()
      const view = open()
      if (outcome === 'invalid-reply') {
        const dispatch = view.context.actions.dispatch.bind(
          view.context.actions,
        )
        vi.spyOn(view.context.actions, 'dispatch').mockImplementation(
          (action, data) => dispatch(action, data, { replySchema: z.string() }),
        )
      }
      view.type('Mine')
      const field = view.field()
      view.submit()
      if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30000)
      else if (outcome === 'disconnected') {
        view.disconnect()
        await vi.advanceTimersByTimeAsync(0)
      } else await view.reply('success')
      view.fixture.detectChanges()
      expect(el(view.root, 'modal')).not.toBeNull()
      expect(view.field()).toBe(field)
      expect(field.value).toBe('Mine')
      expectInvalid(field, false)
      const message =
        outcome === 'timeout'
          ? 'The action timed out. Please try again.'
          : outcome === 'disconnected'
            ? 'The connection dropped before the action completed. Please try again.'
            : 'The server answered in a form this page could not read. Please try again.'
      expect(el(view.root, 'hilos-action-error')?.textContent).toContain(
        message,
      )
    },
  )

  it('sends reset without draft text and leaves its refused disabled field unmarked', async () => {
    vi.useFakeTimers()
    const view = open()
    view.type('Unsaved draft')
    const field = view.field()
    view.click('hilos-settings-edit-custom')
    view.submit()
    expect(view.sent[0]).toMatchObject({
      action: 'setting_reset',
      payload: { key: 'site_name' },
    })
    await view.reply()
    expect(view.field()).toBe(field)
    expect(field.value).toBe('Unsaved draft')
    expect(field.disabled).toBe(true)
    expectInvalid(field, false)
    expect(el(view.root, 'hilos-action-error')).not.toBeNull()
    view.click('hilos-settings-edit-custom')
    expect(field.value).toBe('Unsaved draft')
    expectInvalid(field, false)
    expect(el(view.root, 'hilos-action-error')).toBeNull()
  })

  it('keeps refusal on conflict-only and Keep mine, clearing when Take theirs changes the form', async () => {
    vi.useFakeTimers()
    const view = open()
    view.type('Mine')
    view.submit()
    await view.reply()
    const field = view.field()
    view.pushUpdate({ ...CUSTOM, value: 'Theirs', overrideValue: 'Theirs' })
    view.fixture.detectChanges()
    expect(field.value).toBe('Mine')
    expect(el(view.root, 'conflict-badge')).not.toBeNull()
    expect(el(view.root, 'hilos-settings-edit-type')?.textContent?.trim()).toBe(
      'string',
    )
    expectInvalid(field, true)
    view.click('conflict-accept-mine')
    expect(el(view.root, 'conflict-badge')).toBeNull()
    expectInvalid(field, true)
    expect(el(view.root, 'hilos-action-error')).not.toBeNull()
    view.pushUpdate({ ...CUSTOM, value: 'Next', overrideValue: 'Next' })
    view.fixture.detectChanges()
    view.click('conflict-accept-theirs')
    expect(field.value).toBe('Next')
    expectInvalid(field, false)
    expect(el(view.root, 'hilos-action-error')).toBeNull()
  })

  it('clears refusal only when automatic live merge actually changes form scalars', async () => {
    vi.useFakeTimers()
    const view = open()
    view.type('Mine')
    view.submit()
    await view.reply()
    const field = view.field()
    view.pushUpdate({ ...CUSTOM, value: 'Mine', overrideValue: 'Mine' })
    view.fixture.detectChanges()
    expectInvalid(field, true)
    expect(el(view.root, 'hilos-action-error')).not.toBeNull()
    view.pushUpdate({ ...CUSTOM, value: 'Next', overrideValue: 'Next' })
    view.fixture.detectChanges()
    expect(view.field()).toBe(field)
    expect(field.value).toBe('Next')
    expect(el(view.root, 'hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expectInvalid(field, false)
    expect(el(view.root, 'hilos-action-error')).toBeNull()
  })

  it('clears refusal when a live reset changes the custom switch and form', async () => {
    vi.useFakeTimers()
    const view = open()
    view.type('Mine')
    view.submit()
    await view.reply()
    const field = view.field()
    view.pushUpdate({ ...CUSTOM, value: 'Mine', overrideValue: 'Mine' })
    view.fixture.detectChanges()
    expect(el(view.root, 'hilos-action-error')).not.toBeNull()
    view.pushUpdate({
      ...CUSTOM,
      valueSource: 'default',
      value: 'd',
      overrideValue: null,
    })
    view.fixture.detectChanges()
    expect(view.field()).toBe(field)
    expect(field.disabled).toBe(true)
    expect(field.value).toBe('d')
    expectInvalid(field, false)
    expect(el(view.root, 'hilos-action-error')).toBeNull()
  })

  it('clears an old refusal on opening a hidden orphan without exposing a draft', async () => {
    vi.useFakeTimers()
    const hidden = { _hidden: true } as const
    const orphan = slot({
      key: 'hidden_orphan',
      valueSource: 'orphan',
      value: hidden,
      overrideValue: hidden,
      defaultValue: null,
    })
    const view = open([CUSTOM, orphan])
    view.type('Mine')
    view.submit()
    await view.reply()
    expectInvalid(view.field(), true)
    view.click('modal-close')
    view.click('modal-confirm-discard')
    view.click('hilos-settings-edit-hidden_orphan')
    expect(view.field()).toBeNull()
    expect(el(view.root, 'hilos-settings-edit-custom')).toBeNull()
    expect(
      el(view.root, 'hilos-settings-edit-orphan')?.textContent?.trim(),
    ).toBe('not in catalog')
    expect(
      el(view.root, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(el(view.root, 'modal')?.textContent).not.toContain('Mine')
    expect(el(view.root, 'hilos-action-error')).toBeNull()
    expect(el(view.root, 'modal')?.querySelector('.is-invalid')).toBeNull()
    expect(view.sent).toHaveLength(1)
  })
})

describe('HilosSettingsPage orphan and empty states', () => {
  const ORPHAN = slot({
    key: 'legacy',
    valueSource: 'orphan',
    value: 'legacy value',
    overrideValue: 'legacy value',
    defaultValue: null,
  })

  afterEach(() => vi.useRealTimers())

  it('labels an orphan in table and cards and above the editor, then updates it', async () => {
    const { context, sent, answer } = seededContext([ORPHAN])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const part = root.querySelector(projection)!
      const badges = part.querySelectorAll(
        '[data-id="hilos-setting-not-in-catalog"]',
      )
      expect(badges).toHaveLength(1)
      expect(badges[0]!.textContent?.trim()).toBe('not in catalog')
      expect(badges[0]!.getAttribute('title')).toBe(
        'This key is not in the project catalog',
      )
      expect(badges[0]!.className).toBe(
        'badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle flex-shrink-0',
      )
      expect(part.textContent).toContain('legacy value')
    }
    el(root, 'hilos-settings-edit-legacy')!.click()
    fixture.detectChanges()
    const modal = el(root, 'modal')!
    const badge = el(modal, 'hilos-settings-edit-orphan')!
    const field = el(modal, 'hilos-settings-edit-value') as HTMLInputElement
    expect(badge.textContent?.trim()).toBe('not in catalog')
    expect(badge.getAttribute('title')).toBe(
      'This key is not in the project catalog',
    )
    expect(
      badge.compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(field.disabled).toBe(false)
    expect(el(modal, 'hilos-settings-edit-custom')).toBeNull()
    expect(modal.textContent).not.toContain('Catalog default')
    field.value = 'New legacy value'
    field.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    field.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    fixture.detectChanges()
    expect(sent[0]).toMatchObject({
      action: 'setting_update',
      payload: { key: 'legacy', value: 'New legacy value' },
    })
    answer('success')
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()
    expect(el(root, 'modal')).toBeNull()
  })

  it('keeps Hidden and orphan badges in both projections and the editor without a value', () => {
    const hidden = { _hidden: true } as const
    const fixture = mountPage(
      seededContext([{ ...ORPHAN, value: hidden, overrideValue: hidden }])
        .context,
    )
    const root = fixture.nativeElement as HTMLElement
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const cell = root.querySelector(
        `${projection} [data-id="setting-value"]`,
      )!
      expect(el(cell as HTMLElement, 'hilos-hidden')).not.toBeNull()
      expect(
        el(
          cell as HTMLElement,
          'hilos-setting-not-in-catalog',
        )?.textContent?.trim(),
      ).toBe('not in catalog')
      expect(cell.querySelector('input')).toBeNull()
      expect(cell.textContent).not.toContain('legacy value')
      expect(cell.innerHTML).not.toContain('_hidden')
    }
    el(root, 'hilos-settings-edit-legacy')!.click()
    fixture.detectChanges()
    const modal = el(root, 'modal')!
    expect(el(modal, 'hilos-settings-edit-orphan')).not.toBeNull()
    expect(el(modal, 'hilos-settings-edit-value')).toBeNull()
    expect(el(modal, 'hilos-hidden')).not.toBeNull()
    expect(modal.querySelector('.is-invalid')).toBeNull()
    expect(modal.textContent).not.toContain('legacy value')
  })

  it('preserves default, reference, custom, false, zero and empty without orphan badges', () => {
    const fixture = mountPage(
      seededContext([
        slot({ key: 'empty', valueSource: 'default', value: '' }),
        slot({
          key: 'zero',
          type: 'integer',
          valueSource: 'reference',
          value: '0',
          defaultReferenceKey: 'source.key',
        }),
        slot({
          key: 'off',
          type: 'boolean',
          valueSource: 'override',
          value: '0',
          overrideValue: '0',
        }),
      ]).context,
    )
    const root = fixture.nativeElement as HTMLElement
    expect(
      root.querySelectorAll('[data-id="hilos-setting-not-in-catalog"]'),
    ).toHaveLength(0)
    for (const prefix of ['hilos-table-row', 'hilos-table-card']) {
      const empty = el(root, `${prefix}-empty`)!
      expect(empty.textContent).toContain('empty')
      expect(empty.textContent).toContain('default')
      const zero = el(el(root, `${prefix}-zero`)!, 'setting-value')!
      expect(zero.querySelector('.fst-italic')?.textContent).toBe('0')
      expect(zero.textContent).toContain('source.key')
      const off = el(el(root, `${prefix}-off`)!, 'setting-value')!
      expect((off.querySelector('input') as HTMLInputElement).checked).toBe(
        false,
      )
      expect(off.textContent).toContain('custom')
    }
    el(root, 'hilos-settings-edit-off')!.click()
    fixture.detectChanges()
    expect(el(root, 'hilos-settings-edit-orphan')).toBeNull()
    expect(el(root, 'modal')?.textContent).toContain('Catalog default')
  })

  it('draws the declared ready empty title and hint in both projections', () => {
    const root = mountPage(seededContext([]).context)
      .nativeElement as HTMLElement
    expect(
      Array.from(
        root.querySelectorAll('[data-id="hilos-table-empty-title"]'),
        (node) => node.textContent?.trim(),
      ),
    ).toEqual([
      'This project declares no settings',
      'This project declares no settings',
    ])
    expect(
      Array.from(
        root.querySelectorAll('[data-id="hilos-table-empty-hint"]'),
        (node) => node.textContent?.trim(),
      ),
    ).toEqual([
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
    ])
    expect(el(root, 'hilos-table-empty-action')).toBeNull()
    expect(el(root, 'hilos-table-no-matches')).toBeNull()
    expect(el(root, 'hilos-table-skeleton')).toBeNull()
  })

  it('keeps filtered-empty distinct from an empty catalog and resets search', async () => {
    vi.useFakeTimers()
    const fixture = mountPage(seededContext([ORPHAN]).context)
    const root = fixture.nativeElement as HTMLElement
    const search = el(root, 'hilos-table-search') as HTMLInputElement
    search.value = 'no-such-setting'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    await vi.advanceTimersByTimeAsync(300)
    fixture.detectChanges()
    const states = root.querySelectorAll('[data-id="hilos-table-no-matches"]')
    expect(states).toHaveLength(2)
    for (const state of states) {
      expect(state.textContent).toContain('Nothing found')
      expect(
        state
          .querySelector('[data-id="hilos-table-no-matches-terms"]')
          ?.textContent?.trim(),
      ).toBe('No rows match “no-such-setting”')
    }
    expect(el(root, 'hilos-table-empty-title')).toBeNull()
    expect(root.textContent).not.toContain('This project declares no settings')
    el(root, 'hilos-table-no-matches-reset')!.click()
    fixture.detectChanges()
    await vi.advanceTimersByTimeAsync(300)
    fixture.detectChanges()
    expect(el(root, 'hilos-table-no-matches')).toBeNull()
    expect(root.querySelector('table')?.textContent).toContain('legacy')
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
