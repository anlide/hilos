import { HIDDEN_VALUE } from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosHideable } from '../src/HilosHideable.js'

afterEach(() => {
  cleanup()
})

describe('HilosHideable', () => {
  it('prints a value that is not hidden as text when given no children', () => {
    const { container } = render(<HilosHideable value="Olena" />)

    expect(container.textContent).toBe('Olena')
    expect(container.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })

  it('prints nothing, not the word "null", for an absent value given no children', () => {
    const { container } = render(<HilosHideable value={null} />)

    expect(container.textContent).toBe('')
  })

  it('hands a value that is not hidden to the children render function', () => {
    const { container } = render(
      <HilosHideable value="olena@example.com">
        {(value) => <code data-id="shown">{value.toUpperCase()}</code>}
      </HilosHideable>,
    )

    expect(container.querySelector('[data-id="shown"]')!.textContent).toBe(
      'OLENA@EXAMPLE.COM',
    )
  })

  it('draws the hidden mark for a hidden value and never calls the children function', () => {
    let called = false
    const { container } = render(
      <HilosHideable value={HIDDEN_VALUE}>
        {() => {
          called = true

          return <code>never</code>
        }}
      </HilosHideable>,
    )

    expect(
      container.querySelector('[data-id="hilos-hidden"]')!.textContent,
    ).toBe('Hidden')
    expect(called).toBe(false)
  })
})
