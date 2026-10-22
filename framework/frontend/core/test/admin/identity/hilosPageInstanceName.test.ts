import { describe, expect, it, vi } from 'vitest'

import {
  bindPageInstanceName,
  pageInstanceName,
} from '../../../src/admin/identity/hilosPageInstanceName.js'
import { bindPageInstanceName as baseBinding } from '../../../src/base/index.js'
import { bindPageInstanceName as rootBinding } from '../../../src/index.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
} from '../../../src/state/signal.js'

describe('bindPageInstanceName', () => {
  it('shares one public binding implementation between root and base', () => {
    expect(baseBinding).toBe(bindPageInstanceName)
    expect(rootBinding).toBe(bindPageInstanceName)
  })

  it('reacts to a late registration and to changes in its live source', () => {
    const scopes = new ScopeManager()
    scopes.openPage('entity_names')
    const name = createSignal<string | undefined>('First')
    const current = computedSignal(() => pageInstanceName(scopes, 'entity'))
    const changed = vi.fn()
    const stop = subscribeSignal(current, changed)
    expect(current.get()).toBeUndefined()

    const dispose = bindPageInstanceName(scopes, 'entity', name)
    expect(current.get()).toBe('First')
    expect(changed).toHaveBeenLastCalledWith('First')
    name.set('Renamed')
    expect(current.get()).toBe('Renamed')
    expect(changed).toHaveBeenLastCalledWith('Renamed')

    dispose()
    expect(current.get()).toBeUndefined()
    dispose()
    expect(current.get()).toBeUndefined()
    name.set('After disposal')
    expect(current.get()).toBeUndefined()
    stop()
  })

  it('distinguishes undefined from a literal empty name', () => {
    const scopes = new ScopeManager()
    scopes.openPage('entity')
    const name = createSignal<string | undefined>(undefined)
    bindPageInstanceName(scopes, 'entity', name)
    const current = computedSignal(
      () => pageInstanceName(scopes, 'entity') ?? 'Catalog',
    )
    expect(current.get()).toBe('Catalog')
    name.set('')
    expect(current.get()).toBe('')
    name.set(undefined)
    expect(current.get()).toBe('Catalog')
  })

  it('isolates applications and keys with identical catalog captions', () => {
    const first = new ScopeManager()
    const second = new ScopeManager()
    first.openPage('entity')
    second.openPage('entity')
    bindPageInstanceName(first, 'entity', createSignal('First app'))
    bindPageInstanceName(first, 'parent', createSignal('Parent'))
    bindPageInstanceName(second, 'entity', createSignal('Second app'))
    expect(pageInstanceName(first, 'entity')).toBe('First app')
    expect(pageInstanceName(first, 'parent')).toBe('Parent')
    expect(pageInstanceName(second, 'entity')).toBe('Second app')
    expect(pageInstanceName(second, 'parent')).toBeUndefined()
  })

  it('keeps a newer registration when the old view disposes late', () => {
    const scopes = new ScopeManager()
    scopes.openPage('entity')
    const old = createSignal('Old')
    const disposeOld = bindPageInstanceName(scopes, 'entity', old)
    const disposeNew = bindPageInstanceName(
      scopes,
      'entity',
      createSignal('New'),
    )
    disposeOld()
    disposeOld()
    old.set('Stale update')
    expect(pageInstanceName(scopes, 'entity')).toBe('New')
    disposeNew()
    expect(pageInstanceName(scopes, 'entity')).toBeUndefined()
  })

  it('does not restore an older producer when its replacement disposes', () => {
    const scopes = new ScopeManager()
    scopes.openPage('entity')
    bindPageInstanceName(scopes, 'entity', createSignal('Old'))
    const dispose = bindPageInstanceName(scopes, 'entity', createSignal('New'))
    dispose()
    expect(pageInstanceName(scopes, 'entity')).toBeUndefined()
  })

  it('reads no source outside a page scope and keeps bindings over scope resets', () => {
    const scopes = new ScopeManager()
    const name = scopes.pageDataSignal('name')
    bindPageInstanceName(
      scopes,
      'entity',
      computedSignal(() => name.get() as string | undefined),
    )
    const current = computedSignal(() => pageInstanceName(scopes, 'entity'))
    expect(current.get()).toBeUndefined()
    const first = scopes.openPage('entity')
    first.data.set('name', 'First country')
    expect(current.get()).toBe('First country')
    scopes.dropPage()
    expect(current.get()).toBeUndefined()
    const second = scopes.openPage('entity')
    expect(current.get()).toBeUndefined()
    second.data.set('name', 'Second country')
    expect(current.get()).toBe('Second country')
    first.data.set('name', 'Late old answer')
    expect(current.get()).toBe('Second country')
  })
})
