import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { HilosPages, createSignal } from '@hilos/core'
import type {
  HilosChangeLogEntry,
  HilosChangeLogReceiptDialog,
  HilosChangeLogReceiptHeader,
  HilosChangeLogReceiptReader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosChangeLogReceiptModal } from '../src/admin/changeLog/HilosChangeLogReceiptModal.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function header(
  overrides: Partial<HilosChangeLogReceiptHeader> = {},
): HilosChangeLogReceiptHeader {
  return {
    receiptId: 7,
    createdAt: '2026-10-09T10:11:12.123Z',
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    sessionId: 5,
    channel: 'web',
    source: 'hilos_app_bots',
    action: 'bot.update',
    agent: 'library',
    entryCount: 1,
    ...overrides,
  }
}

function entry(
  overrides: Partial<HilosChangeLogEntry> = {},
): HilosChangeLogEntry {
  return {
    entryId: 11,
    receiptId: 7,
    createdAt: '2026-10-09T10:11:12.123Z',
    table: 'bot',
    recordKey: [3],
    mutation: 'update',
    changes: [],
    ...overrides,
  }
}

function dialog(
  entries = [entry()],
  receiptHeader = header(),
): HilosChangeLogReceiptDialog {
  return {
    kind: 'receipt',
    receiptId: 7,
    header: receiptHeader,
    entries,
    busyInitial: false,
    busyMore: false,
    refusal: null,
    outcome: null,
    nextAfterEntryId: null,
    expanded: false,
  }
}

/** A navigator whose route map gives the Tracked table page an address only when built. */
function router(tableBuilt: boolean): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page: string, params: Record<string, string> = {}) =>
      tableBuilt && page === HilosPages.CHANGE_LOG_TABLE
        ? `/hilos/change-log/tables/${encodeURIComponent(params.tableId ?? '')}/${encodeURIComponent(params.record ?? '')}`
        : undefined,
    ),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * Draw the modal over a stub reader, inside a navigator when one is given.
 *
 * @param value The snapshot the modal shows.
 * @param navigator The navigator in context, if any.
 */
function mountModal(
  value: HilosChangeLogReceiptDialog,
  navigator?: HilosRouter,
) {
  const reader: HilosChangeLogReceiptReader = {
    dialog: createSignal(value),
    openReceipt: vi.fn(async () => {}),
    openEntry: vi.fn(async () => {}),
    more: vi.fn(async () => {}),
    retry: vi.fn(async () => {}),
    collapse: vi.fn(),
    close: vi.fn(),
    dispose: vi.fn(),
  }
  const modal = <HilosChangeLogReceiptModal dialog={value} reader={reader} />
  render(
    navigator ? (
      <HilosRouterContext.Provider value={navigator}>
        {modal}
      </HilosRouterContext.Provider>
    ) : (
      modal
    ),
  )
  return {
    detail: document.querySelector(
      '[data-id="hilos-change-log-detail"]',
    ) as HTMLElement,
    reader,
  }
}

describe('HilosChangeLogReceiptModal', () => {
  // The modal is portalled to the document body, so a modal left mounted would
  // stay there for the next case to find.
  afterEach(cleanup)

  it('shows complete escaped long values and distinct absent, NULL, and empty sides', () => {
    const oldText = 'old <script>bad()</script>\n' + 'x'.repeat(6000)
    const newText = 'new <img src=x onerror=bad()>\n' + 'y'.repeat(6000)
    const { detail } = mountModal(
      dialog([
        entry({
          changes: [
            {
              field: 'description',
              kind: 'long',
              oldPresent: true,
              newPresent: true,
              oldValue: oldText,
              newValue: newText,
              bodyOmitted: false,
            },
            {
              field: 'nullable',
              kind: 'inline',
              oldPresent: true,
              newPresent: true,
              oldValue: null,
              newValue: '',
              bodyOmitted: false,
            },
            {
              field: 'added',
              kind: 'inline',
              oldPresent: false,
              newPresent: true,
              oldValue: null,
              newValue: 'yes',
              bodyOmitted: false,
            },
            {
              field: 'name',
              kind: 'fact',
              oldPresent: true,
              newPresent: true,
              oldValue: 'private old',
              newValue: 'private new',
              bodyOmitted: false,
            },
          ],
        }),
      ]),
    )
    expect(
      detail.querySelector(
        '[data-id="hilos-change-log-value-11-description-old"]',
      )?.textContent,
    ).toBe(oldText)
    expect(
      detail.querySelector(
        '[data-id="hilos-change-log-value-11-description-new"]',
      )?.textContent,
    ).toBe(newText)
    expect(detail.querySelectorAll('script, img')).toHaveLength(0)
    expect(
      detail.querySelector('[data-id="hilos-change-log-value-11-nullable-old"]')
        ?.textContent,
    ).toBe('NULL')
    expect(
      detail.querySelector('[data-id="hilos-change-log-value-11-nullable-new"]')
        ?.textContent,
    ).toBe('Empty string')
    expect(
      detail.querySelector('[data-id="hilos-change-log-value-11-added-old"]')
        ?.textContent,
    ).toBe('Absent')
    expect(detail.textContent).toContain('Personal — no value')
    expect(detail.textContent).not.toContain('private old')
    expect(detail.textContent).not.toContain('private new')
    expect(
      detail.querySelector('pre')?.classList.contains('hilos-pre-wrap'),
    ).toBe(true)
  })

  it('draws creation as a fact and deletion from its saved snapshot with an anonymized key', () => {
    const changes = [
      {
        field: 'description',
        kind: 'long' as const,
        oldPresent: true,
        newPresent: false,
        oldValue: 'saved snapshot',
        newValue: null,
        bodyOmitted: false,
      },
    ]
    const { detail } = mountModal(
      dialog(
        [
          entry({ mutation: 'create', changes }),
          entry({
            entryId: 12,
            mutation: 'delete',
            recordKey: [null, 'retained'],
            changes,
          }),
        ],
        header({ entryCount: 2 }),
      ),
    )
    const created = detail.querySelector(
      '[data-id="hilos-change-log-entry-11"]',
    )!
    expect(created.textContent).toContain(
      'Created as a fact; values belong to the record itself.',
    )
    expect(created.textContent).not.toContain('saved snapshot')
    const deleted = detail.querySelector(
      '[data-id="hilos-change-log-entry-12"]',
    )!
    expect(deleted.textContent).toContain('bot #?, retained')
    expect(deleted.textContent).toContain('Snapshot before deletion:')
    expect(deleted.textContent).toContain('saved snapshot')
    expect(
      deleted.querySelector(
        '[data-id="hilos-change-log-value-12-description-new"]',
      ),
    ).toBeNull()
  })

  it.each([
    ['web', 'Guest', 'Guest — no account yet.'],
    ['cron', 'System', 'System — no person.'],
  ])('keeps %s attribution distinct', (channel, label, explanation) => {
    const { detail } = mountModal(
      dialog([entry()], header({ actorId: null, actorLabel: null, channel })),
    )
    expect(detail.textContent).toContain(label)
    expect(detail.textContent).toContain(explanation)
    expect(detail.textContent).not.toContain('Unknown')
    expect(detail.textContent).not.toContain('Deleted user')
  })

  it('retains a deleted person number and the actual cron writer', () => {
    const { detail } = mountModal(
      dialog(
        [entry()],
        header({
          actorId: 9,
          actorLabel: 'Deleted user #9',
          actorDeleted: true,
          channel: 'cron',
          action: 'sweep_second_factor_remove',
          agent: 'hilos_user:9',
          source: '* * * * *',
        }),
      ),
    )
    expect(detail.textContent).toContain('Deleted user #9')
    expect(detail.textContent).toContain(
      'This person was deleted; the receipt keeps their number.',
    )
    expect(detail.textContent).toContain('hilos_user:9')
    expect(detail.textContent).toContain('* * * * *')
    expect(detail.textContent).toContain('sweep_second_factor_remove')
  })

  it('shows the migration runner only for migration with no recorded writer', () => {
    const { detail } = mountModal(
      dialog(
        [entry()],
        header({ channel: 'migration', agent: null, source: null }),
      ),
    )
    expect(detail.textContent).toContain('Migration runner')
    expect(detail.textContent).toContain('bot.update')
  })

  it('links an unmasked record to its Tracked table and closes on the way', () => {
    const navigator = router(true)
    const { detail, reader } = mountModal(
      dialog([entry({ recordKey: [3, 'a,b'] })]),
      navigator,
    )
    const link = detail.querySelector(
      '[data-id="hilos-change-log-receipt-record-11"]',
    ) as HTMLAnchorElement
    expect(link).not.toBeNull()
    expect(link.tagName).toBe('A')
    expect(link.textContent).toBe('bot #3, a,b')
    expect(navigator.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      { tableId: 'bot', record: '3,a%2Cb' },
    )
    expect(link.getAttribute('href')).toBe(
      '/hilos/change-log/tables/bot/3%2Ca%252Cb',
    )
    act(() => link.click())
    expect(reader.close).toHaveBeenCalled()
    expect(navigator.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/3%2Ca%252Cb',
    )
  })

  it.each([
    ['without a router', undefined, [3]],
    ['while the page is unbuilt', false, [3]],
    ['for a masked key', true, [null, 'retained']],
  ] as const)('keeps the record as text %s', (_case, tableBuilt, recordKey) => {
    const { detail } = mountModal(
      dialog([entry({ recordKey: [...recordKey] })]),
      tableBuilt === undefined ? undefined : router(tableBuilt),
    )
    expect(
      detail.querySelector('[data-id="hilos-change-log-receipt-record-11"]'),
    ).toBeNull()
    expect(detail.querySelector('a')).toBeNull()
    expect(
      detail.querySelector('[data-id="hilos-change-log-entry-11"] h3 code')
        ?.textContent,
    ).toBe(recordKey[0] === null ? 'bot #?, retained' : 'bot #3')
  })

  it.each([1, 50])(
    'offers no pagination controls for %s entries',
    (entryCount) => {
      const { detail } = mountModal(dialog([entry()], header({ entryCount })))
      expect(
        detail.querySelector('[data-id="hilos-change-log-detail-count"]'),
      ).toBeNull()
      expect(
        detail.querySelector('[data-id="hilos-change-log-more"]'),
      ).toBeNull()
      expect(
        detail.querySelector('[data-id="hilos-change-log-hide"]'),
      ).toBeNull()
    },
  )
})
