import { describe, expect, it } from 'vitest'

import { createPasswordVisibilityState } from '../../src/primitives/passwordVisibility.js'

/** A state already set to the given context, so that toggle() acts. */
function activeState(context: string) {
  const state = createPasswordVisibilityState()
  state.setContext(context)
  return state
}

describe('createPasswordVisibilityState', () => {
  it('starts with visibility false', () => {
    const state = activeState('reg-1')
    expect(state.visible.get()).toBe(false)
  })

  it('toggle reveals password when context is active', () => {
    const state = activeState('reg-1')
    state.toggle()
    expect(state.visible.get()).toBe(true)
  })

  it('toggle hides password again', () => {
    const state = activeState('reg-1')
    state.toggle()
    expect(state.visible.get()).toBe(true)
    state.toggle()
    expect(state.visible.get()).toBe(false)
  })

  it('setContext with same key preserves visible state', () => {
    const state = activeState('reg-1')
    state.toggle()
    expect(state.visible.get()).toBe(true)
    state.setContext('reg-1')
    expect(state.visible.get()).toBe(true)
  })

  it('setContext with different key resets visibility to false', () => {
    const state = activeState('reg-1')
    state.toggle()
    expect(state.visible.get()).toBe(true)
    state.setContext('reg-2')
    expect(state.visible.get()).toBe(false)
  })

  it('setContext with null resets visibility to false', () => {
    const state = activeState('reg-1')
    state.toggle()
    expect(state.visible.get()).toBe(true)
    state.setContext(null)
    expect(state.visible.get()).toBe(false)
  })

  it('toggle does not reveal password when context is null (inactive instance)', () => {
    const state = createPasswordVisibilityState()
    state.toggle()
    expect(state.visible.get()).toBe(false)

    const stateWithContext = activeState('reg-1')
    stateWithContext.setContext(null)
    stateWithContext.toggle()
    expect(stateWithContext.visible.get()).toBe(false)
  })

  it('two independent instances do not affect each other', () => {
    const state1 = activeState('reg-1')
    const state2 = activeState('reg-1')

    state1.toggle()
    expect(state1.visible.get()).toBe(true)
    expect(state2.visible.get()).toBe(false)

    state2.toggle()
    expect(state1.visible.get()).toBe(true)
    expect(state2.visible.get()).toBe(true)

    state1.setContext('reg-2')
    expect(state1.visible.get()).toBe(false)
    expect(state2.visible.get()).toBe(true)
  })
})
