import { describe, expect, it } from 'vitest'
import { type TableViewportDescriptor } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'

function makeController(pageSize = 10) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: (descriptor) => sent.push(descriptor),
    pageSize,
  })

  return { controller, sent }
}

describe('TableViewportController', () => {
  it('start sends the initial descriptor', () => {
    const { controller, sent } = makeController()
    controller.start()
    expect(sent).toEqual([{ filter: {}, sort: null, offset: 0, limit: 10 }])
  })

  it('setSearch sets the search filter, resets to page 0, and resends', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow([], 50)
    controller.setPage(2)
    controller.setSearch('theme')

    expect(controller.search.get()).toBe('theme')
    expect(sent.at(-1)).toEqual({
      filter: { search: 'theme' },
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('an empty search drops the filter', () => {
    const { controller, sent } = makeController()
    controller.setSearch('theme')
    controller.setSearch('   ')
    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('setFilter sets a domain filter entry, resets to page 0, and resends', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow([], 50)
    controller.setPage(2)
    controller.setFilter('status', 'failed')

    expect(sent.at(-1)).toEqual({
      filter: { status: 'failed' },
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('setFilter keeps other filter entries and the search filter', () => {
    const { controller, sent } = makeController()
    controller.setSearch('mail')
    controller.setFilter('status', 'failed')
    controller.setFilter('channel', 'sms')

    expect(sent.at(-1)).toEqual({
      filter: { search: 'mail', status: 'failed', channel: 'sms' },
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('setFilter with an empty value drops just that entry', () => {
    const { controller, sent } = makeController()
    controller.setFilter('status', 'failed')
    controller.setFilter('channel', 'sms')
    controller.setFilter('status', '')

    expect(sent.at(-1)).toEqual({
      filter: { channel: 'sms' },
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('setSort toggles the direction on the same field', () => {
    const { controller, sent } = makeController()
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: { field: 'key', direction: 'asc' },
    })
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: { field: 'key', direction: 'desc' },
    })
  })

  it('setPage sends the offset for the page, clamped to the page count', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow([], 50) // 5 pages of 10
    controller.setPage(3)
    expect(sent.at(-1)).toMatchObject({ offset: 30, limit: 10 })

    controller.setPage(99)
    expect(sent.at(-1)).toMatchObject({ offset: 40 }) // clamped to page 4
  })

  it('ingestWindow sets the resolved rows and the total / page counts', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 42)

    expect(controller.rows.get()).toEqual([
      {
        rowKey: 'a',
        row: { rowKey: 'a', slots: {} },
        placeholder: false,
        pending: null,
      },
    ])
    expect(controller.totalCount.get()).toBe(42)
    expect(controller.pageCount.get()).toBe(5)
  })

  it('applies a live row update at once, with nothing left pending', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
      live: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('takes a live removal out of the window instead of leaving a placeholder', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'progress', slots: { name: 'running' } },
        { rowKey: 'a', slots: { name: 'stored' } },
      ],
      2,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'progress',
      reason: 'deleted',
      live: true,
    })

    // A status row that ended has nothing to hold a place for: it goes, and no
    // Apply badge is left behind for a change the user never made.
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a'])
  })

  it('accumulates a row update as pending without changing the rows', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
    })

    controller.apply()
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('applies a removal as a placeholder in its slot', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
      ],
      2,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    const rows = controller.rows.get()
    expect(rows).toHaveLength(2) // layout not collapsed
    expect(rows[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
      pending: null,
    })
    expect(rows[1]?.placeholder).toBe(false)
  })

  it('applies a live count update at once without pending', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestCount(5)

    expect(controller.totalCount.get()).toBe(5)
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('appends a live tail row at once and bumps the total', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestAppend({ rowKey: 'b', slots: {} }, 2)

    const rows = controller.rows.get()
    expect(rows.map((row) => row.rowKey)).toEqual(['a', 'b'])
    expect(controller.totalCount.get()).toBe(2)
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('ignores a delta for a row outside the window', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'z',
      row: { rowKey: 'z', slots: {} },
    })

    expect(controller.pendingCount.get()).toBe(0)
  })

  it('discards pending when the window changes', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    expect(controller.pendingCount.get()).toBe(1)

    controller.setSearch('x')
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('applies a server-tagged own change at once, with no pre-mark', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    // The backend tags the delta `own` for the author connection; the client keeps
    // no per-row mark and simply trusts it.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('a server-tagged own change resolves a pending change racing on the same row', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    // A concurrent change for the same row lands first as pending (not own)...
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'other' } },
    })
    expect(controller.pendingCount.get()).toBe(1)
    // ...then the server tags this tab as the winning author, so it applies at once
    // and clears the pending — the race a client-side row-key mark left open is gone.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'mine' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'mine' },
    })
  })

  it('applies a server-tagged own removal at once as a placeholder', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
      pending: null,
    })
  })

  it('applies a server-tagged own edit to a server-minted create key the client never pre-marked', () => {
    const { controller } = makeController()
    controller.ingestWindow([], 0)
    // The server mints the new row's key and appends it live — the old client-side
    // mark could never have named it up front.
    controller.ingestAppend({ rowKey: 'srv-1', slots: { name: 'fresh' } }, 1)
    // A follow-up own edit to that minted key still applies at once via the server tag.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'srv-1',
      row: { rowKey: 'srv-1', slots: { name: 'edited' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'srv-1',
      slots: { name: 'edited' },
    })
  })

  it('queues an untagged delta as pending — only the server can grant own', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    // No own tag: another connection's edit gates as pending, never auto-applies.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'theirs' } },
    })

    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
    })
  })

  it('is not loaded until the first window arrives', () => {
    const { controller } = makeController()
    expect(controller.loaded.get()).toBe(false)
    controller.ingestWindow([], 0)
    expect(controller.loaded.get()).toBe(true)
  })

  it('exposes the pending kind on the affected rows', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      3,
    )
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: {} },
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })

    const rows = controller.rows.get()
    expect(rows[0]?.pending).toBe('update')
    expect(rows[1]?.pending).toBe('remove')
    expect(rows[2]?.pending).toBeNull()
  })

  it('applyAndResolve applies pending and returns the fresh row', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.applyAndResolve('a')).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('applyAndResolve returns null for a row whose removal it applies', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })

    expect(controller.applyAndResolve('a')).toBeNull()
  })
})
