import { flushPromises, mount } from '@vue/test-utils'
import { consentTermsWithClauses } from '../../../../core/test/legal/consentFixture.js'
import { createSignal, ScopeManager, type HilosLegalContext } from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import HilosLegalPage from './HilosLegalPage.vue'
import HilosLegalDocumentPage from './HilosLegalDocumentPage.vue'
import HilosLegalRevisionPage from './HilosLegalRevisionPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-09-27',
  significance: 'editorial',
  setVersion: 1,
  deviationCount: 1,
}

/** Data sections remain reactive; only the surrounding shell and table drawing are stubbed. */
function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage('hilos_legal')
  const context = {
    scopes,
    connection: {
      on: vi.fn(() => () => {}),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
    },
    actions: {},
  } as unknown as HilosLegalContext
  const global = {
    provide: {
      [hilosRouterKey as symbol]: {
        currentRoute: createSignal({
          page: 'hilos_legal_document',
          params: { documentKey: 'terms', revisionId: 'current' },
          admin: true,
        }),
      },
    },
    stubs: {
      HilosAdminPage: { template: '<main><slot /></main>' },
      HilosViewportTable: true,
      HilosLink: { template: '<a><slot /></a>' },
    },
  }
  return { scope, context, global }
}

describe('legal admin views', () => {
  it.each([HilosLegalPage, HilosLegalDocumentPage, HilosLegalRevisionPage])(
    'renders a catalog refusal instead of declaration content',
    async (component) => {
      const h = harness()
      const view = mount(component, {
        props: { context: h.context },
        global: h.global,
      })
      h.scope.data.set(
        'legalCatalogRefusal',
        'A deviation names a missing clause',
      )
      await nextTick()
      expect(view.find('[data-id="legal-catalog-refusal"]').text()).toContain(
        'A deviation names a missing clause',
      )
      expect(view.find('[data-id="legal-set"]').exists()).toBe(false)
      expect(view.find('[data-id="legal-revision-text"]').exists()).toBe(false)
      view.unmount()
    },
  )

  it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', () => {
    const h = harness()
    const rows = [
      {
        rowKey: 'terms',
        declared: true,
        revision,
        covered: 1,
        window: 0,
        lapsed: 2,
      },
      {
        rowKey: 'privacy',
        declared: true,
        revision,
        covered: 3,
        window: 0,
        lapsed: 0,
      },
    ]
    // Draws the third count's cell for two document rows; the other tables pass.
    const documentTable = {
      setup: () => ({ rows }),
      template: `<div><template v-if="$slots['cell-lapsed']"><div v-for="row in rows" :key="row.rowKey" :data-row="row.rowKey"><slot name="cell-lapsed" :row="row" /></div></template></div>`,
    }
    const view = mount(HilosLegalPage, {
      props: { context: h.context },
      global: {
        ...h.global,
        stubs: { ...h.global.stubs, HilosViewportTable: documentTable },
      },
    })

    const link = view.find(
      '[data-row="terms"] [data-id="legal-count-lapsed-link"]',
    )
    expect(link.exists()).toBe(true)
    expect(link.attributes('to')).toBe('/hilos/users/terms')
    expect(link.find('[data-id="legal-count-lapsed"]').text()).toBe('2')
    const privacy = view.find('[data-row="privacy"]')
    expect(privacy.find('[data-id="legal-count-lapsed-link"]').exists()).toBe(
      false,
    )
    expect(privacy.find('[data-id="legal-count-lapsed"]').text()).toBe('0')
    view.unmount()
  })

  it('renders the adopted set and the reason for each deviation', () => {
    const h = harness()
    h.scope.data.set('legalDocument', {
      document: 'terms',
      declared: true,
      revision,
      set: {
        version: 1,
        publishedOn: '2026-01-01',
        significance: 'substantial',
        clauses: [
          {
            clauseKey: 'standard.retention',
            statement: 'Retention is limited',
          },
        ],
      },
      newerSet: null,
      deviations: [
        {
          clauseKey: 'standard.retention',
          standardStatement: 'Retention is limited',
          statement: 'Messages stay',
          text: 'The project retains its messages.',
          direction: 'stricter',
        },
      ],
    })
    const view = mount(HilosLegalDocumentPage, {
      props: { context: h.context },
      global: h.global,
    })
    expect(view.find('[data-id="legal-set"]').text()).toContain('1 clauses')
    expect(view.find('[data-id="legal-deviation-row"]').text()).toContain(
      'The project retains its messages.',
    )
    expect(view.find('[data-id="legal-deviation-row"]').text()).toContain(
      'stricter',
    )
    view.unmount()
  })

  it('opens the shared consent preview with both documents and no registration action', async () => {
    const h = harness()
    h.scope.data.set('legalDocument', {
      document: 'terms',
      declared: true,
      revision,
      set: null,
      newerSet: null,
      deviations: [],
    })
    const dispatch = vi.fn(() => ({
      done: Promise.resolve({ reply: consentTermsWithClauses() }),
    }))
    Object.assign(h.context.actions, { dispatch })
    const view = mount(HilosLegalDocumentPage, {
      props: { context: h.context },
      global: h.global,
      attachTo: document.body,
    })
    await view.get('[data-id="legal-preview-consent"]').trigger('click')
    await flushPromises()
    const preview = document.querySelector('[data-id="legal-consent-preview"]')!
    expect(
      preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
    ).toHaveLength(4)
    expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
    expect(dispatch).toHaveBeenCalledTimes(1)
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_legal_consent',
      {},
      expect.any(Object),
    )
    ;(
      document.querySelector(
        '[data-id="legal-consent-preview-close"]',
      ) as HTMLElement
    ).click()
    await flushPromises()
    expect(
      document.querySelector('[data-id="legal-consent-preview"]'),
    ).toBeNull()
    view.unmount()
  })

  it('does not invent text or a zero count for an undeclared revision', () => {
    const h = harness()
    h.scope.data.set('legalRevision', {
      document: 'terms',
      revisionId: 'gone',
      declared: false,
      revision: null,
      current: null,
      predecessorId: null,
      clauses: null,
      changes: null,
    })
    const view = mount(HilosLegalRevisionPage, {
      props: { context: h.context },
      global: h.global,
    })
    expect(view.find('[data-id="legal-revision-undeclared"]').exists()).toBe(
      true,
    )
    expect(view.find('[data-id="legal-revision-text"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-revision-accepted"]').text()).toBe(
      'Loading acceptance count…',
    )
    view.unmount()
  })
})
