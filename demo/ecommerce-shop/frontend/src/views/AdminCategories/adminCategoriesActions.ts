// The categories admin actions: mutations dispatched as tracked actions over the
// connection. An added category returns separately over the live categories
// table.
import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import {
  catalogPictureReplySchema,
  type CatalogPictureReply,
} from '../../catalog/catalogPictureReply.js'
import { type CategoryForm } from './categoryAddDraft.js'

/** Action name on the server (EcommerceShopSignalConstants::CATEGORY_MOVE_UP). */
export const CATEGORY_MOVE_UP = 'category_move_up'

/** Action name on the server (EcommerceShopSignalConstants::CATEGORY_CREATE). */
export const CATEGORY_CREATE = 'category_create'

/** Action name on the server (EcommerceShopSignalConstants::CATEGORY_UPDATE). */
export const CATEGORY_UPDATE = 'category_update'

/**
 * Action name on the server
 * (EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH).
 */
export const CATEGORY_PICTURE_PUBLISH = 'category_picture_publish'

/** What publishing a category picture answers. */
export type CategoryPicturePublishReply = CatalogPictureReply

/**
 * Move a category one place up in the catalog.
 *
 * @param id The category id to move up (server payload key ObjectCategory::id).
 */
export function sendCategoryMoveUp(id: number): ActionHandle {
  // Payload key `id` corresponds to server-side ObjectCategory::id.
  return actions.dispatch(CATEGORY_MOVE_UP, { id })
}

/**
 * Add a category with its published picture. The texts go raw: the server
 * trims and checks them.
 *
 * @param draft The name, description and picture the dialog collected; the
 *   picture is published.
 * @throws Error When the draft holds no picture yet.
 */
export function sendCategoryCreate(draft: CategoryForm): ActionHandle {
  if (draft.picture === null) {
    throw new Error('A category is added with a published picture.')
  }
  // Payload keys `name`, `description` and `pictureFileId` correspond to
  // server-side CategoryCreateActionDTO, read as ObjectCategory::name /
  // ::description and the published file's id.
  return actions.dispatch(CATEGORY_CREATE, {
    name: draft.name,
    description: draft.description,
    pictureFileId: draft.picture.fileId,
  })
}

/** The values sent to CategoryUpdateActionDTO, including the live comparison. */
interface CategoryUpdatePayload {
  readonly id: number
  readonly name: string
  readonly description: string
  readonly pictureFileId: number | null
  readonly expectedName: string
  readonly expectedDescription: string
  readonly expectedPictureFileId: number
}

/**
 * Save the category draft if the server still holds the expected values.
 *
 * @param payload The draft and the values it expects, with null keeping the
 *   saved picture.
 */
export function sendCategoryUpdate(
  payload: CategoryUpdatePayload,
): ActionHandle {
  return actions.dispatch(CATEGORY_UPDATE, { ...payload })
}

/**
 * Publish an uploaded picture of the catalog, ready for a category to take.
 *
 * @param clientUploadId Id the browser gave the received upload.
 */
export function sendCategoryPicturePublish(
  clientUploadId: string,
): ActionHandle<CategoryPicturePublishReply> {
  // Payload key `clientUploadId` corresponds to server-side
  // CategoryPicturePublishActionDTO::clientUploadId.
  return actions.dispatch(
    CATEGORY_PICTURE_PUBLISH,
    { clientUploadId },
    { replySchema: catalogPictureReplySchema },
  )
}
