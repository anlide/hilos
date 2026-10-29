import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { z } from 'zod'
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
  ConnectionState,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  HilosSettingsContext,
  PageRouteMatch,
  ProjectSignal,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosSettingsPage } from '../src/admin/settings/HilosSettingsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
  value: unknown
  overrideValue: unknown
  defaultValue: unknown
  defaultReferenceKey: string | null
  valueSource: string
}

// Build one inline settings slot, defaulting the catalog fields a test does not
// care about so each case states only what it asserts on.
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
  const stateListeners = new Set<(state: ConnectionState) => void>()
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
        const stateListener = listener as unknown as (
          state: ConnectionState,
        ) => void
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
      for (const listener of stateListeners) {
        listener('disconnected')
      }
    },
    sent,
    focus,
  }
}

function renderPage(
  context: HilosSettingsContext,
  navigation: HilosRouter = router(SETTINGS_IDENTITY),
) {
  return render(
    <HilosRouterContext.Provider value={navigation}>
      <HilosSettingsPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

function editSave(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="hilos-settings-edit-save"]',
  ) as HTMLButtonElement
}

function editInput(): HTMLInputElement {
  return document.querySelector(
    '[data-id="hilos-settings-edit-value"]',
  ) as HTMLInputElement
}

function editCancel(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="hilos-settings-edit-cancel"]',
  ) as HTMLButtonElement
}

function editBodyFieldset(): HTMLFieldSetElement {
  return document.querySelector(
    '[data-id="modal"] .modal-body > fieldset',
  ) as HTMLFieldSetElement
}

// What the edit window's Save tells a sighted person and a screen reader now.
function saveState() {
  const save = editSave()

  return {
    nativeDisabled: save.disabled,
    disabledLook: save.classList.contains('disabled'),
    spinner: save.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: save.getAttribute('aria-busy'),
    ariaDisabled: save.getAttribute('aria-disabled'),
  }
}

const SAVE_RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const SAVE_OFF = { ...SAVE_RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const SAVE_LOCKED = { ...SAVE_RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SAVE_SPINNING = { ...SAVE_LOCKED, disabledLook: true, spinner: true }

// Enter in the edit window: the submit of its form.
function enterInEdit(): void {
  fireEvent.submit(
    document.querySelector('[data-id="modal"] form') as HTMLFormElement,
  )
}

// Advance the fake clock and let the view render what the timers decided.
function wait(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

describe('HilosSettingsPage', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  it('replaces the section skeleton with the adopted Settings head and names the live table', () => {
    const { context } = seededContext([
      slot({ key: 'site_name', valueSource: 'default' }),
    ])
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const navigation = { ...router(undefined), pageIdentity: identity }
    const { container } = renderPage(context, navigation)

    const skeleton = container.querySelector(
      '[data-id="hilos-page-head-skeleton"]',
    )
    expect(skeleton).not.toBeNull()
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton?.querySelector('.rounded-circle')).not.toBeNull()
    expect(skeleton?.querySelector('.rounded-3')).toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]'),
    ).toBeNull()
    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(container.querySelector('[data-id="hilos-page-tabs"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('.bi-sliders')).toBeNull()
    expect(container.textContent).not.toContain('Settings')
    expect(container.textContent).not.toContain(SETTINGS_IDENTITY.lead)

    act(() => {
      identity.set(SETTINGS_IDENTITY)
    })

    expect(
      container.querySelectorAll('[data-id="hilos-page-head"]'),
    ).toHaveLength(1)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    const heading = container.querySelector('h1') as HTMLHeadingElement
    expect(heading.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(heading.textContent).toBe('Settings')
    const block = container.querySelector(
      '[data-id="hilos-page-head-block"]',
    ) as HTMLElement
    const circle = block.querySelector(':scope > .rounded-circle')
    expect(circle?.getAttribute('aria-hidden')).toBe('true')
    expect(circle?.querySelector('i')?.className).toBe('bi bi-sliders')
    expect(block.querySelectorAll('p')).toHaveLength(1)
    expect(block.querySelector('p')?.textContent).toBe(
      'Application setting catalog: catalog defaults, overrides, and references.',
    )
    expect(container.textContent?.split(SETTINGS_IDENTITY.lead)).toHaveLength(2)

    const crumbs = container.querySelector(
      '[data-id="hilos-breadcrumb"]',
    ) as HTMLElement
    expect(crumbs.getAttribute('aria-label')).toBe('Breadcrumb')
    const items = crumbs.querySelectorAll('li')
    expect(items).toHaveLength(2)
    expect(items[0].textContent).toBe('Hilos')
    expect(items[0].querySelector('a')?.getAttribute('href')).toBe('/hilos')
    expect(items[0].hasAttribute('aria-current')).toBe(false)
    expect(items[0].querySelector('a')?.hasAttribute('aria-current')).toBe(
      false,
    )
    expect(items[1].textContent?.replace('/', '').trim()).toBe('Settings')
    expect(items[1].getAttribute('aria-current')).toBe('page')
    expect(items[1].querySelector('a')).toBeNull()
    expect(container.querySelector('[data-id="hilos-page-tabs"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-title"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    expect(
      container
        .querySelector('[data-id="hilos-page-head"]')
        ?.nextElementSibling?.getAttribute('data-id'),
    ).toBe('hilos-viewport-table')

    expect(heading.id).toBeTruthy()
    expect(document.getElementById(heading.id)).toBe(heading)
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(heading.id)
    expect(
      container
        .querySelector('[data-id="hilos-table-cards"] [role="list"]')
        ?.getAttribute('aria-labelledby'),
    ).toBe(heading.id)
    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]'),
    ).toHaveLength(1)
    expect(container.querySelector('table')?.textContent).toContain('site_name')
  })

  it('renders a row per setting with its key and source badge', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Hilos',
          overrideValue: 'Hilos',
        }),
        slot({
          key: 'max_items',
          valueSource: 'default',
          type: 'integer',
          value: '10',
          defaultValue: '10',
        }),
      ]).context,
    )
    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]').length,
    ).toBe(2)
    expect(container.textContent).toContain('site_name')
    expect(container.textContent).toContain('custom')
    expect(container.textContent).toContain('default')
  })

  it('offers delete only for an orphan key', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          overrideValue: 'Hilos',
        }),
        slot({
          key: 'legacy',
          valueSource: 'orphan',
          overrideValue: 'x',
          defaultValue: null,
        }),
      ]).context,
    )
    expect(
      container.querySelector('[data-id="hilos-settings-delete-legacy"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-settings-delete-site_name"]'),
    ).toBeNull()
  })

  it('offers "set custom value" for a cataloged key on its default', () => {
    // The row a reset leaves behind: on its catalog default, no value of its own.
    // The screen asks the override, not whether a row is stored, so the button is
    // the plus that offers a custom value — not the pencil that edits one.
    const { container } = renderPage(
      seededContext([
        slot({ key: 'site_name', valueSource: 'default', value: 'd' }),
      ]).context,
    )
    const button = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as Element

    expect(button.getAttribute('aria-label')).toBe('Set custom value')
    expect(button.querySelector('.bi-plus-lg')).not.toBeNull()
  })

  it('opens a key on its default with the custom-value switch off', () => {
    const { container } = renderPage(
      seededContext([
        slot({ key: 'site_name', valueSource: 'default', value: 'd' }),
      ]).context,
    )
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )

    const custom = document.querySelector(
      '[data-id="hilos-settings-edit-custom"]',
    ) as HTMLInputElement

    expect(custom.checked).toBe(false)
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    expect(input.value).toBe('d')
    expect(input.disabled).toBe(true)
    expect(input.parentElement?.classList.contains('opacity-50')).toBe(true)
  })

  it('opens the edit dialog from a row action', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          overrideValue: 'Hilos',
        }),
      ]).context,
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    // Nothing happened elsewhere yet: the message line stands empty, its room
    // held by the twin, so a message later moves nothing.
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice-idle"]'),
    ).not.toBeNull()
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    expect(input.value).toBe('Hilos')

    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Elsewhere',
          overrideValue: 'Elsewhere',
        }),
      )
    })

    expect(input.value).toBe('Elsewhere')
    expect(document.querySelector('[data-id="conflict-badge"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)

    // The person types over the taken value: the note about it goes out.
    fireEvent.change(input, { target: { value: 'Mine' } })
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]'),
    ).toBeNull()
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )

    // The other side reset the key: the switch goes off and the dialog says so.
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'default',
          value: 'Default',
          overrideValue: null,
          defaultValue: 'Default',
        }),
      )
    })
    const custom = document.querySelector(
      '[data-id="hilos-settings-edit-custom"]',
    ) as HTMLInputElement
    expect(custom.checked).toBe(false)
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')

    // Turning the switch back on starts from the value now in effect, not from
    // the override the other side just removed.
    fireEvent.click(custom)
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-value"]',
        ) as HTMLInputElement
      ).value,
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'Mine' } })
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    fireEvent.submit(input.form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(document.querySelector('[data-id="conflict-badge"]')).not.toBeNull()
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    fireEvent.change(
      document.querySelector(
        '[data-id="hilos-settings-edit-value"]',
      ) as HTMLInputElement,
      { target: { value: 'Mine' } },
    )
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    expect(document.querySelector('[data-id="conflict-badge"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Changed elsewhere to "Theirs"')
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'Mine' } })
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })
    fireEvent.click(
      document.querySelector('[data-id="conflict-accept-theirs"]') as Element,
    )

    expect(input.value).toBe('Theirs')
    expect(document.querySelector('[data-id="conflict-badge"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-legacy"]',
      ) as Element,
    )
    act(() => {
      pushRemove('legacy')
    })

    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Deleted elsewhere')
    const save = document.querySelector(
      '[data-id="hilos-settings-edit-save"]',
    ) as HTMLButtonElement
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    // The other tab's save took the row out of this tab's search: the screen gates the
    // departure, the dialog holding the row in focus reads the body off the same frame.
    act(() => {
      pushLeave(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-value"]',
        ) as HTMLInputElement
      ).value,
    ).toBe('Theirs')
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
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
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(focus).toEqual(['site_name'])

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    expect(focus).toEqual(['site_name', ''])
  })
})

// The production LoadingButton, ConflictActions and ActionLifecycle together:
// the button's own 300 ms timer is the only delay, so the spinner never waits
// for the lifecycle's deferred loading on top of it, and the own save in flight
// never turns Save off natively — only a reason of its own does.
describe('HilosSettingsPage Save in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    vi.useRealTimers()
  })

  const OWN = slot({
    key: 'site_name',
    valueSource: 'override',
    value: 'Hilos',
    overrideValue: 'Hilos',
  })

  // Open the row's edit window and type a value into its field.
  function openAndType(context: HilosSettingsContext, value: string): void {
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    type(value)
  }

  function type(value: string): void {
    fireEvent.change(
      document.querySelector(
        '[data-id="hilos-settings-edit-value"]',
      ) as HTMLInputElement,
      { target: { value } },
    )
  }

  async function reply(
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    await act(async () => {
      answer(outcome)
      for (let tick = 0; tick < 4; tick += 1) await Promise.resolve()
    })
  }

  it('locks Save at once on the first send and greys it only with its spinner at 300 ms', async () => {
    const { context, sent, answer } = seededContext([OWN])
    openAndType(context, 'Mine')
    expect(saveState()).toEqual(SAVE_RESTING)
    const input = editInput()
    const form = input.form
    const fieldset = editBodyFieldset()
    const cancel = editCancel()

    fireEvent.click(editSave())
    expect(sent.map((entry) => entry.action)).toEqual(['setting_add'])
    expect(saveState()).toEqual(SAVE_LOCKED)
    expect(editBodyFieldset()).toBe(fieldset)
    expect(fieldset.disabled).toBe(true)
    expect(input.matches(':disabled')).toBe(true)
    expect(cancel.matches(':disabled')).toBe(true)
    fireEvent.click(cancel)
    fireEvent.keyDown(document, { key: 'Escape' })
    fireEvent.click(editSave())
    enterInEdit()
    expect(sent).toHaveLength(1)
    expect(document.querySelector('[data-id="modal-confirm-discard"]')).toBe(
      null,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(editInput()).toBe(input)
    expect(input.form).toBe(form)
    expect(input.value).toBe('Mine')

    wait(299)
    expect(saveState()).toEqual(SAVE_LOCKED)
    expect(fieldset.disabled).toBe(true)
    expect(input.matches(':disabled')).toBe(true)
    wait(1)
    expect(saveState()).toEqual(SAVE_SPINNING)
    fireEvent.click(editSave())
    enterInEdit()
    expect(sent).toHaveLength(1)

    await reply(answer, 'success')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('returns Save to rest on a refusal and lets the next press send', async () => {
    const { context, sent, answer } = seededContext([OWN])
    openAndType(context, 'Mine')
    const input = editInput()
    const form = input.form
    const fieldset = editBodyFieldset()
    const cancel = editCancel()
    fireEvent.click(editSave())
    expect(fieldset.disabled).toBe(true)
    expect(input.matches(':disabled')).toBe(true)
    expect(cancel.matches(':disabled')).toBe(true)
    wait(300)
    expect(saveState()).toEqual(SAVE_SPINNING)

    await reply(answer, 'fail')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(editInput()).toBe(input)
    expect(input.form).toBe(form)
    expect(fieldset.disabled).toBe(false)
    expect(input.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(input.value).toBe('Mine')
    expect(
      document.querySelector('[data-id="hilos-action-error"]'),
    ).not.toBeNull()
    expect(saveState()).toEqual(SAVE_RESTING)

    const custom = document.querySelector(
      '[data-id="hilos-settings-edit-custom"]',
    ) as HTMLInputElement
    fireEvent.click(custom)
    expect(fieldset.disabled).toBe(false)
    expect(input.disabled).toBe(true)
    expect(input.matches(':disabled')).toBe(true)
    fireEvent.click(custom)
    expect(input.disabled).toBe(false)
    expect(input.value).toBe('Mine')

    enterInEdit()
    expect(sent).toHaveLength(2)
    expect(fieldset.disabled).toBe(true)
    await reply(answer, 'success')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('turns Save off at once for a draft with nothing to save', () => {
    const { context, sent } = seededContext([OWN])
    openAndType(context, 'Mine')
    type('Hilos')
    expect(saveState()).toEqual(SAVE_OFF)

    fireEvent.click(editSave())
    wait(300)
    expect(saveState()).toEqual(SAVE_OFF)
    enterInEdit()
    expect(sent).toEqual([])
  })

  it('turns Save off for a removal that comes while the save flies', async () => {
    const { context, answer, pushRemove } = seededContext([OWN])
    openAndType(context, 'Mine')
    fireEvent.click(editSave())
    expect(saveState()).toEqual(SAVE_LOCKED)

    act(() => {
      pushRemove('site_name')
    })
    expect(saveState()).toEqual({ ...SAVE_LOCKED, nativeDisabled: true })
    expect(editSave().textContent?.trim()).toBe('Deleted')

    await reply(answer, 'fail')
    expect(saveState()).toEqual(SAVE_OFF)
    expect(editSave().textContent?.trim()).toBe('Deleted')
  })

  it('turns Save off for a conflict that comes while the save flies', async () => {
    const { context, answer, pushUpdate } = seededContext([OWN])
    openAndType(context, 'Mine')
    fireEvent.click(editSave())
    expect(saveState()).toEqual(SAVE_LOCKED)

    act(() => {
      pushUpdate({ ...OWN, value: 'Theirs', overrideValue: 'Theirs' })
    })
    expect(
      document.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(saveState()).toEqual({ ...SAVE_LOCKED, nativeDisabled: true })

    await reply(answer, 'fail')
    expect(saveState()).toEqual(SAVE_OFF)
  })
})

describe('HilosSettingsPage typed editor and refusals', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    vi.useRealTimers()
  })

  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: '1',
    overrideValue: '1',
    defaultValue: '0',
  })

  function byId(id: string): HTMLElement | null {
    return document.querySelector(`[data-id="${id}"]`)
  }

  function open(context: HilosSettingsContext, key = 'site_name'): void {
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(`[data-id="hilos-settings-edit-${key}"]`)!,
    )
  }

  function input(): HTMLInputElement {
    return byId('hilos-settings-edit-value') as HTMLInputElement
  }

  function toggle(): void {
    fireEvent.click(byId('hilos-settings-edit-custom')!)
  }

  function type(text: string): void {
    fireEvent.change(input(), { target: { value: text } })
  }

  async function reply(
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail' = 'fail',
  ): Promise<void> {
    await act(async () => answer(outcome))
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
    'shows the server type %s beside the existing title',
    (type, inputType, step) => {
      const key = 'a_very_long_setting_key_'.repeat(8)
      open(seededContext([{ ...CUSTOM, key, type }]).context, key)
      const badge = byId('hilos-settings-edit-type')!
      expect(badge.textContent).toBe(type)
      expect(badge.classList.contains('font-monospace')).toBe(true)
      expect(badge.classList.contains('flex-shrink-0')).toBe(true)
      const header = badge.parentElement!
      expect(header.classList.contains('flex-grow-1')).toBe(true)
      expect(header.classList.contains('min-w-0')).toBe(true)
      const title = header.querySelector('h5')!
      expect(title.textContent).toBe(`Edit · ${key}`)
      expect(title.closest('.text-break.min-w-0')).not.toBeNull()
      expect(
        header.parentElement?.querySelector('[data-id="modal-close"]'),
      ).not.toBeNull()
      expect(header.contains(byId('modal-close'))).toBe(false)
      expect(input().type).toBe(inputType)
      expect(input().getAttribute('step')).toBe(step)
    },
  )

  it('omits an absent server type without inferring it from the value', () => {
    open(seededContext([{ ...CUSTOM, type: '' }]).context)
    expect(byId('hilos-settings-edit-type')).toBeNull()
    expect(input().type).toBe('text')
  })

  it.each(['string', 'integer', 'float'])(
    'keeps the same %s input and zero draft through custom off and on',
    async (type) => {
      const { context, sent, answer } = seededContext([
        {
          ...CUSTOM,
          type,
          valueSource: 'default',
          value: '5',
          overrideValue: null,
          defaultValue: '5',
        },
      ])
      open(context)
      const field = input()
      const group = field.parentElement!
      const notice = byId('hilos-settings-edit-notice-idle')
      expect(field.value).toBe('5')
      expect(field.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(notice).not.toBeNull()
      toggle()
      expect(input()).toBe(field)
      expect(field.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      fireEvent.change(field, { target: { value: '0' } })
      toggle()
      expect(input()).toBe(field)
      expect(field.value).toBe('0')
      expect(field.disabled).toBe(true)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(byId('hilos-settings-edit-notice-idle')).toBe(notice)
      toggle()
      expect(input()).toBe(field)
      expect(field.value).toBe('0')
      expect(field.disabled).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      enterInEdit()
      expect(sent[0]).toMatchObject({
        action: 'setting_add',
        payload: { key: 'site_name', value: '0' },
      })
      await reply(answer, 'success')
      expect(byId('modal')).toBeNull()
    },
  )

  it.each(['default', 'override'])(
    'keeps the same boolean input and false draft through off and on from %s',
    async (valueSource) => {
      const { context, sent, answer } = seededContext([
        {
          ...CUSTOM,
          type: 'boolean',
          valueSource,
          value: '0',
          overrideValue: valueSource === 'default' ? null : '0',
        },
      ])
      open(context)
      const field = input()
      const group = field.parentElement!.parentElement!
      expect(field.checked).toBe(false)
      expect(field.disabled).toBe(valueSource === 'default')
      expect(group.classList.contains('opacity-50')).toBe(
        valueSource === 'default',
      )
      if (valueSource === 'default') toggle()
      fireEvent.click(field)
      expect(field.checked).toBe(true)
      fireEvent.click(field)
      expect(field.checked).toBe(false)
      toggle()
      expect(input()).toBe(field)
      expect(field.disabled).toBe(true)
      expect(field.checked).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(true)
      expect(byId('hilos-settings-edit-notice-idle')).not.toBeNull()
      toggle()
      expect(input()).toBe(field)
      expect(field.disabled).toBe(false)
      expect(field.checked).toBe(false)
      expect(group.classList.contains('opacity-50')).toBe(false)
      if (valueSource === 'default') {
        enterInEdit()
        expect(sent[0]).toMatchObject({
          action: 'setting_add',
          payload: { key: 'site_name', value: '0' },
        })
        await reply(answer, 'success')
      }
    },
  )

  it.each(['string', 'integer', 'float', 'boolean'])(
    'marks only a real refusal of the custom %s value and clears on run and change',
    async (type) => {
      const { context, sent, answer } = seededContext([{ ...CUSTOM, type }])
      open(context)
      const field = input()
      if (type === 'boolean') fireEvent.click(field)
      else fireEvent.change(field, { target: { value: '2' } })
      const draft = type === 'boolean' ? field.checked : field.value
      const error = document.getElementById('hilos-settings-edit-error')!
      expect(error).not.toBeNull()
      expect(error.getAttribute('role')).toBeNull()
      expect(error.getAttribute('aria-live')).toBeNull()
      expectInvalid(field, false)
      enterInEdit()
      await reply(answer)
      expect(byId('modal')).not.toBeNull()
      expect(input()).toBe(field)
      expect(type === 'boolean' ? field.checked : field.value).toBe(draft)
      expectInvalid(field, true)
      expect(error.contains(byId('hilos-action-error'))).toBe(true)
      expect(byId('hilos-action-error')?.textContent).toContain(
        'The setting refused the reset.',
      )
      expect(
        field.classList.contains(
          type === 'boolean' ? 'form-check-input' : 'form-control',
        ),
      ).toBe(true)
      enterInEdit()
      expect(sent).toHaveLength(2)
      expectInvalid(field, false)
      expect(byId('hilos-action-error')).toBeNull()
      await reply(answer)
      expectInvalid(field, true)
      if (type === 'boolean') fireEvent.click(field)
      else fireEvent.change(field, { target: { value: '3' } })
      expectInvalid(field, false)
      expect(byId('hilos-action-error')).toBeNull()
      expect(document.getElementById('hilos-settings-edit-error')).toBe(error)
    },
  )

  it.each(['timeout', 'disconnected', 'invalid-reply'])(
    'keeps a %s message without marking the value invalid',
    async (outcome) => {
      const { context, answer, disconnect } = seededContext([CUSTOM])
      if (outcome === 'invalid-reply') {
        const dispatch = context.actions.dispatch.bind(context.actions)
        vi.spyOn(context.actions, 'dispatch').mockImplementation(
          (action, data) => dispatch(action, data, { replySchema: z.string() }),
        )
      }
      open(context)
      type('Mine')
      const field = input()
      enterInEdit()
      await act(async () => {
        if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30000)
        else if (outcome === 'disconnected') disconnect()
        else answer('success')
      })
      expect(byId('modal')).not.toBeNull()
      expect(input()).toBe(field)
      expect(field.value).toBe('Mine')
      expectInvalid(field, false)
      const message =
        outcome === 'timeout'
          ? 'The action timed out. Please try again.'
          : outcome === 'disconnected'
            ? 'The connection dropped before the action completed. Please try again.'
            : 'The server answered in a form this page could not read. Please try again.'
      expect(byId('hilos-action-error')?.textContent).toContain(message)
    },
  )

  it('sends only reset while custom is off and leaves its refused disabled draft unmarked', async () => {
    const { context, sent, answer } = seededContext([CUSTOM])
    open(context)
    type('Unsaved draft')
    const field = input()
    toggle()
    enterInEdit()
    expect(sent[0]?.action).toBe('setting_reset')
    expect(sent[0]?.payload).toEqual({ key: 'site_name' })
    await reply(answer)
    expect(input()).toBe(field)
    expect(field.value).toBe('Unsaved draft')
    expect(field.disabled).toBe(true)
    expectInvalid(field, false)
    expect(byId('hilos-action-error')).not.toBeNull()
    toggle()
    expect(field.value).toBe('Unsaved draft')
    expectInvalid(field, false)
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('keeps refusal on conflict-only changes and Keep mine, clearing when Take theirs changes the form', async () => {
    const { context, pushUpdate, answer } = seededContext([CUSTOM])
    open(context)
    type('Mine')
    const field = input()
    enterInEdit()
    await reply(answer)
    act(() =>
      pushUpdate({ ...CUSTOM, value: 'Theirs', overrideValue: 'Theirs' }),
    )
    expect(field.value).toBe('Mine')
    expect(byId('conflict-badge')).not.toBeNull()
    expect(byId('hilos-settings-edit-type')?.textContent).toBe('string')
    expectInvalid(field, true)
    expect(byId('hilos-action-error')).not.toBeNull()
    fireEvent.click(byId('conflict-accept-mine')!)
    expect(byId('conflict-badge')).toBeNull()
    expectInvalid(field, true)
    expect(byId('hilos-action-error')).not.toBeNull()
    act(() =>
      pushUpdate({
        ...CUSTOM,
        value: 'New theirs',
        overrideValue: 'New theirs',
      }),
    )
    fireEvent.click(byId('conflict-accept-theirs')!)
    expect(field.value).toBe('New theirs')
    expectInvalid(field, false)
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('clears refusal when automatic live merge changes the accepted form text', async () => {
    const { context, pushUpdate, answer } = seededContext([CUSTOM])
    open(context)
    type('Mine')
    enterInEdit()
    await reply(answer)
    const field = input()
    expect(byId('hilos-action-error')).not.toBeNull()
    act(() => pushUpdate({ ...CUSTOM, value: 'Mine', overrideValue: 'Mine' }))
    expect(field.value).toBe('Mine')
    expectInvalid(field, true)
    expect(byId('hilos-action-error')).not.toBeNull()
    act(() => pushUpdate({ ...CUSTOM, value: 'Next', overrideValue: 'Next' }))
    expect(input()).toBe(field)
    expect(field.value).toBe('Next')
    expect(field.disabled).toBe(false)
    expect(byId('hilos-settings-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expectInvalid(field, false)
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('clears an old refusal on opening a hidden orphan without exposing a draft', async () => {
    const hidden = { _hidden: true } as const
    const { context, sent, answer } = seededContext([
      CUSTOM,
      slot({
        key: 'hidden_orphan',
        valueSource: 'orphan',
        value: hidden,
        overrideValue: hidden,
        defaultValue: null,
      }),
    ])
    open(context)
    type('Mine')
    enterInEdit()
    await reply(answer)
    expectInvalid(input(), true)
    fireEvent.click(byId('modal-close')!)
    fireEvent.click(byId('modal-confirm-discard')!)
    expect(byId('modal')).toBeNull()
    fireEvent.click(
      document.querySelector('[data-id="hilos-settings-edit-hidden_orphan"]')!,
    )
    expect(input()).toBeNull()
    expect(byId('hilos-settings-edit-custom')).toBeNull()
    expect(byId('hilos-hidden')).not.toBeNull()
    expect(byId('hilos-settings-edit-orphan')?.textContent).toBe(
      'not in catalog',
    )
    expect(byId('hilos-action-error')).toBeNull()
    expect(byId('modal')?.querySelector('.is-invalid')).toBeNull()
    expect(byId('modal')?.textContent).not.toContain('Mine')
    enterInEdit()
    expect(sent).toHaveLength(1)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosSettingsPage orphan and empty states', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    vi.useRealTimers()
  })

  const ORPHAN = slot({
    key: 'legacy',
    valueSource: 'orphan',
    value: 'legacy value',
    overrideValue: 'legacy value',
    defaultValue: null,
  })

  it('labels an orphan in both table projections and above its edit field, then updates it', async () => {
    const { context, sent, answer } = seededContext([ORPHAN])
    const { container } = renderPage(context)
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const root = container.querySelector(projection)!
      const badges = root.querySelectorAll(
        '[data-id="hilos-setting-not-in-catalog"]',
      )
      expect(badges).toHaveLength(1)
      expect(badges[0]!.textContent).toBe('not in catalog')
      expect(badges[0]!.getAttribute('title')).toBe(
        'This key is not in the project catalog',
      )
      expect(badges[0]!.className).toBe(
        'badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle flex-shrink-0',
      )
      expect(root.textContent).toContain('legacy value')
    }
    fireEvent.click(
      container.querySelector('[data-id="hilos-settings-edit-legacy"]')!,
    )
    const modal = document.querySelector('[data-id="modal"]')!
    const badge = modal.querySelector('[data-id="hilos-settings-edit-orphan"]')!
    const field = modal.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    expect(badge.textContent).toBe('not in catalog')
    expect(badge.getAttribute('title')).toBe(
      'This key is not in the project catalog',
    )
    expect(
      badge.compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(field.disabled).toBe(false)
    expect(
      modal.querySelector('[data-id="hilos-settings-edit-custom"]'),
    ).toBeNull()
    expect(modal.textContent).not.toContain('Catalog default')
    fireEvent.change(field, { target: { value: 'New legacy value' } })
    enterInEdit()
    expect(sent[0]).toMatchObject({
      action: 'setting_update',
      payload: { key: 'legacy', value: 'New legacy value' },
    })
    await act(async () => answer('success'))
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('keeps hidden orphan marks and badges in both projections without rendering a value', () => {
    const hidden = { _hidden: true } as const
    const { context } = seededContext([
      { ...ORPHAN, value: hidden, overrideValue: hidden },
    ])
    const { container } = renderPage(context)
    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const cell = container.querySelector(
        `${projection} [data-id="setting-value"]`,
      )!
      expect(cell.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
      expect(
        cell.querySelector('[data-id="hilos-setting-not-in-catalog"]')
          ?.textContent,
      ).toBe('not in catalog')
      expect(cell.querySelector('input')).toBeNull()
      expect(cell.textContent).not.toContain('legacy value')
      expect(cell.innerHTML).not.toContain('_hidden')
    }
    fireEvent.click(
      container.querySelector('[data-id="hilos-settings-edit-legacy"]')!,
    )
    const modal = document.querySelector('[data-id="modal"]')!
    expect(
      modal.querySelector('[data-id="hilos-settings-edit-orphan"]'),
    ).not.toBeNull()
    expect(
      modal.querySelector('[data-id="hilos-settings-edit-value"]'),
    ).toBeNull()
    expect(modal.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(modal.textContent).not.toContain('legacy value')
  })

  it('preserves catalog default, reference, custom, false, zero and empty without orphan badges', () => {
    const { context } = seededContext([
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
    ])
    const { container } = renderPage(context)
    expect(
      container.querySelectorAll('[data-id="hilos-setting-not-in-catalog"]'),
    ).toHaveLength(0)
    for (const prefix of ['hilos-table-row', 'hilos-table-card']) {
      const empty = container.querySelector(`[data-id="${prefix}-empty"]`)!
      expect(empty.textContent).toContain('empty')
      expect(empty.textContent).toContain('default')
      const zero = container.querySelector(
        `[data-id="${prefix}-zero"] [data-id="setting-value"]`,
      )!
      expect(zero.querySelector('.fst-italic')?.textContent).toBe('0')
      expect(zero.textContent).toContain('source.key')
      const off = container.querySelector(
        `[data-id="${prefix}-off"] [data-id="setting-value"]`,
      )!
      expect((off.querySelector('input') as HTMLInputElement).checked).toBe(
        false,
      )
      expect(off.textContent).toContain('custom')
    }
  })

  it('draws the declared empty title and hint in both projections', () => {
    const { container } = renderPage(seededContext([]).context)
    expect(
      Array.from(
        container.querySelectorAll('[data-id="hilos-table-empty-title"]'),
        (el) => el.textContent,
      ),
    ).toEqual([
      'This project declares no settings',
      'This project declares no settings',
    ])
    expect(
      Array.from(
        container.querySelectorAll('[data-id="hilos-table-empty-hint"]'),
        (el) => el.textContent,
      ),
    ).toEqual([
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
      'Setting keys are declared in the project catalog in code. They cannot be added from this screen.',
    ])
    expect(
      container.querySelector('[data-id="hilos-table-empty-action"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-no-matches"]'),
    ).toBeNull()
  })

  it('keeps search without matches distinct from an empty catalog and resets it', async () => {
    vi.useFakeTimers()
    const { container } = renderPage(seededContext([ORPHAN]).context)
    fireEvent.change(
      container.querySelector('[data-id="hilos-table-search"]')!,
      { target: { value: 'no-such-setting' } },
    )
    await act(async () => vi.advanceTimersByTimeAsync(300))
    const states = container.querySelectorAll(
      '[data-id="hilos-table-no-matches"]',
    )
    expect(states).toHaveLength(2)
    for (const state of states) {
      expect(state.textContent).toContain('Nothing found')
      expect(
        state.querySelector('[data-id="hilos-table-no-matches-terms"]')
          ?.textContent,
      ).toBe('No rows match “no-such-setting”')
    }
    expect(
      container.querySelector('[data-id="hilos-table-empty-title"]'),
    ).toBeNull()
    expect(container.textContent).not.toContain(
      'This project declares no settings',
    )
    fireEvent.click(
      container.querySelector('[data-id="hilos-table-no-matches-reset"]')!,
    )
    await act(async () => vi.advanceTimersByTimeAsync(300))
    expect(
      container.querySelector('[data-id="hilos-table-no-matches"]'),
    ).toBeNull()
    expect(container.querySelector('table')?.textContent).toContain('legacy')
  })
})

describe('HilosSettingsPage reset dialog', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: 'Mine',
    overrideValue: 'Mine',
    defaultValue: 'Hilos',
  })

  function byId(id: string): HTMLElement | null {
    return document.querySelector(`[data-id="${id}"]`)
  }

  function resetButton(key: string): HTMLButtonElement | null {
    return document.querySelector(
      `table [data-id="hilos-settings-reset-${key}"]`,
    )
  }

  function confirmButton(): HTMLButtonElement {
    return byId('hilos-settings-reset-confirm') as HTMLButtonElement
  }

  function cancelButton(): HTMLButtonElement {
    return Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  function openReset(context: HilosSettingsContext): void {
    renderPage(context)
    fireEvent.click(resetButton('site_name') as Element)
  }

  async function reply(
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    await act(async () => {
      answer(outcome)
      await Promise.resolve()
    })
  }

  it('offers ↺ on a catalog row, locked on its default, and none on an orphan', () => {
    renderPage(
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

    expect(resetButton('site_name')?.disabled).toBe(false)
    expect(resetButton('on_default')?.disabled).toBe(true)
    expect(resetButton('orphan')).toBeNull()
    expect(
      document.querySelector('table [data-id="hilos-settings-delete-orphan"]'),
    ).not.toBeNull()
  })

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    openReset(context)

    expect(byId('modal')?.textContent).toContain('Reset · site_name')
    expect(focus).toEqual(['site_name'])
    expect(sent).toEqual([])
  })

  it('shows the own value now and the catalog default it goes back to', () => {
    const { context } = seededContext([CUSTOM])
    openReset(context)

    expect(byId('hilos-settings-reset-now')?.textContent).toContain('Mine')
    const back = byId('hilos-settings-reset-default')
    expect(back?.textContent).toContain('Hilos')
    expect(back?.textContent).not.toContain('custom')
  })

  it('names the referenced key when the default is a reference', () => {
    const { context } = seededContext([
      { ...CUSTOM, defaultReferenceKey: 'app.title' },
    ])
    openReset(context)

    expect(byId('hilos-settings-reset-default')?.textContent).toContain(
      'app.title',
    )
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(confirmButton())
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'setting_reset', payload: { key: 'site_name' } },
    ])

    await reply(answer, 'success')
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual(['site_name', ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(cancelButton())

    expect(byId('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual(['site_name', ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(confirmButton())
    await reply(answer, 'fail')

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The setting refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext([CUSTOM])
    openReset(context)
    expect(byId('hilos-settings-reset-gone')).toBeNull()

    act(() => {
      pushUpdate({
        ...CUSTOM,
        valueSource: 'default',
        value: 'Hilos',
        overrideValue: null,
      })
    })

    expect(byId('hilos-settings-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(confirmButton().disabled).toBe(true)
  })
})

describe('HilosSettingsPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    for (const release of releases.splice(0)) release()
    vi.useRealTimers()
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

  function byId(id: string): HTMLElement | null {
    return document.querySelector(`[data-id="${id}"]`)
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
    const { container } = renderPage(context)

    const edit = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    fireEvent.click(edit)

    expect(byId('hilos-settings-edit-value')).toBeNull()
    expect(byId('hilos-hidden')).not.toBeNull()

    const save = byId('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)

    fireEvent.click(save)
    expect(sent).toEqual([])

    const cancel = byId('hilos-settings-edit-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    fireEvent.click(cancel)
    expect(byId('modal')).toBeNull()
  })

  // A viewer's value is always hidden (HIL-1258): Save is off from the first
  // frame, natively, and neither a click nor Enter sends anything.
  it('turns Save off at once in the admin view mode', () => {
    vi.useFakeTimers()
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(saveState()).toEqual(SAVE_OFF)

    fireEvent.click(editSave())
    wait(300)
    expect(saveState()).toEqual(SAVE_OFF)
    enterInEdit()
    expect(sent).toEqual([])
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
    renderPage(context)

    const reset = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-reset-site_name"]',
    )
    expect(reset?.disabled).toBe(false)
    fireEvent.click(reset as Element)

    expect(
      byId('hilos-settings-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      byId('hilos-settings-reset-default')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    const confirm = byId('hilos-settings-reset-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm)
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as Element)

    expect(byId('modal')).toBeNull()
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
    renderPage(context)

    const del = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-delete-orphan"]',
    )
    expect(del?.disabled).toBe(false)
    fireEvent.click(del as Element)

    const confirm = byId('hilos-settings-delete-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm)
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as Element)

    expect(byId('modal')).toBeNull()
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
    const { container } = renderPage(context)

    const edit = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    fireEvent.click(edit)

    const input = byId('hilos-settings-edit-value') as HTMLInputElement
    expect(input.disabled).toBe(false)
    fireEvent.change(input, { target: { value: 'Other' } })

    const save = byId('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(false)
    expect(save.getAttribute('aria-describedby')).toBeNull()
  })
})
