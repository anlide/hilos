import { describe, expect, it } from 'vitest'
import { type EntityFragment, ingest } from './normalizer.js'
import { ScopeManager } from './ScopeManager.js'

describe('ingest', () => {
  it('upserts a single entity slot and leaves a reference in the data store', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, { entities: { author: { id: 7, name: 'Ann' } } })

    expect(scope.data.signal('author').get()).toEqual({ type: 'author', id: 7 })
    expect(
      scope.entities.signal({ type: 'author', id: 7 }).get(),
    ).toMatchObject({ fields: { id: 7, name: 'Ann' } })
  })

  it('upserts a list slot, preserving its order in the reference list', () => {
    const scope = new ScopeManager().openGroup('languages')
    ingest(scope, {
      entities: {
        language: [
          { id: 'en', name: 'English' },
          { id: 'ru', name: 'Russian' },
        ],
      },
    })

    expect(scope.data.signal('language').get()).toEqual([
      { type: 'language', id: 'en' },
      { type: 'language', id: 'ru' },
    ])
    expect(
      scope.entities.signal({ type: 'language', id: 'ru' }).get()?.fields,
    ).toEqual({ id: 'ru', name: 'Russian' })
  })

  it('maps a binding-local sourceKey to its canonical entityType', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(
      scope,
      { entities: { db_author: { id: 7, name: 'Ann' } } },
      { entityTypes: { db_author: 'user' } },
    )

    expect(scope.data.signal('db_author').get()).toEqual({
      type: 'user',
      id: 7,
    })
    expect(scope.entities.signal({ type: 'user', id: 7 }).get()).toBeDefined()
    expect(
      scope.entities.signal({ type: 'db_author', id: 7 }).get(),
    ).toBeUndefined()
  })

  it('dedupes two slots naming the same entity into one per-scope copy', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(
      scope,
      {
        entities: {
          user: { id: 7, name: 'Ann' },
          db_author: { id: 7, role: 'writer' },
        },
      },
      { entityTypes: { db_author: 'user' } },
    )

    const snapshot = scope.entities.signal({ type: 'user', id: 7 }).get()
    expect(snapshot?.fields).toEqual({ id: 7, name: 'Ann', role: 'writer' })
    expect(snapshot?.revision).toBe(2)
  })

  it('merges a later partial fragment into the existing entity', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, { entities: { user: { id: 7, name: 'Ann', age: 30 } } })
    ingest(scope, { entities: { user: { id: 7, name: 'Bea' } } })

    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, name: 'Bea', age: 30 })
  })

  it('stores plain data as-is', () => {
    const scope = new ScopeManager().session
    ingest(scope, { data: { route: '/inbox', filters: { open: true } } })

    expect(scope.data.signal('route').get()).toBe('/inbox')
    expect(scope.data.signal('filters').get()).toEqual({ open: true })
  })

  it('rejects a key that is both an entity slot and a data key', () => {
    const scope = new ScopeManager().openPage('a')
    expect(() =>
      ingest(scope, { entities: { user: { id: 1 } }, data: { user: 1 } }),
    ).toThrow("'user' is both")
  })

  it('rejects an entity fragment without a stable id', () => {
    const scope = new ScopeManager().openPage('a')
    const fragment = { name: 'Ann' } as unknown as EntityFragment
    expect(() => ingest(scope, { entities: { user: fragment } })).toThrow(
      'no stable id',
    )
  })

  it('normalizes table rows: fragment slots become refs, plain slots stay', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      tables: {
        users: {
          rows: [
            {
              rowKey: 7,
              slots: { user: { id: 7, name: 'Ann' }, online: true },
            },
          ],
        },
      },
    })

    expect(scope.tables.signal('users').get()?.rows).toEqual([
      { rowKey: 7, slots: { user: { type: 'user', id: 7 }, online: true } },
    ])
    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, name: 'Ann' })
  })

  it('keeps unmapped row and entity slot aliases as distinct types', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      entities: { currentUser: { id: 7, name: 'Ann' } },
      tables: {
        users: { rows: [{ rowKey: 7, slots: { user: { id: 7, age: 30 } } }] },
      },
    })

    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, age: 30 })
    expect(
      scope.entities.signal({ type: 'currentUser', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, name: 'Ann' })
  })

  it('dedupes a row slot and an entity slot mapped to one type', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(
      scope,
      {
        entities: { currentUser: { id: 7, name: 'Ann' } },
        tables: {
          users: {
            rows: [{ rowKey: 7, slots: { user: { id: 7, age: 30 } } }],
          },
        },
      },
      { entityTypes: { currentUser: 'user', user: 'user' } },
    )

    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, name: 'Ann', age: 30 })
  })

  it('re-ingesting a table replaces its row set as a whole', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      tables: { users: { rows: [{ rowKey: 1, slots: {} }] } },
    })
    ingest(scope, {
      tables: { users: { rows: [{ rowKey: 2, slots: {} }] } },
    })

    expect(
      scope.tables
        .signal('users')
        .get()
        ?.rows.map((row) => row.rowKey),
    ).toEqual([2])
  })
})
