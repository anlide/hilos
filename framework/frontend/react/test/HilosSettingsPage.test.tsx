import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosSettingsPage } from '../src/admin/settings/HilosSettingsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
} {
  let rows = initial.slice()
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
  }
}

function renderPage(context: HilosSettingsContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSettingsPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosSettingsPage', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
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
    expect(
      document.querySelector('[data-id="hilos-settings-edit-value"]'),
    ).toBeNull()
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
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
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
      document.querySelector('[data-id="hilos-settings-edit-conflict"]')
        ?.textContent,
    ).toContain('The value changed elsewhere to "Theirs"')
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
      document.querySelector('[data-id="hilos-settings-edit-gone"]'),
    ).not.toBeNull()
    const save = document.querySelector(
      '[data-id="hilos-settings-edit-save"]',
    ) as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.textContent?.trim()).toBe('Deleted')
  })
})
