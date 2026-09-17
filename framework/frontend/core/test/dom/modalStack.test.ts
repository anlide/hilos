// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import { enterModalLayer, leaveModalLayer } from '../../src/dom/modalStack.js'

const owners: object[] = []

/**
 * Create a layer owner the test cleans up after itself.
 *
 * @returns A fresh owner identity.
 */
function owner(): object {
  const created = {}
  owners.push(created)
  return created
}

afterEach(() => {
  owners.splice(0).forEach((created) => leaveModalLayer(created))
})

describe('modal stack', () => {
  it('gives a lone modal depth 0 and a modal over it depth 1', () => {
    expect(enterModalLayer(document, owner())).toBe(0)
    expect(enterModalLayer(document, owner())).toBe(1)
  })

  it('gives the next layer depth 1 again once the upper one closed', () => {
    const lower = owner()
    const upper = owner()
    enterModalLayer(document, lower)
    enterModalLayer(document, upper)

    leaveModalLayer(upper)

    expect(enterModalLayer(document, owner())).toBe(1)
  })

  it('stacks above the deepest live layer after a lower one closed first', () => {
    const lower = owner()
    const upper = owner()
    enterModalLayer(document, lower)
    enterModalLayer(document, upper)

    leaveModalLayer(lower)

    expect(enterModalLayer(document, owner())).toBe(2)
  })

  it('starts from depth 0 once every layer closed', () => {
    const lower = owner()
    const upper = owner()
    enterModalLayer(document, lower)
    enterModalLayer(document, upper)

    leaveModalLayer(upper)
    leaveModalLayer(lower)

    expect(enterModalLayer(document, owner())).toBe(0)
  })

  it('keeps a repeated entry by one owner at its first depth', () => {
    const lower = owner()
    enterModalLayer(document, lower)
    const upper = owner()
    enterModalLayer(document, upper)

    expect(enterModalLayer(document, lower)).toBe(0)
    expect(enterModalLayer(document, upper)).toBe(1)
  })

  it('does nothing when an owner that holds no layer leaves', () => {
    leaveModalLayer(owner())

    expect(enterModalLayer(document, owner())).toBe(0)
  })

  it('counts the layers of different documents separately', () => {
    const other = document.implementation.createHTMLDocument()
    enterModalLayer(document, owner())

    expect(enterModalLayer(other, owner())).toBe(0)
    expect(enterModalLayer(document, owner())).toBe(1)
  })
})
