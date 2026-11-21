import { describe, expect, it } from 'vitest'
import { Scope, ingest, type ListItemFragment } from '@hilos/core/base'

import {
  MAIN_CATEGORIES_LIST,
  MAIN_PRODUCTS_LIST,
  buildHomeTiles,
  productCountLabel,
} from '../../../src/views/Main/homeTiles.js'
import { type CategoryTileItem } from '../../../src/views/Main/types/lists/CategoryTileItem.js'

/**
 * A categories list item carrying one category, as the wire sends it.
 *
 * @param id The category id, also the item key.
 * @param name The category name.
 * @param position The category catalog position.
 */
function category(
  id: number,
  name: string,
  position: number,
): ListItemFragment {
  return {
    itemKey: id,
    slots: {
      categories: {
        id,
        name,
        description: `About ${name}`,
        slug: name.toLowerCase(),
        position,
        tilePicture: `/images/${id}.webp`,
      },
    },
  }
}

/**
 * A products list item carrying one product, as the wire sends it.
 *
 * @param id The product id, also the item key.
 * @param categoryId The category the product is in.
 * @param onShopFront Whether the product stands on the shop front.
 */
function product(
  id: number,
  categoryId: number,
  onShopFront: boolean,
): ListItemFragment {
  return {
    itemKey: id,
    slots: { products: { id, categoryId, onShopFront } },
  }
}

/**
 * Ingest the home's two lists into a page scope, as the page binder does, and
 * build the tiles from what the scope then holds.
 *
 * @param categories The categories list items.
 * @param products The products list items.
 */
function tilesOf(
  categories: ListItemFragment[],
  products: ListItemFragment[],
): CategoryTileItem[] {
  const scope = new Scope('page', 'main')
  ingest(scope, {
    lists: {
      [MAIN_CATEGORIES_LIST]: { items: categories },
      [MAIN_PRODUCTS_LIST]: { items: products },
    },
  })

  return buildHomeTiles(
    scope.lists.signal(MAIN_CATEGORIES_LIST).get(),
    scope.lists.signal(MAIN_PRODUCTS_LIST).get(),
    (ref) => scope.entities.signal(ref).get()?.fields,
  )
}

describe('buildHomeTiles', () => {
  it('orders the tiles by position, and equal positions by id', () => {
    const tiles = tilesOf(
      [
        category(9, 'Pots', 2),
        category(7, 'Wreaths', 1),
        category(2, 'Bouquets', 1),
      ],
      [],
    )

    expect(tiles.map((tile) => tile.name)).toEqual([
      'Bouquets',
      'Wreaths',
      'Pots',
    ])
  })

  it('reads the category record into the tile', () => {
    expect(tilesOf([category(2, 'Bouquets', 1)], [])).toEqual([
      {
        key: '2',
        name: 'Bouquets',
        description: 'About Bouquets',
        slug: 'bouquets',
        picture: '/images/2.webp',
        productCount: 0,
      },
    ])
  })

  it('counts only the products of its own category on the shop front', () => {
    const tiles = tilesOf(
      [category(2, 'Bouquets', 1), category(7, 'Wreaths', 2)],
      [
        product(11, 2, true),
        product(12, 2, true),
        product(13, 2, false),
        product(14, 7, true),
        product(15, 9, true),
      ],
    )

    expect(tiles.map((tile) => tile.productCount)).toEqual([2, 1])
  })

  it('gives a category without products zero', () => {
    const tiles = tilesOf(
      [category(2, 'Bouquets', 1), category(9, 'Pots', 2)],
      [product(11, 2, true)],
    )

    expect(tiles.map((tile) => tile.productCount)).toEqual([1, 0])
  })
})

describe('productCountLabel', () => {
  it('says one product in the singular', () => {
    expect(productCountLabel(1)).toBe('1 product')
  })

  it('says zero and many products in the plural', () => {
    expect(productCountLabel(0)).toBe('0 products')
    expect(productCountLabel(2)).toBe('2 products')
  })
})
