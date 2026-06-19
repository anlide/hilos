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
    currentRoute: createSignal<PageRouteMatch>({ page: '', params: {} }),
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface SettingSlot {
  key: string
  type: string
  value: string | null
  override_value: string | null
  default_value: string | null
  default_reference_key: string | null
  value_source: string
}

// Build one inline settings slot, defaulting the catalog fields a test does not
// care about so each case states only what it asserts on.
function slot(
  over: Partial<SettingSlot> & { key: string; value_source: string },
): SettingSlot {
  return {
    type: 'string',
    value: 'v',
    override_value: null,
    default_value: 'd',
    default_reference_key: null,
    ...over,
  }
}

function seededContext(rows: SettingSlot[]): HilosSettingsContext {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_settings')
  for (const settings of rows) {
    page.tables.upsert('settings', settings.key, { settings })
  }
  // The page only dispatches on submit; a fake source keeps the lifecycle inert
  // for a render test (the build ships only src modules, never doubles).
  const actions = new ActionLifecycle({
    sendAction: () => false,
    on: () => () => {},
  })
  return { scopes, actions }
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
          value_source: 'override',
          value: 'Hilos',
          override_value: 'Hilos',
        }),
        slot({
          key: 'max_items',
          value_source: 'default',
          type: 'integer',
          value: '10',
          default_value: '10',
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
          value_source: 'override',
          override_value: 'Hilos',
        }),
        slot({
          key: 'legacy',
          value_source: 'orphan',
          override_value: 'x',
          default_value: null,
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

  it('opens the edit dialog from a row action', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          value_source: 'override',
          override_value: 'Hilos',
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
