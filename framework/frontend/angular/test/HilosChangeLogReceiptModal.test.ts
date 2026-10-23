// The Angular peer of vue/src/admin/changeLog/HilosChangeLogReceiptModal.test.ts
// and react/test/HilosChangeLogReceiptModal.test.tsx, under the same case names
// and over the same fixtures: the value sides drawn whole and escaped, creation
// and deletion, the attribution of guests, systems, deleted people and the
// migration runner, the record link and when it is withheld, and pagination only
// past the first portion.
import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  HilosPages,
  type HilosChangeLogEntry,
  type HilosChangeLogReceiptDialog,
  type HilosChangeLogReceiptHeader,
  type HilosChangeLogReceiptReader,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { formatChangeLogTime } from '@hilos/core/admin/change-log'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogReceiptModal } from '../src/admin/changeLog/HilosChangeLogReceiptModal.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

let timeZoneBinding: TimeZoneTestBinding | null = null

afterEach(() => {
  timeZoneBinding?.release()
  timeZoneBinding = null
  vi.restoreAllMocks()
})

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
        ? `/hilos/change-log/tables/${encodeURIComponent(params['tableId'] ?? '')}/${encodeURIComponent(params['record'] ?? '')}`
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

function render(value: HilosChangeLogReceiptDialog, navigator?: HilosRouter) {
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
  TestBed.configureTestingModule({
    providers: navigator
      ? [{ provide: HILOS_ROUTER, useValue: navigator }]
      : [],
  })
  const fixture = TestBed.createComponent(HilosChangeLogReceiptModal)
  fixture.componentRef.setInput('dialog', value)
  fixture.componentRef.setInput('reader', reader)
  fixture.detectChanges()
  return {
    fixture,
    detail: fixture.nativeElement.querySelector(
      '[data-id="hilos-change-log-detail"]',
    ) as HTMLElement,
    reader,
  }
}

describe('HilosChangeLogReceiptModal', () => {
  it('reformats an open receipt in the live reader zone and keeps the same dialog', () => {
    const value = dialog()
    const receiptHeader = value.header
    if (receiptHeader === null) {
      throw new Error('The receipt fixture must include its header.')
    }
    const mounted = render(value)
    const when = mounted.detail.querySelector('dl dd') as HTMLElement
    const reader = mounted.reader
    timeZoneBinding = bindTestTimeZone('America/New_York')

    const receiveAndRead = (timeZone: string | null): string => {
      timeZoneBinding?.receive(timeZone)
      mounted.fixture.detectChanges()
      expect(mounted.fixture.componentInstance.dialog()).toBe(value)
      expect(mounted.detail.querySelector('dl dd')).toBe(when)
      const actual = when.textContent?.trim() ?? ''
      expect(actual).toBe(formatChangeLogTime(receiptHeader.createdAt))
      return actual
    }

    const utc = receiveAndRead('UTC')
    const kyiv = receiveAndRead('Europe/Kyiv')
    const automatic = receiveAndRead(null)
    expect(utc).not.toBe(kyiv)
    expect(kyiv).not.toBe(automatic)
    expect(mounted.reader).toBe(reader)
    expect(timeZoneBinding.listenerCount()).toBe(2)

    mounted.fixture.destroy()
    const destroyedText = when.textContent
    timeZoneBinding.rebind()
    expect(timeZoneBinding.listenerCount()).toBe(2)
    timeZoneBinding.receive('UTC')
    expect(when.textContent).toBe(destroyedText)

    const rebound = TestBed.createComponent(HilosChangeLogReceiptModal)
    rebound.componentRef.setInput('dialog', value)
    rebound.componentRef.setInput('reader', reader)
    rebound.detectChanges()
    timeZoneBinding.receive('Europe/Kyiv')
    rebound.detectChanges()
    expect(
      (rebound.nativeElement as HTMLElement)
        .querySelector('[data-id="hilos-change-log-detail"] dl dd')
        ?.textContent?.trim(),
    ).toBe(formatChangeLogTime(receiptHeader.createdAt))
    rebound.destroy()
  })

  it('shows complete escaped long values and distinct absent, NULL, and empty sides', () => {
    const oldText = 'old <script>bad()</script>\n' + 'x'.repeat(6000)
    const newText = 'new <img src=x onerror=bad()>\n' + 'y'.repeat(6000)
    const { detail } = render(
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
    const { detail } = render(
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
    const { detail } = render(
      dialog([entry()], header({ actorId: null, actorLabel: null, channel })),
    )
    expect(detail.textContent).toContain(label)
    expect(detail.textContent).toContain(explanation)
    expect(detail.textContent).not.toContain('Unknown')
    expect(detail.textContent).not.toContain('Deleted user')
  })

  it('retains a deleted person number and the actual cron writer', () => {
    const { detail } = render(
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
    const { detail } = render(
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
    const { detail, reader } = render(
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
    link.click()
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
    const { detail } = render(
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
      const { detail } = render(dialog([entry()], header({ entryCount })))
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
