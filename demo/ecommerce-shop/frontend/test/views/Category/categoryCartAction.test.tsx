// @vitest-environment happy-dom

import {
  ActionError,
  TABLE_VIEW_COPY,
  TableViewportController,
  createSignal,
  hilosToasts,
  type ActionResult,
} from '@hilos/core/base'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { type CartLine } from '../../../src/bootstrap/cart.js'
import { actions } from '../../../src/bootstrap/connection.js'
import { PAGE_CATEGORY } from '../../../src/pages/keys.js'
import CategoryProductsTable from '../../../src/views/Category/CategoryProductsTable.js'
import {
  CATEGORY_PRODUCTS_FRAME,
  FILTER_CATEGORY,
  resolveCategoryProductRow,
} from '../../../src/views/Category/categoryProducts.js'
import { createCategoryProductsTable } from '../../../src/views/Category/categoryProductsTable.js'
import { type CategoryProductRow } from '../../../src/views/Category/types/tables/CategoryProductRow.js'

vi.mock(import('../../../src/bootstrap/cart.js'), async (importOriginal) => ({
  ...(await importOriginal()),
  get currentCart() {
    return cart
  },
}))
vi.mock('../../../src/views/Category/categoryProductsTable.js', () => ({
  createCategoryProductsTable: vi.fn(),
}))

const cart = createSignal<readonly CartLine[] | null>(null)
const unique: CategoryProductRow = {
  id: 26,
  slug: 'summer-bouquet',
  name: 'Summer bouquet',
  pictureUrl: '/pictures/bouquet.webp',
  pictureAlt: '',
  kind: 'unique',
  price: 41000,
  units: 1,
  availability: 'free',
}
const stock: CategoryProductRow = {
  ...unique,
  id: 12,
  slug: 'ceramic-pot',
  name: 'Ceramic pot',
  kind: 'stock',
  units: 3,
  availability: 'low',
}
const categoryId = 7
const uniqueLine = { productId: unique.id, quantity: 1 }
const stockLine = { productId: stock.id, quantity: 1 }
let container: HTMLDivElement
let root: Root
let controller: TableViewportController<CategoryProductRow>

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  cart.set(null)
  container = document.body.appendChild(document.createElement('div'))
  root = createRoot(container)
  controller = new TableViewportController<CategoryProductRow>({
    resolve: (row) => resolveCategoryProductRow(row, () => undefined),
    frame: CATEGORY_PRODUCTS_FRAME,
    initialFilter: { [FILTER_CATEGORY]: categoryId },
    sendViewport: vi.fn(),
  })
  vi.mocked(createCategoryProductsTable).mockReturnValue({
    controller,
    start: vi.fn(),
    dispose: () => controller.dispose(),
  })
})

afterEach(async () => {
  await act(async () => root.unmount())
  container.remove()
  hilosToasts.clear()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

async function render(...products: CategoryProductRow[]): Promise<void> {
  await act(async () => {
    controller.ingestSubscriptionWindow(
      products.map((product, index) => ({
        rowKey: String(index + 1),
        slots: { product },
      })),
      products.length,
      true,
      null,
      null,
      25,
      undefined,
      { [FILTER_CATEGORY]: categoryId },
      [],
    )
    root.render(
      <CategoryProductsTable page={PAGE_CATEGORY} categoryId={categoryId} />,
    )
  })
}

function tile(product: CategoryProductRow): Element {
  const items = container.querySelectorAll(
    '[data-id="hilos-table-tiles"] [role="listitem"]',
  )
  const item = Array.from(items).find(
    (candidate) => candidate.querySelector('a')?.textContent === product.name,
  )
  if (!item) throw new Error(`Expected tile for ${product.name}`)
  return item
}

function addButton(scope: Element): HTMLButtonElement {
  const button = scope.querySelector<HTMLButtonElement>(
    'button[data-id="shop-category-add-to-cart"]',
  )
  if (!button) throw new Error('Expected the category Add to cart button')
  return button
}

function cartLink(scope: Element): HTMLAnchorElement | null {
  return scope.querySelector('a[data-id="shop-category-go-to-cart"]')
}

function tableAddButton(product: CategoryProductRow): HTMLButtonElement {
  const row = Array.from(container.querySelectorAll('tbody tr')).find(
    (candidate) => candidate.querySelector('a')?.textContent === product.name,
  )
  if (!row) throw new Error(`Expected table row for ${product.name}`)
  return addButton(row)
}

describe('category cart action', () => {
  it('names the Add control and disables it for unknown or unavailable rows', async () => {
    await render(unique)
    const button = addButton(tile(unique))
    expect(button.getAttribute('aria-label')).toBe(
      'Add to cart · Summer bouquet',
    )
    expect(button.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(button.disabled).toBe(true)

    await act(async () => cart.set([uniqueLine]))
    for (const row of [
      { ...unique, units: 0 },
      { ...unique, availability: 'sold' },
      { ...unique, availability: 'in_stock' },
      { ...stock, availability: 'free' },
      { ...stock, units: 0 },
      { ...stock, kind: 'future' },
      { ...unique, id: 0 },
      { ...unique, id: -2 },
      { ...unique, id: 2.5 },
      { ...unique, id: Number.NaN },
    ]) {
      await render(row)
      expect(cartLink(tile(row))).toBeNull()
      expect(addButton(tile(row)).disabled).toBe(true)
    }
  })

  it('uses the personal snapshot for unique links and preserves focus only from the replaced button', async () => {
    await act(async () => cart.set([]))
    await render(unique, stock)
    addButton(tile(unique)).focus()
    expect(document.activeElement).toBe(addButton(tile(unique)))

    await act(async () => cart.set([uniqueLine, stockLine]))
    const link = cartLink(tile(unique))
    expect(link?.getAttribute('aria-label')).toBe('In cart · Summer bouquet')
    expect(link?.getAttribute('href')).toBe('/cart')
    expect(document.activeElement).toBe(link)
    expect(cartLink(tile(stock))).toBeNull()
    expect(addButton(tile(stock)).disabled).toBe(false)

    await act(async () => cart.set([]))
    expect(cartLink(tile(unique))).toBeNull()
    expect(addButton(tile(unique)).disabled).toBe(false)
    addButton(tile(stock)).focus()
    await act(async () => cart.set([uniqueLine, stockLine]))
    expect(document.activeElement).toBe(addButton(tile(stock)))
    await act(async () => cart.set(null))
    expect(cartLink(tile(unique))).toBeNull()
    expect(addButton(tile(unique)).disabled).toBe(true)
  })

  it('keeps the same product busy in both views and releases it after a refusal', async () => {
    const action = 'category_add_to_cart'
    let refuse!: (error: ActionError) => void
    const done = new Promise<ActionResult>((_resolve, reject) => {
      refuse = reject
    })
    const dispatch = vi
      .spyOn(actions, 'dispatch')
      .mockReturnValueOnce({
        requestId: 'add-1',
        loading: createSignal(false),
        done,
      })
      .mockReturnValue({
        requestId: 'add-2',
        loading: createSignal(false),
        done: Promise.resolve({}),
      })

    await act(async () => cart.set([]))
    await render(unique, stock)
    const tileAdd = addButton(tile(unique))
    await act(async () => {
      tileAdd.click()
      tileAdd.click()
    })
    expect(dispatch).toHaveBeenCalledExactlyOnceWith(action, {
      productId: unique.id,
    })
    expect(tileAdd.getAttribute('aria-busy')).toBe('true')

    const tableView = Array.from(container.querySelectorAll('button')).find(
      (button) => button.textContent === TABLE_VIEW_COPY.table,
    )
    if (!tableView) throw new Error('Expected the Table view control')
    await act(async () => tableView.click())
    expect(tableView.getAttribute('aria-pressed')).toBe('true')
    const tableAdd = tableAddButton(unique)
    expect(tableAdd.getAttribute('aria-busy')).toBe('true')
    await act(async () => tableAdd.click())
    expect(dispatch).toHaveBeenCalledTimes(1)

    await act(async () => tableAddButton(stock).click())
    expect(dispatch).toHaveBeenLastCalledWith(action, {
      productId: stock.id,
    })
    expect(hilosToasts.toasts.get()).toEqual([])
    expect(tableAdd.getAttribute('aria-busy')).toBe('true')

    const reason = 'This product is no longer available.'
    await act(async () => refuse(new ActionError(action, 'fail', reason)))
    expect(tableAdd.hasAttribute('aria-busy')).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([
      expect.objectContaining({ message: reason, severity: 'error' }),
    ])

    await act(async () => tableAdd.click())
    expect(dispatch).toHaveBeenCalledTimes(3)
    expect(dispatch).toHaveBeenLastCalledWith(action, {
      productId: unique.id,
    })
  })
})
