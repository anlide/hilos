import { describe, expect, it } from 'vitest'
import {
  createHilosLegalAcceptancesTable,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  hilosLegalDocumentLabel,
  createHilosLegalSettingsActions,
  describeHilosLegalCheck,
  legalAcceptanceFiltersSchema,
  legalAdminDocumentSchema,
  legalAdminRevisionSchema,
  LEGAL_ACCEPTANCES_SIGNAL,
  resolveHilosLegalAcceptanceRow,
  resolveHilosLegalDocumentRow,
  resolveHilosLegalRevisionRow,
  resolveHilosLegalSettingRow,
  hilosLegalLapsedLabel,
  type HilosLegalAcceptanceFilters,
} from '../../../src/admin/legal/hilosLegal.js'
import { type HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import { type TableViewportDescriptor } from '../../../src/connection/HilosConnection.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-01',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 2,
}
const vocabulary: HilosLegalAcceptanceFilters = {
  documents: [
    {
      document: 'terms',
      declared: true,
      revisions: [
        { revisionId: 'current', declared: true },
        { revisionId: 'off-window', declared: false },
      ],
    },
    {
      document: 'privacy',
      declared: true,
      revisions: [{ revisionId: 'privacy-old', declared: true }],
    },
  ],
}

/** A real scope manager and a connection whose listeners replay the early page frame. */
function harness(early?: HilosLegalAcceptanceFilters) {
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const sent: Array<{
    page: string
    table: string
    descriptor: TableViewportDescriptor
  }> = []
  const actions: Array<{ name: string; payload: unknown }> = []
  const facets: unknown[] = []
  let latest = early
  const connection = {
    on(event: string, listener: (frame: never) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      listeners.set(event, group)
      group.add(listener)
      if (event === 'projectSignal' && latest !== undefined)
        listener({ type: LEGAL_ACCEPTANCES_SIGNAL, data: latest } as never)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow() {},
    unregisterTableWindow() {},
    sendTableViewport(
      page: string,
      table: string,
      descriptor: TableViewportDescriptor,
    ) {
      sent.push({ page, table, descriptor })
    },
    sendTableRendered() {},
    sendTableFacets(_page: string, _table: string, values: unknown) {
      facets.push(values)
    },
  }
  const context = {
    connection,
    scopes: new ScopeManager(),
    actions: {
      dispatch(name: string, payload: unknown) {
        actions.push({ name, payload })
        return { done: Promise.resolve({}) }
      },
    },
  } as unknown as HilosLegalContext
  return {
    context,
    sent,
    actions,
    facets,
    emit(data: HilosLegalAcceptanceFilters) {
      latest = data
      for (const listener of listeners.get('projectSignal') ?? [])
        listener({ type: LEGAL_ACCEPTANCES_SIGNAL, data } as never)
    },
    listenerCount: () =>
      [...listeners.values()].reduce((count, group) => count + group.size, 0),
  }
}

describe('legal administration rows and sections', () => {
  it.each(['constructor', '__proto__', 'toString', '123', 'retired'])(
    'keeps the literal historical document key %s',
    (key) => {
      expect(hilosLegalDocumentLabel(key)).toBe(key)
    },
  )

  it('retains server counts and revision metadata', () => {
    expect(
      resolveHilosLegalDocumentRow({
        rowKey: 'terms',
        slots: {
          document: {
            declared: true,
            revision,
            covered: 4,
            window: 2,
            lapsed: 1,
          },
        },
      }),
    ).toEqual({
      rowKey: 'terms',
      declared: true,
      revision,
      covered: 4,
      window: 2,
      lapsed: 1,
    })
    expect(
      resolveHilosLegalRevisionRow({
        rowKey: 'gone',
        slots: {
          revision: {
            declared: false,
            revision: null,
            current: false,
            origin: null,
            heldCount: 9,
            acceptedCount: 12,
          },
        },
      }),
    ).toEqual({
      rowKey: 'gone',
      declared: false,
      revision: null,
      current: false,
      origin: null,
      heldCount: 9,
      acceptedCount: 12,
    })
    expect(
      resolveHilosLegalSettingRow({
        rowKey: 'legal.consent_form',
        slots: { setting: { value: 'line', defaultValue: 'checkbox' } },
      }),
    ).toEqual({
      rowKey: 'legal.consent_form',
      value: 'line',
      defaultValue: 'checkbox',
    })
  })

  it('reads the personal fields sent hidden as the one hidden value (HIL-1260)', () => {
    const acceptance = resolveHilosLegalAcceptanceRow({
      rowKey: '43',
      slots: {
        acceptance: {
          userId: 9,
          name: { _hidden: true },
          email: { _hidden: true },
          document: 'terms',
          revisionId: 'old',
          declared: true,
          acceptedAt: '2026-09-27 12:00:00',
        },
      },
    })
    expect(acceptance.name).toBe(HIDDEN_VALUE)
    expect(acceptance.email).toBe(HIDDEN_VALUE)

    const setting = resolveHilosLegalSettingRow({
      rowKey: 'legal.refusal_after_deadline',
      slots: {
        setting: { value: { _hidden: true }, defaultValue: { _hidden: true } },
      },
    })
    expect(setting.value).toBe(HIDDEN_VALUE)
    expect(setting.defaultValue).toBe(HIDDEN_VALUE)
  })

  it.each([
    ['remind', 'Past deadline'],
    ['hidden', 'Past deadline'],
    ['freeze', 'Frozen'],
    ['unread', 'Frozen'],
  ] as const)(
    'labels the lapsed count under a %s refusal setting "%s" (HIL-1260)',
    (refusal, label) => {
      const value =
        refusal === 'hidden'
          ? HIDDEN_VALUE
          : refusal === 'unread'
            ? undefined
            : refusal
      expect(hilosLegalLapsedLabel(value)).toBe(label)
    },
  )

  it.each([true, false, null])(
    'preserves declared=%s for acceptance records',
    (declared) => {
      const row = resolveHilosLegalAcceptanceRow({
        rowKey: '42',
        slots: {
          acceptance: {
            userId: 9,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'old',
            declared,
            acceptedAt: '2026-09-27 12:00:00',
          },
        },
      })
      expect(row).toEqual({
        rowKey: 42,
        userId: 9,
        name: 'Reader',
        email: null,
        document: 'terms',
        revisionId: 'old',
        declared,
        acceptedAt: '2026-09-27 12:00:00',
      })
    },
  )

  it('accepts historical page data with no invented text', () => {
    expect(
      legalAdminRevisionSchema.safeParse({
        document: 'terms',
        revisionId: 'gone',
        declared: false,
        revision: null,
        current: null,
        predecessorId: null,
        clauses: null,
        changes: null,
      }).success,
    ).toBe(true)
    expect(
      legalAdminDocumentSchema.safeParse({
        document: 'retired',
        declared: false,
        revision: null,
        set: null,
        newerSet: null,
        deviations: [],
      }).success,
    ).toBe(true)
    expect(
      legalAcceptanceFiltersSchema.safeParse({
        documents: [
          {
            document: 'retired',
            declared: null,
            revisions: [{ revisionId: 'gone', declared: null }],
          },
        ],
      }).success,
    ).toBe(true)
    expect(
      legalAcceptanceFiltersSchema.safeParse({
        documents: [{ document: 'terms' }],
      }).success,
    ).toBe(false)
  })

  it('describes each backend check without recomputing its verdict', () => {
    expect(
      describeHilosLegalCheck({
        rowKey: 'undeclared_revision',
        ok: false,
        items: [{ document: 'terms', revisionId: 'gone', people: 3 }],
      }).lines[0],
    ).toContain('3 people')
    expect(
      describeHilosLegalCheck({
        rowKey: 'zero_window',
        ok: false,
        items: [{ document: 'terms', revisionId: 'new' }],
      }).lines[0],
    ).toContain('publication')
    expect(
      describeHilosLegalCheck({
        rowKey: 'newer_standard_set',
        ok: false,
        items: [
          {
            document: 'terms',
            setVersion: 2,
            documentSetVersion: 1,
            significance: 'substantial',
          },
        ],
      }).lines[0],
    ).toContain('set 2 (substantial)')
    expect(
      describeHilosLegalCheck({
        rowKey: 'deviations',
        ok: false,
        items: [{ document: 'privacy', deviations: 0 }],
      }).lines[0],
    ).toContain('no differences')
  })
})

describe('legal table controllers', () => {
  it('shows zero after the last historical acceptance disappears and distinguishes a refused read', () => {
    const h = harness()
    const revisionId = createSignal('gone')
    const table = createHilosLegalRevisionsTable(
      h.context,
      createSignal('terms'),
      'hilos_legal_revision',
      revisionId,
    )
    const summary = createHilosLegalRevisionAcceptanceSummary(
      table.controller,
      revisionId,
    )
    expect(summary.get()).toBe('Loading acceptance count…')
    table.controller.ingestWindow(
      [{ rowKey: 'gone', slots: { revision: { acceptedCount: 1 } } }],
      1,
      true,
      null,
      null,
      25,
    )
    expect(summary.get()).toBe('1 acceptances')
    table.controller.ingestWindow([], 0, true, null, null, 25)
    expect(summary.get()).toBe('0 acceptances')
    table.controller.ingestRefusal('internal_error')
    expect(summary.get()).toBe('Acceptance count unavailable.')
  })

  it('reads the complete early vocabulary and atomically clears a revision on document changes', () => {
    const h = harness(vocabulary)
    const table = createHilosLegalAcceptancesTable(h.context)
    table.start()
    const filters = table.controller.frame.declaration?.filters ?? []
    const documents = filters[0]
    const revisions = filters[1]
    expect(documents?.kind).toBe('select')
    expect(revisions?.kind).toBe('select')
    if (documents?.kind !== 'select' || revisions?.kind !== 'select')
      throw new Error('Expected selects')
    expect(documents.options().map((option) => option.value)).toEqual([
      'terms',
      'privacy',
    ])
    expect(revisions.options()).toEqual([])
    table.controller.ingestWindow([], 0, true, null, null, 25)
    table.controller.setFilter('document', 'terms')
    expect(revisions.options()).toEqual([
      { value: 'current', label: 'current' },
      { value: 'off-window', label: 'off-window (not in code)' },
    ])
    table.controller.setFilter('revision', 'off-window')
    h.sent.length = 0
    table.controller.setFilter('document', 'privacy')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
    expect(h.sent).toHaveLength(1)
    expect(h.sent[0]?.descriptor.filter).toEqual({ document: 'privacy' })
    expect(revisions.options()).toEqual([
      { value: 'privacy-old', label: 'privacy-old' },
    ])
    h.emit({
      documents: [
        {
          document: 'privacy',
          declared: null,
          revisions: [{ revisionId: 'new-on-record', declared: null }],
        },
      ],
    })
    expect(revisions.options()).toEqual([
      { value: 'new-on-record', label: 'new-on-record' },
    ])
    expect(h.facets.at(-1)).toEqual({
      document: ['privacy'],
      revision: ['new-on-record'],
    })
    table.dispose()
    expect(h.listenerCount()).toBe(0)
    h.emit(vocabulary)
    expect(revisions.options()).toEqual([
      { value: 'new-on-record', label: 'new-on-record' },
    ])
  })

  it('keeps the revision table narrowed when the route document changes', () => {
    const h = harness()
    const document = createSignal('terms')
    const table = createHilosLegalRevisionsTable(h.context, document)
    table.start()
    expect(table.controller.filter.get()).toEqual({ document: 'terms' })
    document.set('privacy')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
    table.dispose()
    document.set('terms')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
  })

  it('requests the exact revision on its detail page, even beyond the history window', () => {
    const h = harness()
    const document = createSignal('terms')
    const revision = createSignal('old')
    const table = createHilosLegalRevisionsTable(
      h.context,
      document,
      'hilos_legal_revision',
      revision,
    )
    table.start()
    expect(table.controller.filter.get()).toEqual({
      document: 'terms',
      revision: 'old',
    })
    revision.set('gone')
    expect(table.controller.filter.get()).toEqual({
      document: 'terms',
      revision: 'gone',
    })
    table.dispose()
  })

  it('sends only the named legal setting and selected value', () => {
    const h = harness()
    createHilosLegalSettingsActions(h.context).sendSettingSet(
      'legal.consent_form',
      'line',
    )
    expect(h.actions).toEqual([
      {
        name: 'legal_setting_set',
        payload: { key: 'legal.consent_form', value: 'line' },
      },
    ])
  })
})
