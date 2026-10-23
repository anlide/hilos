import { describe, expect, expectTypeOf, it, vi } from 'vitest'
import { subscribeSignal, type ReadonlySignal } from '../../src/state/signal.js'
import { TableTextExpansion } from '../../src/table/tableTextExpansion.js'

describe('TableTextExpansion', () => {
  it('publishes independent occurrence/column pairs without redundant notifications', () => {
    const owner = new TableTextExpansion()
    expectTypeOf(owner.expanded).toEqualTypeOf<
      ReadonlySignal<ReadonlyMap<number, ReadonlySet<string>>>
    >()
    const changed = vi.fn()
    const stop = subscribeSignal(owner.expanded, changed)
    owner.setExpanded(1, 'name', false)
    expect(changed).not.toHaveBeenCalled()
    owner.setExpanded(1, 'name', true)
    const first = owner.expanded.get()
    owner.setExpanded(1, 'name', true)
    expect(changed).toHaveBeenCalledTimes(1)
    owner.setExpanded(1, 'description', true)
    owner.setExpanded(2, 'name', true)
    owner.setExpanded(1, 'name', false)
    expect(owner.expanded.get()).toEqual(
      new Map([
        [1, new Set(['description'])],
        [2, new Set(['name'])],
      ]),
    )
    expect(first).toEqual(new Map([[1, new Set(['name'])]]))
    owner.setExpanded(1, 'description', false)
    expect(owner.expanded.get().has(1)).toBe(false)
    stop()
  })

  it('forgets all text of exactly one retired occurrence', () => {
    const owner = new TableTextExpansion()
    owner.setExpanded(1, 'name', true)
    owner.setExpanded(1, 'description', true)
    owner.setExpanded(2, 'name', true)
    owner.forgetOccurrence(1)
    const current = owner.expanded.get()
    expect(current).toEqual(new Map([[2, new Set(['name'])]]))
    owner.forgetOccurrence(1)
    expect(owner.expanded.get()).toBe(current)
  })

  it('prunes deleted declarations and never restores them when a key returns', () => {
    const owner = new TableTextExpansion()
    owner.setExpanded(1, 'name', true)
    owner.setExpanded(1, 'description', true)
    owner.setExpanded(2, 'name', true)
    const first = owner.expanded.get()
    owner.retainColumns(new Set(['name', 'description']))
    expect(owner.expanded.get()).toBe(first)
    owner.retainColumns(new Set(['description']))
    expect(owner.expanded.get()).toEqual(
      new Map([[1, new Set(['description'])]]),
    )
    owner.retainColumns(new Set(['name', 'description']))
    expect(owner.expanded.get()).toEqual(
      new Map([[1, new Set(['description'])]]),
    )
    owner.retainColumns(new Set())
    expect(owner.expanded.get().size).toBe(0)
  })

  it('keeps only numeric occurrences and column keys, private to each owner', () => {
    const first = new TableTextExpansion()
    const second = new TableTextExpansion()
    first.setExpanded(1, 'name', true)
    expect([...first.expanded.get()]).toEqual([[1, new Set(['name'])]])
    expect(second.expanded.get().size).toBe(0)
    const snapshot = first.expanded.get()
    first.clear()
    expect(first.expanded.get().size).toBe(0)
    expect(snapshot).toEqual(new Map([[1, new Set(['name'])]]))
    const empty = first.expanded.get()
    first.clear()
    expect(first.expanded.get()).toBe(empty)
  })
})
