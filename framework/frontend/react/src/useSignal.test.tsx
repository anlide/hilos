import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import { createSignal } from '@hilos/core'
import type { ReadonlySignal } from '@hilos/core'
import { useSignal } from './useSignal.js'

function Probe({ source }: { source: ReadonlySignal<number> }) {
  const value = useSignal(source)
  return <span data-testid="value">{value}</span>
}

function valueText(): string | null {
  return screen.getByTestId('value').textContent
}

describe('useSignal', () => {
  afterEach(cleanup)

  it('mirrors the core signal value and its changes', () => {
    const count = createSignal(1)
    render(<Probe source={count} />)
    expect(valueText()).toBe('1')

    act(() => {
      count.set(2)
    })
    expect(valueText()).toBe('2')
  })

  it('reads the value current at mount time, not the initial one', () => {
    const count = createSignal(1)
    count.set(5)

    render(<Probe source={count} />)
    expect(valueText()).toBe('5')
  })

  it('releases the subscription on unmount', () => {
    // A tracked wrapper: while subscribed, every change re-runs the watched
    // getter; once released, changes stop reaching get().
    const count = createSignal(1)
    let reads = 0
    const source: ReadonlySignal<number> = {
      get: () => {
        reads += 1
        return count.get()
      },
    }

    const { unmount } = render(<Probe source={source} />)
    unmount()
    const readsAfterUnmount = reads

    act(() => {
      count.set(2)
    })
    expect(reads).toBe(readsAfterUnmount)
  })
})
