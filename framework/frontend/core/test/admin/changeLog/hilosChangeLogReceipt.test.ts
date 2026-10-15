import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  CHANGE_LOG_RECEIPT_COPY,
  HILOS_CHANGE_LOG_ENTRY_READ,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  changeLogEntryDetails,
  changeLogEntryReadReplySchema,
  changeLogFieldValue,
  changeLogReceiptAttribution,
  changeLogReceiptPagination,
  changeLogReceiptReadReplySchema,
  createHilosChangeLogReceiptReader,
  formatChangeLogReceiptTitle,
  type HilosChangeLogEntry,
  type HilosChangeLogReceiptDialog,
  type HilosChangeLogReceiptHeader,
  type HilosChangeLogReceiptReadReply,
} from '../../../src/admin/changeLog/hilosChangeLogReceipt.js'
import { type HilosChangeLogFieldChange } from '../../../src/admin/changeLog/hilosChangeLogHistory.js'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../../src/connection/actionLifecycle.js'
import {
  type ConnectionState,
  type HilosConnection,
} from '../../../src/connection/HilosConnection.js'
import {
  type ActionErrorSignal,
  type ActionSuccessSignal,
} from '../../../src/protocol/parseSignal.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Exercise the real tracked lifecycle, including its parser, correlation and failures. */
class Source implements ActionLifecycleSource {
  connected = true
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof Events]: ((payload: Events[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })
    return this.connected
  }

  on<E extends keyof Events>(
    event: E,
    listener: (payload: Events[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)
    return () => {
      const index = this.listeners[event].indexOf(listener)
      if (index !== -1) this.listeners[event].splice(index, 1)
    }
  }

  success(index: number, reply?: unknown): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action: request.action,
        requestId: request.requestId,
        reply,
        message: undefined,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  fail(index: number, reason = 'Could not read the journal'): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action: request.action,
        requestId: request.requestId,
        reason,
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        envelope: { type: 'action_error', data: {} },
      })
    }
  }

  state(state: ConnectionState): void {
    this.connected = state === 'connected'
    for (const listener of this.listeners.state) listener(state)
  }
}

function header(
  overrides: Partial<HilosChangeLogReceiptHeader> = {},
): HilosChangeLogReceiptHeader {
  return {
    receiptId: 42,
    createdAt: '2026-10-14T10:00:00.123Z',
    actorId: 7,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    sessionId: 11,
    channel: 'web',
    action: 'bot_update',
    agent: 'bot:9',
    source: '11',
    entryCount: 1,
    ...overrides,
  }
}

function change(
  overrides: Partial<HilosChangeLogFieldChange> = {},
): HilosChangeLogFieldChange {
  return {
    field: 'prompt',
    kind: 'long',
    oldPresent: true,
    newPresent: true,
    oldValue: 'Before\n\n<em>plain text</em>',
    newValue: 'After\n  with indentation',
    bodyOmitted: false,
    ...overrides,
  }
}

function entry(
  entryId = 1,
  overrides: Partial<HilosChangeLogEntry> = {},
): HilosChangeLogEntry {
  return {
    entryId,
    createdAt: '2026-10-14T10:00:00.123Z',
    receiptId: 42,
    table: 'bot',
    recordKey: [9],
    mutation: 'update',
    changes: [change()],
    ...overrides,
  }
}

function portion(total: number, after = 0): HilosChangeLogReceiptReadReply {
  const end = Math.min(total, after + 50)
  return {
    receipt: header({ entryCount: total }),
    entries: Array.from({ length: end - after }, (_, index) =>
      entry(after + index + 1),
    ),
    nextAfterEntryId: end < total ? end : null,
  }
}

function world() {
  const source = new Source()
  const scopes = new ScopeManager()
  scopes.openPage('hilos_change_log')
  const actions = new ActionLifecycle(source, { timeoutMs: 1000 })
  const reader = createHilosChangeLogReceiptReader({
    actions,
    scopes,
    connection: source as unknown as HilosConnection,
  })
  return { source, scopes, reader }
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  vi.clearAllTimers()
  vi.useRealTimers()
})

describe('change-log detail schemas', () => {
  it('preserves hidden labels as the shared mark, person ids, and anonymized keys', () => {
    const reply = changeLogReceiptReadReplySchema.parse({
      receipt: header({
        actorLabel: { _hidden: true },
        subjectId: 8,
        subjectLabel: { _hidden: true },
      }),
      entries: [
        entry(1, { recordKey: null }),
        entry(2, { recordKey: [null, 'en', 5] }),
      ],
      nextAfterEntryId: null,
    })
    expect(reply.receipt.actorLabel).toBe(HIDDEN_VALUE)
    expect(reply.receipt.subjectLabel).toBe(HIDDEN_VALUE)
    expect(reply.receipt.actorId).toBe(7)
    expect(reply.receipt.subjectId).toBe(8)
    expect(reply.entries.map(({ recordKey }) => recordKey)).toEqual([
      null,
      [null, 'en', 5],
    ])
    expect(
      changeLogEntryReadReplySchema.parse({
        entry: entry(5, { receiptId: null }),
      }).entry.entryId,
    ).toBe(5)
  })

  it('requires every header, entry, change, and reply field without coercing defaults', () => {
    const valid = portion(1)
    for (const key of Object.keys(valid)) {
      const reply: Record<string, unknown> = { ...valid }
      delete reply[key]
      expect(
        changeLogReceiptReadReplySchema.safeParse(reply).success,
        key,
      ).toBe(false)
    }
    for (const key of Object.keys(valid.receipt)) {
      const receipt: Record<string, unknown> = { ...valid.receipt }
      delete receipt[key]
      expect(
        changeLogReceiptReadReplySchema.safeParse({ ...valid, receipt })
          .success,
        key,
      ).toBe(false)
    }
    for (const key of Object.keys(entry())) {
      const row: Record<string, unknown> = { ...entry() }
      delete row[key]
      expect(
        changeLogEntryReadReplySchema.safeParse({ entry: row }).success,
        key,
      ).toBe(false)
    }
    for (const key of Object.keys(change())) {
      const field: Record<string, unknown> = { ...change() }
      delete field[key]
      expect(
        changeLogEntryReadReplySchema.safeParse({
          entry: { ...entry(), changes: [field] },
        }).success,
        key,
      ).toBe(false)
    }
  })

  it('rejects malformed ids, marks, value sides, enums and unknown fields', () => {
    for (const receipt of [
      { ...header(), receiptId: 0 },
      { ...header(), actorId: '7' },
      { ...header(), subjectId: 0 },
      { ...header(), sessionId: 1.5 },
      { ...header(), actorLabel: { _hidden: false } },
      { ...header(), subjectLabel: { _hidden: true, name: 'Ada' } },
      { ...header(), entryCount: 0 },
      { ...header(), extra: true },
    ]) {
      expect(
        changeLogReceiptReadReplySchema.safeParse({ ...portion(1), receipt })
          .success,
      ).toBe(false)
    }
    for (const row of [
      { ...entry(), entryId: -1 },
      { ...entry(), receiptId: 0 },
      { ...entry(), recordKey: [{}] },
      { ...entry(), mutation: 'replace' },
      { ...entry(), extra: true },
      { ...entry(), changes: [{ ...change(), kind: 'pii' }] },
      { ...entry(), changes: [{ ...change(), oldPresent: 'true' }] },
      { ...entry(), changes: [{ ...change(), newValue: 0 }] },
      { ...entry(), changes: [{ ...change(), extra: true }] },
    ]) {
      expect(
        changeLogEntryReadReplySchema.safeParse({ entry: row }).success,
      ).toBe(false)
    }
    for (const reply of [
      { ...portion(1), nextAfterEntryId: 0 },
      { ...portion(1), entries: [] },
      {
        ...portion(51),
        entries: Array.from({ length: 51 }, (_, i) => entry(i + 1)),
      },
      { ...portion(1), extra: true },
    ])
      expect(changeLogReceiptReadReplySchema.safeParse(reply).success).toBe(
        false,
      )
    expect(
      changeLogEntryReadReplySchema.safeParse({ entry: entry(), extra: true })
        .success,
    ).toBe(false)
  })
})

describe('change-log receipt reader', () => {
  it('reads lazily with exact tracked receipt and bare entry payloads, preserving the page scope', async () => {
    const { reader, source, scopes } = world()
    const page = scopes.page()
    expect(source.sent).toEqual([])
    expect(reader.dialog.get()).toBeNull()
    const done = reader.openReceipt(42)
    expect(reader.dialog.get()).toMatchObject({
      kind: 'receipt',
      receiptId: 42,
      busyInitial: true,
      busyMore: false,
      entries: [],
    })
    expect(source.sent[0]).toEqual({
      action: 'hilos_change_log_receipt_read',
      data: { receiptId: 42, afterEntryId: null },
      requestId: '1',
    })
    await reader.more()
    source.success(0, portion(1))
    await done
    expect(reader.dialog.get()).toMatchObject({
      busyInitial: false,
      header: header(),
      entries: [entry()],
      refusal: null,
    })
    expect(changeLogReceiptPagination(reader.dialog.get()!)).toBeNull()
    const bare = reader.openEntry(18)
    expect(source.sent[1]).toEqual({
      action: 'hilos_change_log_entry_read',
      data: { entryId: 18 },
      requestId: '2',
    })
    source.success(1, { entry: entry(18, { receiptId: null }) })
    await bare
    expect(reader.dialog.get()).toMatchObject({
      kind: 'entry',
      entryId: 18,
      header: null,
      busyInitial: false,
      entries: [entry(18, { receiptId: null })],
    })
    await reader.more()
    reader.close()
    await reader.more()
    await reader.retry()
    expect(source.sent).toHaveLength(2)
    expect(reader.dialog.get()).toBeNull()
    expect(scopes.page()).toBe(page)
  })

  it.each([1, 50])(
    'offers no continuation controls for %i entries',
    async (total) => {
      const { reader, source } = world()
      const done = reader.openReceipt(42)
      source.success(0, portion(total))
      await done
      expect(changeLogReceiptPagination(reader.dialog.get()!)).toBeNull()
      await reader.more()
      expect(source.sent).toHaveLength(1)
    },
  )

  it.each([51, 100, 101])(
    'expands %i entries by cursor, hides to the first portion, and re-expands from it',
    async (total) => {
      const { reader, source } = world()
      const initial = reader.openReceipt(42)
      source.success(0, portion(total))
      await initial
      expect(reader.dialog.get()?.entries).toHaveLength(50)
      expect(changeLogReceiptPagination(reader.dialog.get()!)).toEqual({
        count: `Shown 50 of ${total}`,
        showMore: true,
        showHide: false,
      })
      let after = 50
      let index = 1
      while (after < total) {
        const done = reader.more()
        expect(source.sent[index]?.data).toEqual({
          receiptId: 42,
          afterEntryId: after,
        })
        expect(reader.dialog.get()).toMatchObject({
          busyInitial: false,
          busyMore: true,
          refusal: null,
        })
        source.success(index, portion(total, after))
        await done
        after = Math.min(total, after + 50)
        expect(reader.dialog.get()?.entries).toHaveLength(after)
        expect(reader.dialog.get()?.nextAfterEntryId).toBe(
          after < total ? after : null,
        )
        index += 1
      }
      expect(changeLogReceiptPagination(reader.dialog.get()!)).toEqual({
        count: `Shown ${total} of ${total}`,
        showMore: false,
        showHide: true,
      })
      await reader.more()
      expect(source.sent).toHaveLength(index)
      const savedHeader = reader.dialog.get()?.header
      reader.collapse()
      expect(reader.dialog.get()).toMatchObject({
        entries: portion(total).entries,
        nextAfterEntryId: 50,
        expanded: false,
      })
      expect(reader.dialog.get()?.header).toBe(savedHeader)
      const again = reader.more()
      expect(source.sent[index]?.data).toEqual({
        receiptId: 42,
        afterEntryId: 50,
      })
      source.success(index, portion(total, 50))
      await again
      expect(reader.dialog.get()?.entries).toHaveLength(Math.min(100, total))
    },
  )

  it('prevents double more and deduplicates appended ids without merging changes of one record', async () => {
    const { reader, source } = world()
    const initial = reader.openReceipt(42)
    source.success(0, portion(51))
    await initial
    const done = reader.more()
    await reader.more()
    await reader.retry()
    expect(source.sent).toHaveLength(2)
    source.success(1, {
      receipt: header({ entryCount: 51 }),
      entries: [entry(50), entry(51), entry(51)],
      nextAfterEntryId: null,
    })
    await done
    expect(reader.dialog.get()?.entries.map(({ entryId }) => entryId)).toEqual(
      Array.from({ length: 51 }, (_, i) => i + 1),
    )
    expect(
      reader.dialog
        .get()
        ?.entries.every(
          ({ table, recordKey }) => table === 'bot' && recordKey?.[0] === 9,
        ),
    ).toBe(true)
  })

  it('uses returned cursors and actual visible counts instead of record keys or contiguous ids', async () => {
    const { reader, source } = world()
    const initial = reader.openReceipt(42)
    source.success(0, {
      ...portion(51),
      entries: [entry(120), entry(4)],
      nextAfterEntryId: 4,
    })
    await initial
    expect(changeLogReceiptPagination(reader.dialog.get()!)?.count).toBe(
      'Shown 2 of 51',
    )
    const done = reader.more()
    expect(source.sent[1]?.data).toEqual({ receiptId: 42, afterEntryId: 4 })
    source.success(1, {
      receipt: header({ entryCount: 51 }),
      entries: [entry(90)],
      nextAfterEntryId: null,
    })
    await done
    expect(reader.dialog.get()?.entries.map(({ entryId }) => entryId)).toEqual([
      120, 4, 90,
    ])
    reader.collapse()
    expect(reader.dialog.get()?.entries.map(({ entryId }) => entryId)).toEqual([
      120, 4,
    ])
    expect(reader.dialog.get()?.nextAfterEntryId).toBe(4)
  })

  it.each(['success', 'failure'])(
    'ignores late continuation %s after collapse while a new expansion runs',
    async (outcome) => {
      const { reader, source } = world()
      const initial = reader.openReceipt(42)
      source.success(0, portion(101))
      await initial
      const second = reader.more()
      source.success(1, portion(101, 50))
      await second
      const late = reader.more()
      expect(reader.dialog.get()?.expanded).toBe(true)
      reader.collapse()
      expect(reader.dialog.get()).toMatchObject({
        busyMore: false,
        nextAfterEntryId: 50,
        refusal: null,
        entries: portion(101).entries,
      })
      const newMore = reader.more()
      const pending = reader.dialog.get()
      if (outcome === 'success') source.success(2, portion(101, 100))
      else source.fail(2)
      await late
      expect(reader.dialog.get()).toBe(pending)
      expect(source.sent[3]?.data).toEqual({ receiptId: 42, afterEntryId: 50 })
      source.success(3, portion(101, 50))
      await newMore
      expect(reader.dialog.get()?.entries).toHaveLength(100)
    },
  )

  it.each(['initial', 'continuation'])(
    'ignores success after close during %s, also on reopening the same receipt',
    async (phase) => {
      const { reader, source } = world()
      let late = reader.openReceipt(42)
      let index = 0
      if (phase === 'continuation') {
        source.success(0, portion(101))
        await late
        late = reader.more()
        index = 1
      }
      reader.close()
      expect(reader.dialog.get()).toBeNull()
      const reopened = reader.openReceipt(42)
      const pending = reader.dialog.get()
      expect(pending?.entries).toEqual([])
      source.success(index, portion(101, phase === 'initial' ? 0 : 50))
      await late
      expect(reader.dialog.get()).toBe(pending)
      source.success(index + 1, portion(1))
      await reopened
      expect(reader.dialog.get()?.entries).toHaveLength(1)
    },
  )

  it('ignores late failures after close and initial replies after replacing the address or kind', async () => {
    const { reader, source } = world()
    const first = reader.openReceipt(42)
    const second = reader.openReceipt(77)
    source.success(0, portion(1))
    await first
    expect(reader.dialog.get()).toMatchObject({
      kind: 'receipt',
      receiptId: 77,
      busyInitial: true,
      header: null,
    })
    const bare = reader.openEntry(18)
    source.fail(1)
    await second
    expect(reader.dialog.get()).toMatchObject({
      kind: 'entry',
      entryId: 18,
      refusal: null,
      busyInitial: true,
    })
    reader.close()
    source.fail(2)
    await bare
    expect(reader.dialog.get()).toBeNull()
  })

  it('disposes permanently, clears data and ignores a late reply', async () => {
    const { reader, source } = world()
    const late = reader.openEntry(18)
    reader.dispose()
    source.success(0, { entry: entry(18, { receiptId: null }) })
    await late
    await reader.openReceipt(42)
    await reader.openEntry(18)
    await reader.more()
    await reader.retry()
    reader.collapse()
    expect(reader.dialog.get()).toBeNull()
    expect(source.sent).toHaveLength(1)
  })

  it.each([0, -1, 1.5, NaN, Infinity])(
    'refuses invalid request id %s without dispatch',
    async (id) => {
      const { reader, source } = world()
      await reader.openReceipt(id)
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        refusal: CHANGE_LOG_RECEIPT_COPY.invalidAddress,
      })
      await reader.openEntry(id)
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        refusal: CHANGE_LOG_RECEIPT_COPY.invalidAddress,
      })
      expect(source.sent).toHaveLength(0)
    },
  )

  it.each([
    undefined,
    {},
    { ...portion(1), receipt: header({ receiptId: 99 }) },
    { ...portion(1), entries: [entry(1, { receiptId: 99 })] },
    { ...portion(1), entries: [entry(1, { receiptId: null })] },
    { ...portion(1), nextAfterEntryId: 99 },
    { ...portion(1), entries: [] },
  ])(
    'keeps a malformed or wrongly addressed receipt reply as invalid-reply',
    async (reply) => {
      const { reader, source } = world()
      const done = reader.openReceipt(42)
      source.success(0, reply)
      await done
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        header: null,
        entries: [],
        outcome: 'invalid-reply',
        refusal: CHANGE_LOG_RECEIPT_COPY.invalidReply,
      })
      const retry = reader.retry()
      expect(source.sent[1]?.data).toEqual({
        receiptId: 42,
        afterEntryId: null,
      })
      source.success(1, portion(1))
      await retry
      expect(reader.dialog.get()?.outcome).toBeNull()
    },
  )

  it.each([
    undefined,
    { entry: entry(19, { receiptId: null }) },
    { entry: entry(18) },
    { entry: { ...entry(18, { receiptId: null }), changes: null } },
  ])(
    'refuses malformed, wrongly addressed or receipt-backed bare entries',
    async (reply) => {
      const { reader, source } = world()
      const done = reader.openEntry(18)
      source.success(0, reply)
      await done
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        entries: [],
        outcome: 'invalid-reply',
      })
      const retry = reader.retry()
      expect(source.sent[1]?.data).toEqual({ entryId: 18 })
      source.success(1, { entry: entry(18, { receiptId: null }) })
      await retry
      expect(reader.dialog.get()?.entries).toHaveLength(1)
    },
  )

  it('preserves visible rows and the failed cursor on an invalid continuation, and clears its refusal on Hide', async () => {
    const { reader, source } = world()
    const initial = reader.openReceipt(42)
    source.success(0, portion(101))
    await initial
    const more = reader.more()
    source.success(1, { ...portion(101, 50), nextAfterEntryId: 50 })
    await more
    expect(reader.dialog.get()).toMatchObject({
      entries: portion(101).entries,
      nextAfterEntryId: 50,
      outcome: 'invalid-reply',
    })
    const retry = reader.retry()
    expect(source.sent[2]?.data).toEqual({ receiptId: 42, afterEntryId: 50 })
    source.success(2, portion(101, 50))
    await retry
    const last = reader.more()
    source.fail(3)
    await last
    reader.collapse()
    expect(reader.dialog.get()).toMatchObject({
      entries: portion(101).entries,
      nextAfterEntryId: 50,
      outcome: null,
      refusal: null,
      expanded: false,
    })
  })

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps initial %s visible and retries only on intent',
    async (outcome) => {
      const { reader, source } = world()
      const done = reader.openReceipt(42)
      if (outcome === 'fail') source.fail(0)
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(1000)
      else source.state('disconnected')
      await done
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        busyMore: false,
        outcome,
        header: null,
        entries: [],
      })
      expect(reader.dialog.get()?.refusal).toBeTruthy()
      expect(source.sent).toHaveLength(1)
      source.state('connected')
      const retry = reader.retry()
      expect(source.sent[1]?.data).toEqual({
        receiptId: 42,
        afterEntryId: null,
      })
      source.success(1, portion(51))
      await retry
      expect(reader.dialog.get()).toMatchObject({
        outcome: null,
        refusal: null,
        nextAfterEntryId: 50,
      })
    },
  )

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps the first and appended portions after continuation %s, then retries the same cursor',
    async (outcome) => {
      const { reader, source } = world()
      const initial = reader.openReceipt(42)
      source.success(0, portion(101))
      await initial
      const second = reader.more()
      source.success(1, portion(101, 50))
      await second
      const headerBefore = reader.dialog.get()?.header
      const done = reader.more()
      if (outcome === 'fail') source.fail(2)
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(1000)
      else source.state('reconnecting')
      await done
      expect(reader.dialog.get()).toMatchObject({
        busyInitial: false,
        busyMore: false,
        outcome,
        nextAfterEntryId: 100,
        expanded: true,
      })
      expect(reader.dialog.get()?.entries).toHaveLength(100)
      expect(reader.dialog.get()?.header).toBe(headerBefore)
      expect(source.sent).toHaveLength(3)
      source.state('connected')
      const retry = reader.retry()
      expect(source.sent[3]?.data).toEqual({ receiptId: 42, afterEntryId: 100 })
      source.success(3, portion(101, 100))
      await retry
      expect(reader.dialog.get()).toMatchObject({
        refusal: null,
        outcome: null,
        nextAfterEntryId: null,
      })
      expect(reader.dialog.get()?.entries).toHaveLength(101)
      reader.collapse()
      expect(reader.dialog.get()?.entries).toHaveLength(50)
      expect(reader.dialog.get()?.nextAfterEntryId).toBe(50)
    },
  )

  it('refuses dispatch while disconnected without an automatic resend', async () => {
    const { reader, source } = world()
    source.state('disconnected')
    await reader.openEntry(18)
    expect(reader.dialog.get()).toMatchObject({
      busyInitial: false,
      outcome: 'disconnected',
      refusal: 'Not connected.',
    })
    source.state('connected')
    expect(source.sent).toHaveLength(1)
  })
})

describe('change-log detail copy and projections', () => {
  it('keeps actor and subject numbers beside hidden names, and omits a matching subject', () => {
    const attribution = changeLogReceiptAttribution(
      header({
        actorLabel: HIDDEN_VALUE,
        subjectId: 8,
        subjectLabel: HIDDEN_VALUE,
      }),
    )
    expect(attribution.actor).toEqual({
      id: 7,
      label: HIDDEN_VALUE,
      explanation: CHANGE_LOG_RECEIPT_COPY.hiddenPerson,
    })
    expect(attribution.subject).toEqual({
      id: 8,
      label: HIDDEN_VALUE,
      explanation: CHANGE_LOG_RECEIPT_COPY.hiddenPerson,
    })
    expect(
      changeLogReceiptAttribution(header({ subjectId: 7, subjectLabel: 'Ada' }))
        .subject,
    ).toBeNull()
    expect(changeLogReceiptAttribution(header()).subject).toBeNull()
    expect(CHANGE_LOG_RECEIPT_COPY.impersonation).toBe(
      'Impersonation: at the keyboard',
    )
  })

  it('uses current and deleted person labels, and local time with the real actor and source', () => {
    const receipt = header({
      actorDeleted: true,
      actorLabel: 'Deleted user #7',
      subjectId: 8,
      subjectLabel: 'Deleted user #8',
      subjectDeleted: true,
      channel: 'cron',
      agent: 'hilos_user:7',
      source: 'sweep (0 * * * *)',
      action: 'sweep',
    })
    const attribution = changeLogReceiptAttribution(receipt)
    expect(attribution.actor).toEqual({
      id: 7,
      label: 'Deleted user #7',
      explanation: CHANGE_LOG_RECEIPT_COPY.deletedPerson,
    })
    expect(attribution.subject).toEqual({
      id: 8,
      label: 'Deleted user #8',
      explanation: CHANGE_LOG_RECEIPT_COPY.deletedPerson,
    })
    expect(attribution.when).toBe(
      new Date(receipt.createdAt).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      }),
    )
    expect(attribution.channel).toEqual({ label: 'Cron', icon: 'bi-alarm' })
    expect(attribution.agent).toBe('hilos_user:7')
    expect(attribution.source).toBe('sweep (0 * * * *)')
    expect(attribution.action).toBe('sweep')
    expect(changeLogReceiptAttribution(header()).actor.label).toBe('Ada')
  })

  it('names a guest and system, and uses Migration runner only for migration with a null agent', () => {
    expect(
      changeLogReceiptAttribution(
        header({ actorId: null, actorLabel: HIDDEN_VALUE }),
      ).actor,
    ).toEqual({
      id: null,
      label: 'Guest',
      explanation: CHANGE_LOG_RECEIPT_COPY.guest,
    })
    expect(
      changeLogReceiptAttribution(
        header({ actorId: null, actorLabel: null, channel: 'cli' }),
      ).actor.label,
    ).toBe('System')
    expect(
      changeLogReceiptAttribution(header({ channel: 'migration', agent: null }))
        .agent,
    ).toBe('Migration runner')
    expect(
      changeLogReceiptAttribution(
        header({ channel: 'migration', agent: 'hilos_index' }),
      ).agent,
    ).toBe('hilos_index')
    expect(
      changeLogReceiptAttribution(
        header({ channel: 'web', agent: null, source: null }),
      ),
    ).toMatchObject({ agent: '—', source: '—' })
    expect(
      changeLogReceiptAttribution(
        header({ channel: 'unknown', agent: null, source: null }),
      ),
    ).toMatchObject({ channel: null, agent: '—', source: '—' })
  })

  it('distinguishes absent, NULL, empty and full values, retaining line breaks and literal markup', () => {
    expect(changeLogFieldValue(change({ oldPresent: false }), 'old')).toEqual({
      kind: 'absent',
      text: 'Absent',
    })
    expect(changeLogFieldValue(change({ oldValue: null }), 'old')).toEqual({
      kind: 'null',
      text: 'NULL',
    })
    expect(changeLogFieldValue(change({ newValue: '' }), 'new')).toEqual({
      kind: 'empty',
      text: 'Empty string',
    })
    expect(changeLogFieldValue(change(), 'old')).toEqual({
      kind: 'value',
      text: 'Before\n\n<em>plain text</em>',
    })
    expect(changeLogFieldValue(change(), 'new')).toEqual({
      kind: 'value',
      text: 'After\n  with indentation',
    })
    expect(changeLogFieldValue(change({ bodyOmitted: true }), 'new')).toEqual({
      kind: 'omitted',
      text: 'Value omitted',
    })
    expect(
      changeLogFieldValue(
        change({
          kind: 'fact',
          oldValue: 'never draw',
          newValue: 'never draw',
        }),
        'old',
      ),
    ).toEqual({ kind: 'fact', text: 'Personal — no value' })
    expect(changeLogFieldValue(change({ kind: 'fact' }), 'new')).toEqual({
      kind: 'fact',
      text: 'Personal — no value',
    })
  })

  it('projects creation as a fact, deletion as saved old sides, and updates as both full sides', () => {
    expect(
      changeLogEntryDetails(entry(1, { mutation: 'create', recordKey: null })),
    ).toEqual({
      record: 'bot #?',
      mutation: 'created',
      explanation: CHANGE_LOG_RECEIPT_COPY.created,
      fields: [],
    })
    const deletion = changeLogEntryDetails(
      entry(2, {
        mutation: 'delete',
        changes: [
          change(),
          change({
            field: 'name',
            kind: 'fact',
            oldValue: null,
            newValue: null,
          }),
        ],
      }),
    )
    expect(deletion.explanation).toBe('Snapshot before deletion:')
    expect(
      deletion.fields.map(({ oldValue, newValue }) => ({ oldValue, newValue })),
    ).toEqual([
      {
        oldValue: { kind: 'value', text: 'Before\n\n<em>plain text</em>' },
        newValue: null,
      },
      {
        oldValue: { kind: 'fact', text: 'Personal — no value' },
        newValue: null,
      },
    ])
    const update = changeLogEntryDetails(
      entry(3, {
        recordKey: [9, 'en'],
        changes: [change({ kind: 'inline', oldValue: null, newValue: '' })],
      }),
    )
    expect(update.record).toBe('bot #9, en')
    expect(update.explanation).toBeNull()
    expect(update.fields[0]).toEqual({
      field: 'prompt',
      kind: 'inline',
      oldValue: { kind: 'null', text: 'NULL' },
      newValue: { kind: 'empty', text: 'Empty string' },
    })
    expect(HILOS_CHANGE_LOG_RECEIPT_READ).toBe('hilos_change_log_receipt_read')
    expect(HILOS_CHANGE_LOG_ENTRY_READ).toBe('hilos_change_log_entry_read')
  })

  it('titles the dialog by its receipt or bare entry, and leaves a closed one blank', () => {
    const reading = {
      busyInitial: true,
      busyMore: false,
      refusal: null,
      outcome: null,
      header: null,
      entries: [],
      nextAfterEntryId: null,
      expanded: false,
    }
    const receipt: HilosChangeLogReceiptDialog = {
      kind: 'receipt',
      receiptId: 7,
      ...reading,
    }
    const bare: HilosChangeLogReceiptDialog = {
      kind: 'entry',
      entryId: 11,
      ...reading,
    }
    expect(formatChangeLogReceiptTitle(receipt)).toBe('Receipt #7')
    expect(formatChangeLogReceiptTitle(bare)).toBe(
      'Change #11 past the application',
    )
    expect(formatChangeLogReceiptTitle(null)).toBe('')
  })
})
