import { describe, expect, it } from 'vitest'
import { TableRowsStore } from './TableRowsStore.js'

describe('TableRowsStore', () => {
  it('is undefined until the first replace, then exposes the snapshot', () => {
    const store = new TableRowsStore()
    const users = store.signal('users')
    expect(users.get()).toBeUndefined()

    store.replace('users', [{ rowKey: 1, slots: { user: { id: 1 } } }])
    expect(users.get()?.rows).toHaveLength(1)
    expect(users.get()?.rows[0]?.rowKey).toBe(1)
  })

  it('replaces the row set as a whole on every snapshot', () => {
    const store = new TableRowsStore()
    store.replace('users', [
      { rowKey: 1, slots: {} },
      { rowKey: 2, slots: {} },
    ])
    store.replace('users', [{ rowKey: 3, slots: {} }])

    expect(
      store
        .signal('users')
        .get()
        ?.rows.map((row) => row.rowKey),
    ).toEqual([3])
  })

  it('keeps tables independent of each other', () => {
    const store = new TableRowsStore()
    store.replace('users', [{ rowKey: 1, slots: {} }])

    expect(store.signal('tasks').get()).toBeUndefined()
  })

  it('shares one reactive cell per table between watchers', () => {
    const store = new TableRowsStore()
    const before = store.signal('users')
    store.replace('users', [{ rowKey: 1, slots: {} }])

    expect(before.get()).toBe(store.signal('users').get())
  })
})
