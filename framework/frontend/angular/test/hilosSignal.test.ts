import { describe, expect, it } from 'vitest'
import { Injector, runInInjectionContext } from '@angular/core'
import { createSignal } from '@hilos/core'
import { hilosSignal } from '../src/hilosSignal.js'

/** Injector.create() returns an R3Injector; destroy() is its runtime API. */
type DestroyableInjector = Injector & { destroy(): void }

function createInjector(): DestroyableInjector {
  return Injector.create({ providers: [] }) as DestroyableInjector
}

describe('hilosSignal', () => {
  it('mirrors the core signal value and its changes', () => {
    const count = createSignal(1)
    const value = hilosSignal(count, { injector: createInjector() })
    expect(value()).toBe(1)

    count.set(2)
    expect(value()).toBe(2)
  })

  it('reads the value current at call time, not the initial one', () => {
    const count = createSignal(1)
    count.set(5)

    const value = hilosSignal(count, { injector: createInjector() })
    expect(value()).toBe(5)
  })

  it('resolves the injector from the ambient injection context', () => {
    const count = createSignal(1)
    const value = runInInjectionContext(createInjector(), () =>
      hilosSignal(count),
    )

    count.set(2)
    expect(value()).toBe(2)
  })

  it('throws outside an injection context when no injector is given', () => {
    expect(() => hilosSignal(createSignal(1))).toThrowError(/injection context/)
  })

  it('releases the subscription when the owning injector is destroyed', () => {
    const count = createSignal(1)
    const injector = createInjector()
    const value = hilosSignal(count, { injector })
    injector.destroy()

    count.set(2)
    expect(value()).toBe(1)
  })
})
