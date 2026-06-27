import { describe, expect, it } from 'vitest'
import { Injector, runInInjectionContext } from '@angular/core'
import { ScopeManager } from '@hilos/core'
import { entitySignal } from '../src/entitySignal.js'

/** Injector.create() returns an R3Injector; destroy() is its runtime API. */
type DestroyableInjector = Injector & { destroy(): void }

function createInjector(): DestroyableInjector {
  return Injector.create({ providers: [] }) as DestroyableInjector
}

const ref = { type: 'user', id: 1 }

describe('entitySignal', () => {
  it('resolves the reference reactively across scope changes', () => {
    const manager = new ScopeManager()
    manager.user.entities.upsert(ref, { name: 'Ann' })
    const entity = entitySignal(manager, ref, { injector: createInjector() })
    expect(entity()?.fields).toEqual({ name: 'Ann' })

    manager.openPage('a').entities.upsert(ref, { name: 'Bea' })
    expect(entity()?.fields).toEqual({ name: 'Bea' })

    manager.dropPage()
    expect(entity()?.fields).toEqual({ name: 'Ann' })
  })

  it('is undefined until the entity appears', () => {
    const manager = new ScopeManager()
    const entity = entitySignal(manager, ref, { injector: createInjector() })
    expect(entity()).toBeUndefined()

    manager.session.entities.upsert(ref, { name: 'Ann' })
    expect(entity()?.fields).toEqual({ name: 'Ann' })
  })

  it('resolves the injector from the ambient injection context', () => {
    const manager = new ScopeManager()
    const entity = runInInjectionContext(createInjector(), () =>
      entitySignal(manager, ref),
    )

    manager.session.entities.upsert(ref, { name: 'Ann' })
    expect(entity()?.fields).toEqual({ name: 'Ann' })
  })

  it('throws outside an injection context when no injector is given', () => {
    expect(() => entitySignal(new ScopeManager(), ref)).toThrowError(
      /injection context/,
    )
  })
})
