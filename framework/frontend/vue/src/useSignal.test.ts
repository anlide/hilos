import { describe, expect, it } from 'vitest'
import { computed, effectScope } from 'vue'
import { createSignal } from '@hilos/core'
import { useSignal } from './useSignal.js'

describe('useSignal', () => {
  it('mirrors the core signal value and its changes', () => {
    const count = createSignal(1)
    const scope = effectScope()
    const value = scope.run(() => useSignal(count))
    expect(value?.value).toBe(1)

    count.set(2)
    expect(value?.value).toBe(2)
    scope.stop()
  })

  it('reads the value current at call time, not the initial one', () => {
    const count = createSignal(1)
    count.set(5)

    const scope = effectScope()
    const value = scope.run(() => useSignal(count))
    expect(value?.value).toBe(5)
    scope.stop()
  })

  it('participates in Vue reactivity downstream of the mirror', () => {
    const count = createSignal(1)
    const scope = effectScope()
    const doubled = scope.run(() => {
      const value = useSignal(count)
      return computed(() => value.value * 2)
    })
    expect(doubled?.value).toBe(2)

    count.set(3)
    expect(doubled?.value).toBe(6)
    scope.stop()
  })

  it('releases the subscription when the calling scope is disposed', () => {
    const count = createSignal(1)
    const scope = effectScope()
    const value = scope.run(() => useSignal(count))
    scope.stop()

    count.set(2)
    expect(value?.value).toBe(1)
  })
})
