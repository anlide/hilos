import { mount } from '@vue/test-utils'
import {
  createSignal,
  HIDDEN_VALUE,
  ScopeManager,
  type HilosLegalAcceptanceRow,
  type HilosLegalContext,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'
import HilosLegalAcceptancesPage from './HilosLegalAcceptancesPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** One acceptance record, with the person's name and email as the server sent them. */
function acceptance(
  name: HilosLegalAcceptanceRow['name'],
  email: HilosLegalAcceptanceRow['email'],
): HilosLegalAcceptanceRow {
  return {
    rowKey: 42,
    userId: 9,
    name,
    email,
    document: 'terms',
    revisionId: 'current',
    declared: true,
    acceptedAt: '2026-09-27 12:00:00',
  }
}

/** Mount the page over the given rows; only the table's drawing is stubbed. */
function mountOver(rows: HilosLegalAcceptanceRow[]) {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_legal_acceptances')
  const context = {
    scopes,
    connection: {
      on: vi.fn(() => () => {}),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
      sendTableFacets: vi.fn(),
    },
    actions: {},
  } as unknown as HilosLegalContext
  const table = {
    setup: () => ({ rows }),
    template: `<div><div v-for="row in rows" :key="row.rowKey"><slot name="cell-name" :row="row" /></div></div>`,
  }

  return mount(HilosLegalAcceptancesPage, {
    props: { context },
    global: {
      provide: {
        [hilosRouterKey as symbol]: {
          currentRoute: createSignal({
            page: 'hilos_legal_acceptances',
            params: {},
            admin: true,
          }),
        },
      },
      stubs: {
        HilosAdminPage: { template: '<main><slot /></main>' },
        HilosViewportTable: table,
        HilosLink: { template: '<a><slot /></a>' },
      },
    },
  })
}

describe('HilosLegalAcceptancesPage (HIL-1260)', () => {
  it('draws the mark for a hidden name and a hidden email, and says nothing about email', () => {
    const view = mountOver([acceptance(HIDDEN_VALUE, HIDDEN_VALUE)])

    const record = view.get('[data-id="legal-acceptance-row"]')
    expect(record.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(record.text()).not.toContain('No verified email')
    view.unmount()
  })

  it('says "No verified email" only when there is none', () => {
    const view = mountOver([acceptance('Reader', null)])

    const record = view.get('[data-id="legal-acceptance-row"]')
    expect(record.find('strong').text()).toBe('Reader')
    expect(record.text()).toContain('No verified email')
    expect(record.find('[data-id="hilos-hidden"]').exists()).toBe(false)
    view.unmount()
  })
})
