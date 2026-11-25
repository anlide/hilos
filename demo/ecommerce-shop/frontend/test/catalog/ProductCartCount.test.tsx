// @vitest-environment happy-dom

import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ProductCartCount } from '../../src/catalog/ProductCartCount.js'

describe('the product cart count', () => {
  let container: HTMLElement
  let root: Root

  beforeEach(() => {
    vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
    container = document.body.appendChild(document.createElement('div'))
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => root.unmount())
    container.remove()
    vi.unstubAllGlobals()
  })

  it.each([
    [0, 'In nobody’s cart yet'],
    [1, 'In 1 cart'],
    [5, 'In 5 carts'],
  ])('shows and announces the count of %i carts', (cartCount, text) => {
    act(() => root.render(<ProductCartCount cartCount={undefined} />))
    const status = container.querySelector('[role="status"]')
    const reading = container.querySelector('[data-id="product-cart-count"]')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(status?.textContent).toBe('')
    expect(reading?.textContent).toBe('')

    act(() => root.render(<ProductCartCount cartCount={cartCount} />))
    expect([...container.querySelectorAll('[role="status"]')]).toEqual([status])
    expect(status?.textContent).toBe(text)
    expect(reading?.textContent).toBe(text)
    expect(reading?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(reading?.querySelector('strong')?.textContent).toBe(
      cartCount === 0 ? undefined : String(cartCount),
    )

    act(() =>
      root.render(<ProductCartCount cartCount={cartCount} visible={false} />),
    )
    expect(container.querySelector('[role="status"]')).toBe(status)
    expect(status?.textContent).toBe('')
    expect(container.querySelector('[data-id="product-cart-count"]')).toBeNull()
  })
})
