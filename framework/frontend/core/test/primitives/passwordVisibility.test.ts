import { describe, expect, it } from 'vitest'

import { createPasswordVisibilityState } from '../../src/primitives/passwordVisibility.js'

describe('createPasswordVisibilityState', () => {
  it('starts with visibility false', () => {
    const state = createPasswordVisibilityState('reg-1')
    expect(state.isVisible.get()).toBe(false)
  })

  it('toggle reveals password when context is active', () => {
    const state = createPasswordVisibilityState('reg-1')
    state.toggle()
    expect(state.isVisible.get()).toBe(true)
  })

  it('toggle hides password again', () => {
    const state = createPasswordVisibilityState('reg-1')
    state.toggle()
    expect(state.isVisible.get()).toBe(true)
    state.toggle()
    expect(state.isVisible.get()).toBe(false)
  })

  it('updateContext with same key preserves visible state', () => {
    const state = createPasswordVisibilityState('reg-1')
    state.toggle()
    expect(state.isVisible.get()).toBe(true)
    state.updateContext('reg-1')
    expect(state.isVisible.get()).toBe(true)
  })

  it('updateContext with different key resets visibility to false', () => {
    const state = createPasswordVisibilityState('reg-1')
    state.toggle()
    expect(state.isVisible.get()).toBe(true)
    state.updateContext('reg-2')
    expect(state.isVisible.get()).toBe(false)
  })

  it('updateContext with null resets visibility to false', () => {
    const state = createPasswordVisibilityState('reg-1')
    state.toggle()
    expect(state.isVisible.get()).toBe(true)
    state.updateContext(null)
    expect(state.isVisible.get()).toBe(false)
  })

  it('toggle does not reveal password when context is null (inactive instance)', () => {
    const state = createPasswordVisibilityState(null)
    state.toggle()
    expect(state.isVisible.get()).toBe(false)

    const stateWithContext = createPasswordVisibilityState('reg-1')
    stateWithContext.updateContext(null)
    stateWithContext.toggle()
    expect(stateWithContext.isVisible.get()).toBe(false)
  })

  it('two independent instances do not affect each other', () => {
    const state1 = createPasswordVisibilityState('reg-1')
    const state2 = createPasswordVisibilityState('reg-1')

    state1.toggle()
    expect(state1.isVisible.get()).toBe(true)
    expect(state2.isVisible.get()).toBe(false)

    state2.toggle()
    expect(state1.isVisible.get()).toBe(true)
    expect(state2.isVisible.get()).toBe(true)

    state1.updateContext('reg-2')
    expect(state1.isVisible.get()).toBe(false)
    expect(state2.isVisible.get()).toBe(true)
  })
})
