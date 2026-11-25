import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createSignal } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { type CartLine } from '../../../src/bootstrap/cart.js'
import CartHeaderLink, {
  type CartHeaderLinkProps,
} from '../../../src/views/Cart/CartHeaderLink.js'

describe('CartHeaderLink', () => {
  it('renders the cart link without a badge when the cart is null', () => {
    const cart = createSignal<readonly CartLine[] | null>(null)
    const html = renderToStaticMarkup(
      createElement<CartHeaderLinkProps>(CartHeaderLink, { cart }),
    )

    expect(html).toContain('href="/cart"')
    expect(html).toContain('data-id="nav-cart"')
    expect(html).toContain('aria-label="Cart"')
    expect(html).toContain('bi-cart3')
    expect(html).not.toContain('data-id="cart-badge"')
  })

  it('renders 0 items badge when the cart is empty', () => {
    const cart = createSignal<readonly CartLine[] | null>([])
    const html = renderToStaticMarkup(
      createElement<CartHeaderLinkProps>(CartHeaderLink, { cart }),
    )

    expect(html).toContain('href="/cart"')
    expect(html).toContain('data-id="nav-cart"')
    expect(html).toContain('aria-label="Cart, 0 items"')
    expect(html).toContain('data-id="cart-badge"')
    expect(html).toContain('>0</span>')
  })

  it('renders 1 item badge when the cart has a single unit', () => {
    const cart = createSignal<readonly CartLine[] | null>([
      { productId: 1, quantity: 1 },
    ])
    const html = renderToStaticMarkup(
      createElement<CartHeaderLinkProps>(CartHeaderLink, { cart }),
    )

    expect(html).toContain('href="/cart"')
    expect(html).toContain('data-id="nav-cart"')
    expect(html).toContain('aria-label="Cart, 1 item"')
    expect(html).toContain('data-id="cart-badge"')
    expect(html).toContain('>1</span>')
  })

  it('renders 5 items badge when lines sum to 2 + 3', () => {
    const cart = createSignal<readonly CartLine[] | null>([
      { productId: 1, quantity: 2 },
      { productId: 2, quantity: 3 },
    ])
    const html = renderToStaticMarkup(
      createElement<CartHeaderLinkProps>(CartHeaderLink, { cart }),
    )

    expect(html).toContain('href="/cart"')
    expect(html).toContain('data-id="nav-cart"')
    expect(html).toContain('aria-label="Cart, 5 items"')
    expect(html).toContain('data-id="cart-badge"')
    expect(html).toContain('>5</span>')
  })

  it('renders the exact total above the safe integer range', () => {
    const cart = createSignal<readonly CartLine[] | null>([
      { productId: 1, quantity: Number.MAX_SAFE_INTEGER },
      { productId: 2, quantity: 2 },
    ])
    const html = renderToStaticMarkup(
      createElement<CartHeaderLinkProps>(CartHeaderLink, { cart }),
    )

    expect(html).toContain('aria-label="Cart, 9007199254740993 items"')
    expect(html).toContain('data-id="cart-badge">9007199254740993</span>')
  })
})
