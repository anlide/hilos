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
