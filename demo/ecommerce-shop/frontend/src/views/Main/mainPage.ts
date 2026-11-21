// The home page selector: the page payload's categories and products lists
// resolved into the tiles the page renders. The pure building lives in
// homeTiles.ts so it can also be read without application bootstrap; this file
// only binds it to the session's scopes, which also resolve the lists' entity
// slots to their fields.
import { computedSignal, type ReadonlySignal } from '@hilos/core/base'

import { scopes } from '../../bootstrap/session.js'
import {
  MAIN_CATEGORIES_LIST,
  MAIN_PRODUCTS_LIST,
  buildHomeTiles,
} from './homeTiles.js'
import { type CategoryTileItem } from './types/lists/CategoryTileItem.js'

const categoryItems = scopes.pageListSignal(MAIN_CATEGORIES_LIST)
const productItems = scopes.pageListSignal(MAIN_PRODUCTS_LIST)

/** The home's category tiles in the administrators' order, with live counts. */
export const homeTiles: ReadonlySignal<readonly CategoryTileItem[]> =
  computedSignal(() =>
    buildHomeTiles(
      categoryItems.get(),
      productItems.get(),
      (ref) => scopes.entitySignal(ref).get()?.fields,
    ),
  )
