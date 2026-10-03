import { flushPromises, mount } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  ScopeManager,
  type HilosLegalAcceptanceRow,
  type HilosLegalAcceptancesExportNode,
  type HilosLegalContext,
  type ProjectSignal,
  type TableViewportController,
} from '@hilos/core'
import { defineComponent, ref, type PropType } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HilosLegalAcceptancesPage from './HilosLegalAcceptancesPage.vue'
import { hilosAdminViewModeKey } from '../../hilosAdminViewMode.js'
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

const preparing = {
  state: 'preparing',
  document: 'terms',
  revisionId: 'current',
  search: 'anna',
  requestedAt: 1000,
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode
const ready = {
  state: 'ready',
  document: null,
  revisionId: null,
  search: null,
  requestedAt: 1000,
  finishedAt: 2000,
  expiresAt: 3000,
  sizeBytes: 456,
  records: 12,
} as const satisfies HilosLegalAcceptancesExportNode
const failed = {
  ...ready,
  state: 'failed',
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode

/**
 * Mount the page with its export: the page answer's section, the frames after
 * it, and the server's word on the confirmation step, answered by `stepUp`.
 *
 * @param options The export the page opens with, the step's answer, and whether a viewer of the admin view mode looks.
 */
async function mountExport(
  options: {
    initial?: HilosLegalAcceptancesExportNode
    stepUp?: 'skip' | 'ask' | 'refused'
    viewer?: boolean
  } = {},
) {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_legal_acceptances')
  if (options.initial !== undefined) {
    page.data.set('legalAcceptancesExport', options.initial)
  }
  const frames: Array<(signal: ProjectSignal) => void> = []
  const sent: Array<{ name: string; data: unknown }> = []
  const stepUp = options.stepUp ?? 'skip'
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') frames.push(listener)
        return () => {}
      },
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
      sendTableFacets: vi.fn(),
    },
    actions: {
      dispatch(name: string, data: unknown) {
        sent.push({ name, data })
        const reply =
          name !== 'hilos_step_up_start'
            ? {}
            : stepUp === 'refused'
              ? new ActionError(
                  name,
                  'fail',
                  'Only an active administrator can do this',
                )
              : {
                  required: stepUp === 'ask',
                  purpose: 'export acceptance records',
                  method: 'password',
                }
        return {
          done:
            reply instanceof Error
              ? Promise.reject(reply)
              : Promise.resolve({ reply }),
        }
      },
    },
  } as unknown as HilosLegalContext
  let controller: TableViewportController<HilosLegalAcceptanceRow> | null = null
  const table = defineComponent({
    props: {
      controller: {
        type: Object as PropType<
          TableViewportController<HilosLegalAcceptanceRow>
        >,
        required: true,
      },
    },
    setup(props) {
      controller = props.controller
      return {}
    },
    template: '<div />',
  })
  const wrapper = mount(HilosLegalAcceptancesPage, {
    props: { context },
    attachTo: document.body,
    global: {
      provide: {
        [hilosRouterKey as symbol]: {
          currentRoute: createSignal({
            page: 'hilos_legal_acceptances',
            params: {},
            admin: true,
          }),
        },
        ...(options.viewer
          ? { [hilosAdminViewModeKey as symbol]: ref(true) }
          : {}),
      },
      stubs: {
        HilosAdminPage: { template: '<main><slot /></main>' },
        HilosViewportTable: table,
        HilosLink: { template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return {
    wrapper,
    sent,
    names: () => sent.map((entry) => entry.name),
    controller: () => {
      if (controller === null) throw new Error('The table was not drawn')
      return controller
    },
    async emit(node: HilosLegalAcceptancesExportNode | null) {
      for (const listener of frames)
        listener({
          type: 'hilos_legal_acceptances_export_state',
          data: { legalAcceptancesExport: node },
        } as unknown as ProjectSignal)
      await flushPromises()
    },
    button: () =>
      wrapper.get<HTMLButtonElement>('[data-id="legal-acceptances-export"]'),
  }
}

describe('HilosLegalAcceptancesPage export (HIL-1234)', () => {
  afterEach(() => {
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
  })

  it('draws the Export button and nothing under it while there is no export', async () => {
    const w = await mountExport()

    expect(w.button().text()).toBe('Export')
    expect(w.button().find('.bi-download').exists()).toBe(true)
    expect(w.button().element.disabled).toBe(false)
    expect(
      w.wrapper.find('[data-id="legal-acceptances-export-filter"]').exists(),
    ).toBe(false)
    expect(
      w.wrapper.find('[data-id="legal-acceptances-export-download"]').exists(),
    ).toBe(false)
    expect(w.wrapper.find('[role="status"]').exists()).toBe(true)
    w.wrapper.unmount()
  })

  it('draws the three states of the export and the link of a ready one', async () => {
    const w = await mountExport({ initial: preparing })

    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-preparing"]').text(),
    ).toContain('Preparing the export… Started at')
    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-filter"]').text(),
    ).toBe('Terms · current · “anna”')
    expect(w.button().element.disabled).toBe(true)

    await w.emit(ready)
    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-ready"]').text(),
    ).toContain('Export ready · 12 records · 456 B · available until')
    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-filter"]').text(),
    ).toBe('All records')
    const link = w.wrapper.get('[data-id="legal-acceptances-export-download"]')
    expect(link.attributes('href')).toBe('/_hilos/legal-acceptances-export')
    expect(link.attributes()).toHaveProperty('download')
    expect(link.text()).toBe('Download')
    expect(w.button().element.disabled).toBe(false)

    await w.emit(failed)
    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-failed"]').text(),
    ).toBe('We could not prepare the export.')
    expect(
      w.wrapper.find('[data-id="legal-acceptances-export-download"]').exists(),
    ).toBe(false)
    w.wrapper.unmount()
  })

  it('orders the table’s filters and search when no confirmation is needed', async () => {
    const w = await mountExport()
    w.controller().setFilter('document', 'terms')
    w.controller().setFilter('revision', 'current')
    w.controller().setSearch('anna')

    await w.button().trigger('click')
    await flushPromises()

    expect(w.sent.at(-1)).toEqual({
      name: 'legal_acceptances_export',
      data: { document: 'terms', revisionId: 'current', search: 'anna' },
    })
    expect(
      document.querySelector('[data-id="legal-acceptances-export-step-up"]'),
    ).toBeNull()
    w.wrapper.unmount()
  })

  it('asks in a window and orders after Confirm', async () => {
    const w = await mountExport({ stepUp: 'ask' })

    await w.button().trigger('click')
    await flushPromises()
    expect(
      document.querySelector('[data-id="legal-acceptances-export-step-up"]'),
    ).not.toBeNull()
    expect(w.names()).toEqual(['hilos_step_up_start'])

    const field = document.querySelector<HTMLInputElement>(
      '[data-id="step-up-password"]',
    )
    expect(document.activeElement).toBe(field)
    if (field === null) throw new Error('No password field')
    field.value = 'a proof'
    field.dispatchEvent(new Event('input'))
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="legal-acceptances-export-confirm"]',
      )
      ?.click()
    await flushPromises()

    expect(w.names()).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
      'legal_acceptances_export',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      document: null,
      revisionId: null,
      search: null,
    })
    await w.emit(preparing)
    expect(
      document.querySelector('[data-id="legal-acceptances-export-step-up"]'),
    ).toBeNull()
    w.wrapper.unmount()
  })

  it('shows a refusal of the order in its reserved place', async () => {
    const w = await mountExport({ stepUp: 'refused' })
    expect(
      w.wrapper
        .find('[data-id="legal-acceptances-export-error-slot"]')
        .exists(),
    ).toBe(true)

    await w.button().trigger('click')
    await flushPromises()

    expect(
      w.wrapper.get('[data-id="legal-acceptances-export-error"]').text(),
    ).toContain('Only an active administrator can do this')
    expect(w.names()).toEqual(['hilos_step_up_start'])
    w.wrapper.unmount()
  })

  it('leaves a viewer of the admin view mode a disabled button that sends nothing', async () => {
    const w = await mountExport({ viewer: true })

    expect(w.button().element.disabled).toBe(true)
    expect(w.button().attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await w.button().trigger('click')
    await flushPromises()
    expect(w.sent).toEqual([])
    w.wrapper.unmount()
  })
})
