import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { ActionLifecycle, ScopeManager, createSignal } from '@hilos/core'
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
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
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

function seededContext(rows: SettingSlot[]): HilosSettingsContext {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_settings')
  // A connection double that answers each viewport request with a window built
  // from the seeded rows — the server-windowed table's data path in one hop.
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const connection = {
    sendTableViewport(page: string, tableKey: string): boolean {
      const data = {
        page,
        tableKey,
        rows: rows.map((settings) => ({
          rowKey: settings.key,
          slots: { settings },
        })),
        totalCount: rows.length,
        offset: 0,
        limit: 10,
      }
      for (const listener of windowListeners) {
        listener({ data })
      }

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)
      }

      return () => windowListeners.delete(listener)
    },
  }
  // The page only dispatches on submit; a fake source keeps the lifecycle inert
  // for a render test (the build ships only src modules, never doubles).
  const actions = new ActionLifecycle({
    sendAction: () => false,
    on: () => () => {},
  })

  return {
    connection: connection as unknown as HilosSettingsContext['connection'],
    scopes,
    actions,
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
      ]),
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
      ]),
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
      ]),
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
      ]),
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
      ]),
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
  })
})
