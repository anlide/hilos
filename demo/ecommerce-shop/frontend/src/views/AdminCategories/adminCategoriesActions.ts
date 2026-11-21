// The categories admin actions: mutations dispatched as tracked actions over the
// connection.
import type { ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Action name on the server (EcommerceShopSignalConstants::CATEGORY_MOVE_UP). */
export const CATEGORY_MOVE_UP = 'category_move_up'

/**
 * Move a category one place up in the catalog.
 *
 * @param id The category id to move up (server payload key ObjectCategory::id).
 */
export function sendCategoryMoveUp(id: number): ActionHandle {
  // Payload key `id` corresponds to server-side ObjectCategory::id.
  return actions.dispatch(CATEGORY_MOVE_UP, { id })
}
