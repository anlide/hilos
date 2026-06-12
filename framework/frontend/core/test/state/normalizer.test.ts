import { describe, expect, it } from 'vitest'
import { type EntityFragment, ingest } from '../../src/state/normalizer.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'

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

  it('folds list items into the list store in order', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      lists: {
        events: {
          items: [
            { itemKey: 1, slots: { message: 'first' } },
            { itemKey: 2, slots: { message: 'second' } },
          ],
        },
      },
    })

    expect(scope.lists.signal('events').get()).toEqual([
      { itemKey: '1', slots: { message: 'first' } },
      { itemKey: '2', slots: { message: 'second' } },
    ])
  })

  it('upserts an entity-bearing list slot and leaves a reference in its place', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(
      scope,
      {
        lists: {
          users: {
            items: [
              {
                itemKey: 7,
                slots: { db_user: { id: 7, name: 'Ann' }, presence: 'online' },
              },
            ],
          },
        },
      },
      { entityTypes: { db_user: 'user' } },
    )

    expect(scope.lists.signal('users').get()).toEqual([
      {
        itemKey: '7',
        slots: { db_user: { type: 'user', id: 7 }, presence: 'online' },
      },
    ])
    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields,
    ).toEqual({ id: 7, name: 'Ann' })
  })

  it('references a MANY list slot as an order-preserving reference list', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      lists: {
        events: {
          items: [
            {
              itemKey: 1,
              slots: {
                attachment: [
                  { id: 10, filename: 'a.png' },
                  { id: 11, filename: 'b.png' },
                ],
              },
            },
          ],
        },
      },
    })

    expect(scope.lists.signal('events').get()[0]?.slots['attachment']).toEqual([
      { type: 'attachment', id: 10 },
      { type: 'attachment', id: 11 },
    ])
  })

  it('keeps a non-entity list slot inline', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      lists: {
        events: {
          items: [{ itemKey: 1, slots: { authorUserId: 7, message: 'hi' } }],
        },
      },
    })

    expect(scope.lists.signal('events').get()[0]?.slots).toEqual({
      authorUserId: 7,
      message: 'hi',
    })
  })

  it('dedupes a list reference against an entity delivered elsewhere', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(
      scope,
      {
        lists: {
          users: {
            items: [{ itemKey: 7, slots: { db_user: { id: 7, name: 'Ann' } } }],
          },
        },
      },
      { entityTypes: { db_user: 'user' } },
    )
    ingest(scope, { entities: { user: { id: 7, name: 'Bea' } } })

    // The list slot still references the same entity; only the entity changed.
    expect(scope.lists.signal('users').get()[0]?.slots['db_user']).toEqual({
      type: 'user',
      id: 7,
    })
    expect(
      scope.entities.signal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('Bea')
  })

  it('applies a delta: updates an item, removes a deleted key', () => {
    const scope = new ScopeManager().openPage('a')
    ingest(scope, {
      lists: {
        events: {
          items: [
            { itemKey: 1, slots: { message: 'a' } },
            { itemKey: 2, slots: { message: 'b' } },
          ],
        },
      },
    })
    ingest(scope, {
      lists: {
        events: {
          items: [{ itemKey: 1, slots: { message: 'edited' } }],
          deleted: [2],
        },
      },
    })

    expect(scope.lists.signal('events').get()).toEqual([
      { itemKey: '1', slots: { message: 'edited' } },
    ])
  })

  it('rejects a key that is both a list and a data key', () => {
    const scope = new ScopeManager().openPage('a')
    expect(() =>
      ingest(scope, { data: { events: 1 }, lists: { events: {} } }),
    ).toThrow("'events' is both a list and a data key")
  })
})
