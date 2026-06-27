import { describe, expect, it } from 'vitest'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'

const ref = { type: 'user', id: 1 }

function nameIn(manager: ScopeManager): string | undefined {
  return manager.entitySignal(ref).get()?.fields['name'] as string | undefined
}

describe('ScopeManager scopes', () => {
  it('always carries the session and user scopes', () => {
    const manager = new ScopeManager()
    expect(manager.session.kind).toBe('session')
    expect(manager.user.kind).toBe('user')
    expect(manager.page()).toBeUndefined()
  })

  it('opens keyed page and group scopes', () => {
    const manager = new ScopeManager()
    const page = manager.openPage('settings')
    expect(page).toMatchObject({ kind: 'page', key: 'settings' })
    expect(manager.page()).toBe(page)

    const group = manager.openGroup('languages')
    expect(group).toMatchObject({ kind: 'group', key: 'languages' })
    expect(manager.group('languages')).toBe(group)
  })

  it('replaces the page scope on navigation, dropping its entities', () => {
    const manager = new ScopeManager()
    manager.openPage('a').entities.upsert(ref, { name: 'Ann' })
    expect(nameIn(manager)).toBe('Ann')

    manager.openPage('b')
    expect(nameIn(manager)).toBeUndefined()
  })

  it('treats group scopes strictly: no double open, no unknown drop', () => {
    const manager = new ScopeManager()
    manager.openGroup('languages')
    expect(() => manager.openGroup('languages')).toThrow('already open')
    expect(() => manager.dropGroup('currencies')).toThrow('not open')
  })
})

describe('ScopeManager entity resolution', () => {
  it('resolves most-specific-first: page, then session, then user, then groups', () => {
    const manager = new ScopeManager()
    manager.openGroup('team').entities.upsert(ref, { name: 'from-group' })
    manager.user.entities.upsert(ref, { name: 'from-user' })
    manager.session.entities.upsert(ref, { name: 'from-session' })
    manager.openPage('a').entities.upsert(ref, { name: 'from-page' })

    expect(nameIn(manager)).toBe('from-page')
    manager.dropPage()
    expect(nameIn(manager)).toBe('from-session')
  })

  it('resolves groups in opening order, after the user scope', () => {
    const manager = new ScopeManager()
    manager.openGroup('first').entities.upsert(ref, { name: 'from-first' })
    manager.openGroup('second').entities.upsert(ref, { name: 'from-second' })
    expect(nameIn(manager)).toBe('from-first')

    manager.dropGroup('first')
    expect(nameIn(manager)).toBe('from-second')
  })

  it('re-resolves reactively when a more specific scope takes the entity over', () => {
    const manager = new ScopeManager()
    const seen: (string | undefined)[] = []
    subscribeSignal(manager.entitySignal(ref), (snapshot) =>
      seen.push(snapshot?.fields['name'] as string | undefined),
    )

    manager.user.entities.upsert(ref, { name: 'from-user' })
    manager.openPage('a').entities.upsert(ref, { name: 'from-page' })
    expect(seen).toEqual(['from-user', 'from-page'])
  })

  it('falls back reactively when the winning scope is dropped', () => {
    const manager = new ScopeManager()
    manager.session.entities.upsert(ref, { name: 'from-session' })
    manager.openPage('a').entities.upsert(ref, { name: 'from-page' })

    const seen: (string | undefined)[] = []
    subscribeSignal(manager.entitySignal(ref), (snapshot) =>
      seen.push(snapshot?.fields['name'] as string | undefined),
    )

    manager.dropPage()
    expect(seen).toEqual(['from-session'])
  })

  it('frees group entities reactively when the group scope is dropped', () => {
    const manager = new ScopeManager()
    manager.openGroup('team').entities.upsert(ref, { name: 'from-group' })

    const seen: (string | undefined)[] = []
    subscribeSignal(manager.entitySignal(ref), (snapshot) =>
      seen.push(snapshot?.fields['name'] as string | undefined),
    )

    manager.dropGroup('team')
    expect(seen).toEqual([undefined])
  })

  it('tracks committed changes inside the winning scope', () => {
    const manager = new ScopeManager()
    const page = manager.openPage('a')
    page.entities.upsert(ref, { name: 'Ann' })

    const seen: (string | undefined)[] = []
    subscribeSignal(manager.entitySignal(ref), (snapshot) =>
      seen.push(snapshot?.fields['name'] as string | undefined),
    )

    page.entities.upsert(ref, { name: 'Bea' })
    expect(seen).toEqual(['Bea'])
  })
})

describe('ScopeManager page list resolution', () => {
  it('is empty when no page is open', () => {
    const manager = new ScopeManager()
    expect(manager.pageListSignal('events').get()).toEqual([])
  })

  it('reads the current page scope list reactively', () => {
    const manager = new ScopeManager()
    const page = manager.openPage('a')

    const lengths: number[] = []
    subscribeSignal(manager.pageListSignal('events'), (items) =>
      lengths.push(items.length),
    )

    page.lists.upsert('events', 1, { text: 'a' })
    page.lists.upsert('events', 2, { text: 'b' })
    expect(lengths).toEqual([1, 2])
  })

  it('re-resolves to the new page list when navigation swaps the scope', () => {
    const manager = new ScopeManager()
    manager.openPage('a').lists.upsert('events', 1, { text: 'from-a' })
    expect(manager.pageListSignal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'from-a' } },
    ])

    manager.openPage('b')
    expect(manager.pageListSignal('events').get()).toEqual([])
  })
})

describe('ScopeManager page data resolution', () => {
  it('is undefined when no page is open', () => {
    const manager = new ScopeManager()
    expect(manager.pageDataSignal('selfConnection').get()).toBeUndefined()
  })

  it('reads the current page scope datum reactively', () => {
    const manager = new ScopeManager()
    const page = manager.openPage('a')

    const seen: unknown[] = []
    subscribeSignal(manager.pageDataSignal('selfConnection'), (value) =>
      seen.push(value),
    )

    page.data.set('selfConnection', { rateLimit: 7 })
    expect(seen).toEqual([{ rateLimit: 7 }])
  })

  it('re-resolves to the new page datum when navigation swaps the scope', () => {
    const manager = new ScopeManager()
    manager.openPage('a').data.set('selfConnection', { rateLimit: 7 })
    expect(manager.pageDataSignal('selfConnection').get()).toEqual({
      rateLimit: 7,
    })

    manager.openPage('b')
    expect(manager.pageDataSignal('selfConnection').get()).toBeUndefined()
  })
})

describe('ScopeManager page table resolution', () => {
  it('is empty when no page is open', () => {
    const manager = new ScopeManager()
    expect(manager.pageTableSignal('hilosUsers').get()).toEqual([])
  })

  it('reads the current page scope table reactively', () => {
    const manager = new ScopeManager()
    const page = manager.openPage('a')

    const lengths: number[] = []
    subscribeSignal(manager.pageTableSignal('hilosUsers'), (rows) =>
      lengths.push(rows.length),
    )

    page.tables.upsert('hilosUsers', 1, { name: 'a' })
    page.tables.upsert('hilosUsers', 2, { name: 'b' })
    expect(lengths).toEqual([1, 2])
  })

  it('re-resolves to the new page table when navigation swaps the scope', () => {
    const manager = new ScopeManager()
    manager.openPage('a').tables.upsert('hilosUsers', 1, { name: 'from-a' })
    expect(manager.pageTableSignal('hilosUsers').get()).toEqual([
      { rowKey: '1', slots: { name: 'from-a' } },
    ])

    manager.openPage('b')
    expect(manager.pageTableSignal('hilosUsers').get()).toEqual([])
  })
})
