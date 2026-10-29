import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { statusOverviewSchema } from '../../../src/admin/status/hilosStatus.js'
import {
  statusWorkSchema,
  statusWorksDataSchema,
  resolveHilosStatusWorkRow,
} from '../../../src/admin/status/hilosStatusWorks.js'
import {
  createHilosStatusWorksState,
  createHilosStatusWorksTable,
  createHilosStatusWorkCreate,
  createHilosStatusWorkEdit,
  createHilosStatusWorkCancel,
  HILOS_STATUS_WORK_ACTION_COPY,
  localDateTimeValue,
  type HilosStatusWorkForm,
  type HilosStatusWorkRow,
} from '../../../src/admin/status/index.js'
import { HILOS_VIEW_MODE_COPY } from '../../../src/admin/viewMode.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { bindAdminAccess } from '../../../src/session/adminAccess.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'
import { Socket } from '../../status/fixtures.js'
import { harness, overview } from './fixtures.js'
import { normalizeTableRow } from '../../../src/state/normalizer.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'

const work = {
  id: 'opaque-public-id',
  title: 'Upgrade',
  description: null,
  componentKeys: ['service'],
  startsAt: 10000,
  endsAt: 20000,
}

const page = 'hilos_status_works'
const tableKey = 'hilosStatusWorks'
const openingSort = [{ field: 'startsAt', direction: 'asc' as const }]
const announcement: HilosStatusWorkRow = {
  id: 7,
  publicId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  title: 'Database upgrade',
  description: null,
  componentKeys: ['service'],
  startsAt: Date.UTC(2031, 0, 2, 3, 4, 5),
  endsAt: Date.UTC(2031, 0, 2, 4, 5, 6),
  version: 1,
  canceledAt: null,
  consumedAt: null,
  expiredAt: null,
  lastMailQueuedAt: null,
}
const row = (value = announcement) => ({
  rowKey: String(value.id),
  slots: { work: value },
})
const catalog = {
  components: [
    { componentKey: 'service', name: 'Service' },
    { componentKey: 'database', name: 'Database' },
    { componentKey: 'queue', name: 'Queue' },
  ],
  upcomingCount: 1,
  nextWork: {
    ...work,
    id: announcement.publicId,
    startsAt: announcement.startsAt,
    endsAt: announcement.endsAt,
  },
}
const filledForm: HilosStatusWorkForm = {
  title: '  Database upgrade  ',
  description: '   ',
  componentKeys: ['service'],
  startsAt: localDateTimeValue(announcement.startsAt, 'UTC'),
  endsAt: localDateTimeValue(announcement.endsAt, 'UTC'),
}

function setup(zone = 'UTC') {
  const h = harness(HilosPages.STATUS_WORKS)
  h.context.connection.forgetPageFrames = vi.fn()
  const pages = bindPageScope(h.context.connection, h.scopes)
  pages.subscribe(page)
  const releaseZone = bindTimeZoneState(h.context.connection)
  const setZone = (timeZone: string) =>
    h.emit('projectSignal', {
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone } } },
    })
  setZone(zone)
  const state = createHilosStatusWorksState(h.context)
  const table = createHilosStatusWorksTable(h.context)
  const create = createHilosStatusWorkCreate(h.context, state)
  const edit = createHilosStatusWorkEdit(h.context, table, state)
  const cancel = createHilosStatusWorkCancel(h.context, table)
  for (const controller of [state, table, create, edit, cancel]) {
    controller.start()
    controller.start()
  }
  return {
    ...h,
    state,
    table,
    create,
    edit,
    cancel,
    setZone,
    answer(
      success: boolean,
      data: Record<string, unknown> = {},
      index = h.sent.length - 1,
    ) {
      const sent = h.sent[index]!
      h.emit(success ? 'actionSuccess' : 'actionError', {
        action: sent.action,
        requestId: sent.requestId,
        ...data,
      })
    },
    window(rows = [row()], data: unknown = catalog) {
      h.emit('projectSignal', {
        type: 'page_response',
        data: {
          page,
          payload: {
            data: { statusWorks: data },
            windows: {
              [tableKey]: {
                rows,
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                limit: 25,
                sort: openingSort,
              },
            },
          },
        },
      })
    },
    data(value: unknown) {
      h.emit('projectSignal', {
        type: 'page_response',
        data: { page, payload: { data: { statusWorks: value } } },
      })
    },
    delta(data: Record<string, unknown>) {
      h.emit('tableViewportDelta', { data: { page, tableKey, ...data } })
    },
    update(patch: Partial<HilosStatusWorkRow>) {
      const live =
        table.controller.focusedRow.get() ??
        table.controller.rows.get()[0]?.row ??
        announcement
      h.emit('tableViewportDelta', {
        data: {
          page,
          tableKey,
          kind: 'row_updated',
          rowKey: '7',
          row: row({ ...live, ...patch }),
        },
      })
    },
    dispose() {
      create.dispose()
      edit.dispose()
      cancel.dispose()
      state.dispose()
      table.dispose()
      releaseZone()
      h.releaseAccess()
    },
  }
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('scheduled maintenance wire readers', () => {
  it('reads exact Overview summaries and all announcement knowledge states', () => {
    for (const announcement of [
      null,
      { known: false, work: null },
      { known: true, work: null },
      { known: true, work },
    ]) {
      const wire = overview({
        scheduledWorks: { count: 1, nextWork: work },
        maintenance: {
          phase: 'active',
          operation: 'manual_maintenance',
          startedAt: 12000,
          announcement,
        },
      })
      const parsed = statusOverviewSchema.parse(wire)
      expect(parsed.scheduledWorks).toEqual({ count: 1, nextWork: work })
      expect(parsed.maintenance?.announcement).toEqual(announcement)
      expect(parsed.incidents).toEqual(wire.incidents)
    }
    expect(statusOverviewSchema.parse(overview()).scheduledWorks).toBeNull()
    expect(
      statusOverviewSchema.parse(
        overview({ scheduledWorks: { count: 0, nextWork: null } }),
      ).scheduledWorks,
    ).toEqual({ count: 0, nextWork: null })
  })

  it('keeps required nullable fields strict and refuses private or malformed work data', () => {
    const wire = overview()
    const missing = { ...wire } as Record<string, unknown>
    delete missing.scheduledWorks
    expect(statusOverviewSchema.safeParse(missing).success).toBe(false)
    for (const scheduledWorks of [
      { count: -1, nextWork: null },
      { count: 0 },
      { count: 1, nextWork: { ...work, actorId: 7 } },
      { count: 1, nextWork: { ...work, endsAt: 10000 } },
    ]) {
      expect(
        statusOverviewSchema.safeParse({ ...wire, scheduledWorks }).success,
      ).toBe(false)
    }
    expect(
      statusOverviewSchema.safeParse({
        ...wire,
        maintenance: { phase: 'active', operation: null, startedAt: null },
      }).success,
    ).toBe(false)
  })

  it('resolves only a normalized Work slot with its persisted row identity', () => {
    const h = harness(HilosPages.STATUS)
    const value = {
      ...work,
      id: 7,
      publicId: work.id,
      version: 1,
      canceledAt: null,
      consumedAt: null,
      expiredAt: null,
      lastMailQueuedAt: null,
    }
    try {
      const row = normalizeTableRow(h.scope, {
        rowKey: '7',
        slots: { work: value },
      })
      expect(resolveHilosStatusWorkRow(row, h.scopes)).toEqual(value)
      for (const wire of [
        { rowKey: '8', slots: { work: value } },
        { rowKey: '7', slots: { user: value } },
        { rowKey: '7', slots: { work: { ...value, audience: 9 } } },
      ]) {
        expect(() =>
          resolveHilosStatusWorkRow(normalizeTableRow(h.scope, wire), h.scopes),
        ).toThrow()
      }
    } finally {
      h.releaseAccess()
    }
  })

  it('reads the form catalog and exact admin slot independently of the viewport', () => {
    expect(statusWorksDataSchema.parse(null)).toBeNull()
    expect(
      statusWorksDataSchema.parse({
        components: [{ componentKey: 'service', name: 'Service' }],
        upcomingCount: 1,
        nextWork: work,
      })?.nextWork,
    ).toEqual(work)
    const row = {
      ...work,
      id: 7,
      publicId: work.id,
      version: 2,
      canceledAt: null,
      consumedAt: null,
      expiredAt: null,
      lastMailQueuedAt: 9000,
    }
    expect(statusWorkSchema.parse(row)).toEqual(row)
    expect(
      statusWorkSchema.safeParse({ ...row, mailAudience: 9 }).success,
    ).toBe(false)
  })
})

describe('Works page state and viewport', () => {
  it('replays the opening answer at mount and disposes observed rows before the next scope opens', async () => {
    const socket = new Socket()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket,
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    const scopes = new ScopeManager()
    scopes.session.data.set('currentUser', { type: 'users', id: '1' })
    scopes.session.entities.upsert({ type: 'users', id: '1' }, { admin: true })
    const releaseAccess = bindAdminAccess(scopes)
    const context = {
      connection,
      scopes,
      actions: new ActionLifecycle(connection),
    }
    const pages = bindPageScope(connection, scopes)
    const releaseZone = bindTimeZoneState(connection)
    connection.connect()
    socket.emit('open')
    pages.releaseOnSession()
    pages.subscribe(page)
    socket.push('page_response', {
      page,
      payload: {
        data: { statusWorks: catalog },
        windows: {
          [tableKey]: {
            rows: [row()],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: openingSort,
          },
        },
      },
    })
    const table = createHilosStatusWorksTable(context)
    const state = createHilosStatusWorksState(context)
    const cancel = createHilosStatusWorkCancel(context, table)
    const offRows = subscribeSignal(table.controller.rows, vi.fn())
    try {
      table.start()
      state.start()
      cancel.start()
      expect(table.controller.rows.get()[0]?.row).toEqual(announcement)
      expect(state.data.get()).toEqual(catalog)
      expect(connection.tableWindowDescriptors()).toHaveProperty(tableKey)
      expect(
        socket.send.mock.calls
          .map(([raw]) => JSON.parse(raw))
          .filter((frame) => frame.type === 'action'),
      ).toEqual([])
      cancel.open('7')
      const pending = cancel.submit()
      const requestId = cancel.requestId.get()
      pages.subscribe(HilosPages.STATUS)
      expect(connection.tableWindowDescriptors()).toEqual({})
      expect(table.controller.rows.get()).toEqual([])
      expect(state.data.get()).toBeNull()
      expect(cancel.opened.get()).toBe(false)
      socket.push('page_response', {
        page: HilosPages.STATUS,
        payload: { data: { heading: 'Overview' } },
      })
      socket.emit('message', {
        data: JSON.stringify({
          type: 'action_success',
          data: { action: 'hilos_status_work_cancel' },
          requestId,
          outcome: 'success',
        }),
      })
      expect(await pending).toBe(false)
      expect(cancel.success.get()).toBeNull()
      table.start()
      state.start()
      cancel.start()
      expect(connection.tableWindowDescriptors()).toEqual({})
      expect(scopes.page()?.key).toBe(HilosPages.STATUS)
    } finally {
      offRows()
      cancel.dispose()
      state.dispose()
      table.dispose()
      releaseZone()
      releaseAccess()
      connection.close()
    }
  })

  it('takes the complete first answer without companion requests, and keeps data independent of Apply', () => {
    const h = setup()
    try {
      expect(h.state.data.get()).toBeNull()
      expect(h.sent).toEqual([])
      expect(h.viewports).toEqual([])
      expect(h.registered.size).toBe(1)
      h.window([row(), row({ ...announcement, id: 8 })])
      expect(h.state.data.get()).toEqual(catalog)
      expect(h.table.controller.rows.get()[0]?.row).toEqual(announcement)
      expect(h.table.controller.descriptor()?.sort).toEqual(openingSort)
      h.delta({
        kind: 'row_moved',
        rowKey: '7',
        row: row({ ...announcement, title: 'Moved' }),
        position: 1,
      })
      h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
      expect(h.table.controller.rows.get()[0]?.row?.title).toBe('Moved')
      expect(h.table.controller.rows.get()[0]?.rowKey).toBe('7')
      expect(h.table.controller.pendingCount.get()).toBe(2)
      const descriptor = h.table.controller.descriptor()
      const requests = [...h.viewports]
      h.data({ ...catalog, upcomingCount: 0, nextWork: null })
      expect(h.state.data.get()?.upcomingCount).toBe(0)
      expect(h.state.data.get()?.nextWork).toBeNull()
      expect(h.table.controller.pendingCount.get()).toBe(2)
      expect(h.table.controller.descriptor()).toEqual(descriptor)
      expect(h.viewports).toEqual(requests)
      expect(h.sent).toEqual([])
      h.table.controller.apply()
      expect(h.table.controller.pendingCount.get()).toBe(0)
    } finally {
      h.dispose()
    }
  })

  it('keeps unknown, unavailable and recovered zero separate, without synthesizing a catalog', () => {
    const h = setup()
    try {
      h.window([], null)
      expect(h.state.data.get()).toBeNull()
      for (const value of [undefined, {}, { ...catalog, upcomingCount: -1 }]) {
        h.data(value)
        expect(h.state.data.get()).toBeNull()
      }
      h.data({ ...catalog, upcomingCount: 0, nextWork: null })
      expect(h.state.data.get()?.upcomingCount).toBe(0)
      h.data(null)
      expect(h.state.data.get()).toBeNull()
      h.data(catalog)
      expect(h.state.data.get()?.nextWork?.id).toBe(announcement.publicId)
      expect(h.viewports).toEqual([])
    } finally {
      h.dispose()
    }
  })

  it('offers the component facet, public search and exactly three indexed sorts to a viewer too', () => {
    const h = setup()
    try {
      h.viewer()
      h.window()
      expect(
        h.table.controller.frame.columns
          .get()
          .filter((column) => column.sortable)
          .map((column) => column.key),
      ).toEqual(['startsAt', 'endsAt', 'title'])
      const filter = h.table.controller.frame.filters.get()[0]!.filter
      expect(filter.kind === 'select' && filter.options()).toEqual([
        { value: 'service', label: 'Service' },
        { value: 'database', label: 'Database' },
        { value: 'queue', label: 'Queue' },
      ])
      h.table.controller.setFilter('componentKey', 'database')
      h.table.controller.setSearch('upgrade')
      expect(h.table.controller.descriptor()?.filter).toEqual({
        componentKey: 'database',
        search: 'upgrade',
      })
      for (const field of ['startsAt', 'endsAt', 'title'])
        for (const direction of ['asc', 'desc'] as const) {
          h.table.controller.setOrder([{ field, direction }])
          expect(h.viewports.at(-1)).toEqual([
            page,
            tableKey,
            expect.objectContaining({ sort: [{ field, direction }] }),
          ])
        }
      expect(h.facets).toContainEqual([
        page,
        tableKey,
        { componentKey: ['service', 'database', 'queue'] },
      ])
    } finally {
      h.dispose()
    }
  })
})

describe('Works create, edit and cancel', () => {
  it('refuses missing inputs, unknown or duplicate component keys and reversed times without changing the form', async () => {
    const h = setup()
    try {
      h.window()
      h.create.open()
      for (const patch of [
        {},
        { ...filledForm, title: ' ' },
        { ...filledForm, componentKeys: [] },
        { ...filledForm, componentKeys: ['unknown'] },
        { ...filledForm, componentKeys: ['service', 'service'] },
        { ...filledForm, startsAt: filledForm.endsAt },
        { ...filledForm, endsAt: '2031-01-02T02:00:00' },
        { ...filledForm, title: 'a'.repeat(201) },
        { ...filledForm, description: 'a'.repeat(2001) },
      ]) {
        h.create.close()
        h.create.open()
        h.create.patchForm(patch)
        const form = h.create.form.get()
        expect(h.create.canSubmit.get()).toBe(false)
        expect(await h.create.submit()).toBe(false)
        expect(h.create.form.get()).toBe(form)
      }
      expect(h.sent).toEqual([])
      h.create.patchForm({
        ...filledForm,
        title: '🛠'.repeat(200),
        description: '🛠'.repeat(2000),
      })
      expect(h.create.canSubmit.get()).toBe(true)
      h.create.patchForm({ title: '🛠'.repeat(201) })
      expect(h.create.canSubmit.get()).toBe(false)
    } finally {
      h.dispose()
    }
  })

  it('schedules a real entity with the exact DTO, busy guards and only a correlated ack closing it', async () => {
    const h = setup()
    try {
      h.window()
      expect(h.create.open()).toBe(true)
      expect(h.create.dirty.get()).toBe(false)
      h.create.patchForm(filledForm)
      const pending = h.create.submit()
      expect(h.sent).toEqual([
        {
          action: 'hilos_status_work_schedule',
          requestId: '1',
          data: {
            title: announcement.title,
            description: null,
            componentKeys: ['service'],
            startsAt: announcement.startsAt,
            endsAt: announcement.endsAt,
          },
        },
      ])
      expect(h.create.busy.get()).toBe(true)
      expect(h.create.loading.get()).toBe(false)
      h.create.close()
      expect(h.create.open()).toBe(false)
      h.create.patchForm({ title: 'double send' })
      expect(await h.create.submit()).toBe(false)
      expect(h.create.form.get().title).toBe(filledForm.title)
      vi.advanceTimersByTime(30)
      expect(h.create.loading.get()).toBe(true)
      h.emit('actionSuccess', {
        action: 'hilos_status_work_schedule',
        requestId: 'wrong',
      })
      expect(h.create.opened.get()).toBe(true)
      h.answer(true)
      expect(await pending).toBe(true)
      expect(h.create.opened.get()).toBe(false)
      expect(h.create.success.get()).toBe(
        HILOS_STATUS_WORK_ACTION_COPY.scheduled,
      )
      expect(h.table.controller.rows.get()).toHaveLength(1)
      expect(h.table.controller.rows.get()[0]?.row?.lastMailQueuedAt).toBeNull()
    } finally {
      h.dispose()
    }
  })

  it('merges the whole baseline, compares lists by content and sends the live version after Keep mine', async () => {
    const h = setup()
    try {
      h.window()
      h.edit.open('7')
      expect(h.focuses.at(-1)).toEqual([page, tableKey, '7'])
      h.edit.patchForm({
        title: 'My title',
        componentKeys: ['database', 'service'],
        endsAt: '2031-01-02T06:00:00',
      })
      h.update({
        description: 'Their description',
        version: 2,
        lastMailQueuedAt: 100,
      })
      expect(h.edit.form.get().description).toBe('Their description')
      expect(h.edit.form.get().title).toBe('My title')
      expect(h.edit.state.get().conflict).toBe(false)
      h.update({
        componentKeys: ['service', 'database'],
        title: 'Their title',
        startsAt: Date.UTC(2031, 0, 2, 3, 5, 7),
        version: 3,
      })
      expect(h.edit.state.get().fields.componentKeys.conflict).toBe(false)
      expect(h.edit.form.get().startsAt).toBe('2031-01-02T03:05:07')
      expect(h.edit.state.get().fields.title.conflict).toBe(true)
      expect(h.edit.canSave.get()).toBe(false)
      expect(await h.edit.submit()).toBe(false)
      h.edit.keepMine()
      expect(h.edit.canSave.get()).toBe(true)
      const pending = h.edit.submit()
      expect(h.sent).toEqual([
        {
          action: 'hilos_status_work_change',
          requestId: '1',
          data: {
            workId: 7,
            publicId: announcement.publicId,
            expectedVersion: 3,
            title: 'My title',
            description: 'Their description',
            componentKeys: ['database', 'service'],
            startsAt: Date.UTC(2031, 0, 2, 3, 5, 7),
            endsAt: Date.UTC(2031, 0, 2, 6),
          },
        },
      ])
      h.edit.close()
      h.edit.patchForm({ title: 'busy change' })
      expect(h.edit.opened.get()).toBe(true)
      expect(h.edit.form.get().title).toBe('My title')
      h.answer(true)
      expect(await pending).toBe(true)
      expect(h.edit.opened.get()).toBe(false)
      expect(h.focuses.at(-1)).toEqual([page, tableKey, ''])
    } finally {
      h.dispose()
    }
  })

  it('uses Take theirs for text, components and absolute times, advances the snapshot and closes no-op without sending', async () => {
    const h = setup()
    try {
      h.window()
      h.edit.open('7')
      h.edit.patchForm({
        title: 'Mine',
        componentKeys: ['database'],
        startsAt: '2031-01-02T02:00:00',
        endsAt: '2031-01-02T09:00:00',
      })
      h.update({
        title: 'Theirs',
        componentKeys: ['queue'],
        startsAt: Date.UTC(2031, 0, 2, 1),
        endsAt: Date.UTC(2031, 0, 2, 8),
        version: 2,
      })
      for (const key of [
        'title',
        'componentKeys',
        'startsAt',
        'endsAt',
      ] as const)
        expect(h.edit.state.get().fields[key].conflict, key).toBe(true)
      h.edit.takeTheirs()
      expect(h.edit.form.get()).toEqual({
        title: 'Theirs',
        description: '',
        componentKeys: ['queue'],
        startsAt: '2031-01-02T01:00:00',
        endsAt: '2031-01-02T08:00:00',
      })
      expect(h.edit.dirty.get()).toBe(false)
      h.update({ lastMailQueuedAt: 9000 })
      expect(h.edit.state.get().conflict).toBe(false)
      expect(h.edit.dirty.get()).toBe(false)
      expect(await h.edit.submit()).toBe(true)
      expect(h.sent).toEqual([])
      expect(h.edit.opened.get()).toBe(false)
      h.edit.open('7')
      expect(h.edit.target.get()?.version).toBe(2)
      expect(h.edit.target.get()?.lastMailQueuedAt).toBe(9000)
    } finally {
      h.dispose()
    }
  })

  it('keeps draft/baseline unchanged on mail-only metadata, and preserves the actual instant of an unedited second', async () => {
    const h = setup()
    try {
      h.window([
        row({
          ...announcement,
          startsAt: announcement.startsAt + 123,
          endsAt: announcement.endsAt + 456,
        }),
      ])
      h.edit.open('7')
      expect(h.edit.dirty.get()).toBe(false)
      expect(Object.keys(h.edit.checkpoint()!.baseline.values)).toEqual([
        'title',
        'description',
        'componentKeys',
        'startsAt',
        'endsAt',
      ])
      h.edit.patchForm({ title: 'Draft' })
      const snapshot = h.edit.checkpoint()!.baseline
      h.update({ lastMailQueuedAt: 999 })
      expect(h.edit.state.get().conflict).toBe(false)
      expect(h.edit.noticeText.get()).toBe('')
      expect(h.edit.checkpoint()!.baseline).toEqual(snapshot)
      expect(h.edit.form.get().title).toBe('Draft')
      expect(h.edit.target.get()?.version).toBe(1)
      const pending = h.edit.submit()
      expect(h.sent[0]?.data).toMatchObject({
        expectedVersion: 1,
        startsAt: announcement.startsAt + 123,
        endsAt: announcement.endsAt + 456,
      })
      expect(await h.edit.submit()).toBe(false)
      h.answer(true)
      expect(await pending).toBe(true)
    } finally {
      h.dispose()
    }
  })

  it('keeps focus and the draft through Apply and an unrelated viewport, then cancels using the live caption/version', async () => {
    const h = setup()
    try {
      h.window([
        row(),
        row({
          ...announcement,
          id: 8,
          publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        }),
      ])
      h.edit.open('7')
      h.edit.patchForm({ title: 'Draft' })
      h.delta({
        kind: 'row_moved',
        rowKey: '7',
        row: row({ ...announcement, description: 'Live', version: 2 }),
        position: 1,
      })
      h.table.controller.apply()
      h.window([row({ ...announcement, id: 8 })])
      expect(h.edit.form.get().title).toBe('Draft')
      expect(h.edit.form.get().description).toBe('Live')
      expect(h.edit.canSave.get()).toBe(true)
      expect(h.focuses.at(-1)).toEqual([page, tableKey, '7'])
      h.edit.close()
      h.window()
      h.cancel.open('7')
      h.update({ title: 'Live caption', version: 4 })
      expect(h.cancel.target.get()?.title).toBe('Live caption')
      const pending = h.cancel.submit()
      expect(h.sent[0]).toEqual({
        action: 'hilos_status_work_cancel',
        requestId: '1',
        data: {
          workId: 7,
          publicId: announcement.publicId,
          expectedVersion: 4,
        },
      })
      h.cancel.close()
      expect(await h.cancel.submit()).toBe(false)
      expect(h.cancel.opened.get()).toBe(true)
      h.delta({
        kind: 'row_removed',
        rowKey: '7',
        reason: 'deleted',
        own: true,
      })
      h.answer(true)
      expect(await pending).toBe(true)
      expect(h.cancel.opened.get()).toBe(false)
      expect(h.table.controller.rows.get()[0]?.placeholder).toBe(true)
    } finally {
      h.dispose()
    }
  })

  it.each(['canceledAt', 'consumedAt', 'expiredAt', 'deleted', 'replacement'])(
    'blocks an edit and cancel on %s while preserving the draft',
    async (kind) => {
      const h = setup()
      try {
        h.window()
        h.edit.open('7')
        h.edit.patchForm({ title: 'Copy this' })
        if (kind === 'deleted')
          h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
        else
          h.update(
            kind === 'replacement'
              ? { publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', version: 1 }
              : { [kind]: 100, version: 2 },
          )
        expect(h.edit.state.get().gone).toBe(true)
        expect(h.edit.noticeText.get()).toBe(HILOS_STATUS_WORK_ACTION_COPY.gone)
        expect(h.edit.form.get().title).toBe('Copy this')
        expect(await h.edit.submit()).toBe(false)
        expect(h.edit.opened.get()).toBe(true)
        h.edit.close()
        h.window()
        h.cancel.open('7')
        if (kind === 'deleted')
          h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
        else
          h.update(
            kind === 'replacement'
              ? { publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' }
              : { [kind]: 100 },
          )
        expect(await h.cancel.submit()).toBe(false)
        expect(h.cancel.blockedReason.get()).toBe(
          HILOS_STATUS_WORK_ACTION_COPY.gone,
        )
        expect(h.sent).toEqual([])
      } finally {
        h.dispose()
      }
    },
  )

  it.each(['create', 'edit', 'cancel'] as const)(
    'preserves %s refusal details, timeout and disconnect without accepting a late ack',
    async (operation) => {
      for (const outcome of ['refusal', 'timeout', 'disconnect']) {
        const h = setup()
        try {
          h.window()
          const controller = h[operation]
          if (operation === 'create') {
            h.create.open()
            h.create.patchForm(filledForm)
          } else if (operation === 'edit') {
            h.edit.open('7')
            h.edit.patchForm({ title: 'Draft' })
          } else h.cancel.open('7')
          const pending = controller.submit()
          if (outcome === 'refusal')
            h.answer(false, {
              reason: 'This announcement changed. Review it before saving.',
              errorType: 'StaleWork',
              errorDetail: 'Version mismatch',
              errorCode: 'stale',
            })
          else if (outcome === 'timeout') vi.advanceTimersByTime(1000)
          else h.connect('disconnected')
          expect(await pending).toBe(false)
          expect(controller.opened.get()).toBe(true)
          expect(controller.busy.get()).toBe(false)
          expect(controller.unconfirmed.get()).toBe(outcome !== 'refusal')
          expect(controller.error.get()).toBe(
            outcome === 'refusal'
              ? 'This announcement changed. Review it before saving.'
              : HILOS_STATUS_WORK_ACTION_COPY.unconfirmed,
          )
          if (outcome === 'refusal')
            expect(controller.failure.get()?.errorDetail).toBe(
              'Version mismatch',
            )
          h.answer(true)
          await Promise.resolve()
          expect(controller.opened.get()).toBe(true)
          expect(controller.success.get()).toBeNull()
        } finally {
          h.dispose()
        }
      }
    },
  )

  it('blocks view-mode writes, busy edits and unavailable sources with the shared explanation', async () => {
    const h = setup()
    try {
      h.window()
      h.create.open()
      h.create.patchForm(filledForm)
      h.edit.open('7')
      h.edit.patchForm({ title: 'Draft' })
      h.cancel.open('7')
      h.viewer()
      for (const controller of [h.create, h.edit, h.cancel]) {
        expect(controller.blockedReason.get()).toBe(
          HILOS_VIEW_MODE_COPY.explanation,
        )
        expect(await controller.submit()).toBe(false)
      }
      expect(h.sent).toEqual([])
    } finally {
      h.dispose()
    }
    const other = setup()
    try {
      other.window()
      other.create.open()
      other.create.patchForm(filledForm)
      other.data(null)
      expect(other.create.canSubmit.get()).toBe(false)
      other.data(catalog)
      other.edit.open('7')
      other.edit.patchForm({ title: 'Draft' })
      other.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      expect(other.edit.canSave.get()).toBe(false)
      other.delta({ kind: 'row_stale', rowKey: '7', staleSources: [] })
      expect(other.edit.canSave.get()).toBe(true)
      other.emit('tableViewportFrozen', { data: { page, tableKey, since: 1 } })
      expect(other.edit.canSave.get()).toBe(false)
      expect(other.edit.form.get().title).toBe('Draft')
    } finally {
      other.dispose()
    }
  })

  it.each(['7', '8'])(
    'does not let an old dialog release another dialog focus on %s or consume its late success',
    async (key) => {
      const h = setup()
      try {
        h.window([row(), row({ ...announcement, id: 8 })])
        h.cancel.open('7')
        const pending = h.cancel.submit()
        h.edit.open(key)
        h.edit.patchForm({ title: 'Another draft' })
        h.answer(true)
        expect(await pending).toBe(false)
        expect(h.cancel.success.get()).toBeNull()
        h.cancel.close()
        expect(h.table.focusKey.get()).toBe(key)
        expect(h.edit.form.get().title).toBe('Another draft')
        expect(h.edit.canSave.get()).toBe(true)
      } finally {
        h.dispose()
      }
    },
  )

  it.each(['create', 'edit', 'cancel'] as const)(
    'disposes %s on scope replacement before an old completion can touch the new page',
    async (operation) => {
      const h = setup()
      try {
        h.window()
        if (operation === 'create') {
          h.create.open()
          h.create.patchForm(filledForm)
        } else if (operation === 'edit') {
          h.edit.open('7')
          h.edit.patchForm({ title: 'Draft' })
        } else h.cancel.open('7')
        const pending = h[operation].submit()
        h.scopes.openPage(page)
        h.answer(true)
        expect(await pending).toBe(false)
        expect(h[operation].opened.get()).toBe(false)
        expect(h[operation].success.get()).toBeNull()
        expect(h.state.data.get()).toBeNull()
        expect(h.table.controller.rows.get()).toEqual([])
        expect(h.registered.size).toBe(0)
        for (const controller of [h.create, h.edit, h.cancel, h.table, h.state])
          controller.start()
        expect(h.registered.size).toBe(0)
        expect(h.edit.open('7')).toBe(false)
        expect(h.create.open()).toBe(false)
      } finally {
        h.dispose()
        h.dispose()
      }
    },
  )

  it('captures the reader zone per opening, preserves seconds and refuses ambiguous local times', async () => {
    const h = setup('America/New_York')
    try {
      h.window()
      h.edit.open('7')
      expect(h.edit.timeZone.get()).toBe('America/New_York')
      expect(h.edit.form.get().startsAt).toBe('2031-01-01T22:04:05')
      h.edit.patchForm({ title: 'Draft' })
      h.setZone('Asia/Tokyo')
      expect(h.edit.timeZone.get()).toBe('America/New_York')
      const pending = h.edit.submit()
      expect(h.sent[0]?.data).toMatchObject({
        startsAt: announcement.startsAt,
        endsAt: announcement.endsAt,
      })
      h.answer(true)
      expect(await pending).toBe(true)
      h.create.open()
      expect(h.create.timeZone.get()).toBe('Asia/Tokyo')
      h.create.close()
      h.setZone('America/New_York')
      h.create.open()
      h.create.patchForm({
        ...filledForm,
        startsAt: '2031-11-02T01:30:00',
        endsAt: '2031-11-02T04:00:00',
      })
      expect(h.create.canSubmit.get()).toBe(false)
      expect(await h.create.submit()).toBe(false)
      h.create.patchForm({
        startsAt: '2031-03-09T02:30:00',
        endsAt: '2031-03-09T04:00:00',
      })
      expect(h.create.canSubmit.get()).toBe(false)
      expect(h.sent).toHaveLength(1)
    } finally {
      h.dispose()
    }
  })
})
