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
import { formatMoment } from '../../../src/format/moment.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'

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
    projectTimeZone: 'UTC',
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
    choose(timeZone: string) {
      for (const listener of listeners.get('projectSignal') ?? []) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as never)
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
    sendTableRowFocus() {},
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
    choose(timeZone: string) {
      connection.choose(timeZone)
    },
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
          acceptedAt: 1790510400000,
        },
      },
    })
    expect(acceptance.name).toBe(HIDDEN_VALUE)
    expect(acceptance.email).toBe(HIDDEN_VALUE)
    expect(acceptance.acceptedAt).toBe(
      formatMoment(1790510400000, { kind: 'dateTime' }),
    )

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
            acceptedAt: 1790510400000,
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
        acceptedAt: formatMoment(1790510400000, { kind: 'dateTime' })!,
      })
    },
  )

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['old SQL string', '2026-09-27 12:00:00'],
    ['numeric string', '1790510400000'],
    ['NaN', Number.NaN],
    ['Infinity', Number.POSITIVE_INFINITY],
    ['out of date range', 1e16],
  ])(
    'formats invalid or non-numeric acceptedAt (%s) as em-dash',
    (_, acceptedAt) => {
      const row = resolveHilosLegalAcceptanceRow({
        rowKey: '42',
        slots: {
          acceptance: {
            userId: 9,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'old',
            declared: true,
            acceptedAt,
          },
        },
      })
      expect(row.acceptedAt).toBe('—')
    },
  )

  it('formats numeric 0 as the valid epoch moment rather than fallback', () => {
    const row = resolveHilosLegalAcceptanceRow({
      rowKey: '42',
      slots: {
        acceptance: {
          userId: 9,
          name: 'Reader',
          email: null,
          document: 'terms',
          revisionId: 'old',
          declared: true,
          acceptedAt: 0,
        },
      },
    })
    expect(row.acceptedAt).toBe(formatMoment(0, { kind: 'dateTime' }))
    expect(row.acceptedAt).not.toBe('—')
  })

  it('formats acceptedAt in UTC, Europe/Kyiv, and Asia/Tokyo with calendar day transition and DST', () => {
    const h = harness()
    const release = bindTimeZoneState(h.context.connection)
    try {
      const lateMoment = 1790548200000 // 2026-09-27 22:30:00 UTC
      const tableRow = {
        rowKey: '1',
        slots: {
          acceptance: {
            userId: 1,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'current',
            declared: true,
            acceptedAt: lateMoment,
          },
        },
      }

      h.choose('UTC')
      const utcRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(utcRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(utcRow.acceptedAt).toContain('Sep 27, 2026')

      h.choose('Europe/Kyiv')
      const kyivRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(kyivRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(kyivRow.acceptedAt).toContain('Sep 28, 2026')

      h.choose('Asia/Tokyo')
      const tokyoRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(tokyoRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(tokyoRow.acceptedAt).toContain('Sep 28, 2026')

      // DST transition in Europe/Kyiv (2026-10-25)
      // 2026-10-25 00:30:00 UTC (EEST, UTC+3 -> 03:30)
      const summerMoment = 1792888200000
      h.choose('Europe/Kyiv')
      const summerRow = resolveHilosLegalAcceptanceRow({
        rowKey: '2',
        slots: {
          acceptance: {
            ...tableRow.slots.acceptance,
            acceptedAt: summerMoment,
          },
        },
      })
      expect(summerRow.acceptedAt).toContain('3:30:00')

      // 2026-10-25 02:30:00 UTC (EET, UTC+2 -> 04:30)
      const winterMoment = 1792895400000
      const winterRow = resolveHilosLegalAcceptanceRow({
        rowKey: '3',
        slots: {
          acceptance: {
            ...tableRow.slots.acceptance,
            acceptedAt: winterMoment,
          },
        },
      })
      expect(winterRow.acceptedAt).toContain('4:30:00')
    } finally {
      release()
    }
  })

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
    const filters = table.controller.frame.filters
      .get()
      .map(({ filter }) => filter)
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

  it('updates display labels in controller.rows and focusedRow on timezone change without sendViewport', () => {
    const h = harness(vocabulary)
    const release = bindTimeZoneState(h.context.connection)
    try {
      h.choose('UTC')
      const table = createHilosLegalAcceptancesTable(h.context)
      table.start()
      const rawMoment = 1790548200000 // 2026-09-27 22:30:00 UTC
      table.controller.ingestWindow(
        [
          {
            rowKey: '100',
            slots: {
              acceptance: {
                userId: 42,
                name: 'Focused acceptor',
                email: 'acceptor@example.com',
                document: 'terms',
                revisionId: 'current',
                declared: true,
                acceptedAt: rawMoment,
              },
            },
          },
        ],
        1,
        true,
        null,
        null,
        25,
      )
      table.controller.focusRow('100')

      expect(table.controller.rows.get()[0]?.row?.acceptedAt).toContain(
        'Sep 27, 2026',
      )
      expect(table.controller.focusedRow.get()?.acceptedAt).toContain(
        'Sep 27, 2026',
      )

      h.sent.length = 0

      h.choose('Europe/Kyiv')

      expect(h.sent).toHaveLength(0)
      expect(table.controller.rows.get()[0]?.row?.acceptedAt).toContain(
        'Sep 28, 2026',
      )
      expect(table.controller.focusedRow.get()?.acceptedAt).toContain(
        'Sep 28, 2026',
      )

      table.dispose()
    } finally {
      release()
    }
  })
})
