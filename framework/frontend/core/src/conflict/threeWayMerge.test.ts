import { describe, expect, it } from 'vitest'

import { threeWayMerge } from './threeWayMerge.js'

describe('threeWayMerge', () => {
  it('reports unchanged when neither side moved', () => {
    const result = threeWayMerge('a', 'a', 'a')
    expect(result.status).toBe('unchanged')
    expect(result.conflict).toBe(false)
    expect(result.value).toBe('a')
  })

  it('keeps the draft when only the user changed it', () => {
    const result = threeWayMerge('a', 'b', 'a')
    expect(result.status).toBe('user')
    expect(result.conflict).toBe(false)
    expect(result.value).toBe('b')
  })

  it('takes incoming when only the server changed it', () => {
    const result = threeWayMerge('a', 'a', 'c')
    expect(result.status).toBe('incoming')
    expect(result.conflict).toBe(false)
    expect(result.value).toBe('c')
  })

  it('converges without conflict when both reach the same value', () => {
    const result = threeWayMerge('a', 'x', 'x')
    expect(result.status).toBe('converged')
    expect(result.conflict).toBe(false)
    expect(result.value).toBe('x')
  })

  it('flags a conflict when both diverge, keeping the draft to edit from', () => {
    const result = threeWayMerge('a', 'b', 'c')
    expect(result.status).toBe('conflict')
    expect(result.conflict).toBe(true)
    expect(result.value).toBe('b')
  })

  it('honors a custom equals for structural fields', () => {
    const equals = (a: { v: number }, b: { v: number }): boolean => a.v === b.v
    const result = threeWayMerge({ v: 1 }, { v: 1 }, { v: 2 }, equals)
    expect(result.status).toBe('incoming')
    expect(result.value).toEqual({ v: 2 })
  })
})
