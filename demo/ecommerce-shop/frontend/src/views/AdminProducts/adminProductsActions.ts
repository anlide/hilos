import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import {
  catalogPictureReplySchema,
  type CatalogPictureReply,
} from '../../catalog/catalogPictureReply.js'
import { type ProductCreatePayload } from './productAddDraft.js'

/** EcommerceShopSignalConstants::PRODUCT_CREATE. */
export const PRODUCT_CREATE = 'product_create'

/** EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH. */
export const PRODUCT_PICTURE_PUBLISH = 'product_picture_publish'

/**
 * Add a product with its published picture and price as typed in hryvnias.
 *
 * @param payload The fields collected by the Add product dialog.
 */
export function sendProductCreate(payload: ProductCreatePayload): ActionHandle {
  return actions.dispatch(PRODUCT_CREATE, { ...payload })
}

/**
 * Publish an upload so that the product can bind it.
 *
 * @param clientUploadId The id the browser gave the received upload.
 */
export function sendProductPicturePublish(
  clientUploadId: string,
): ActionHandle<CatalogPictureReply> {
  // ProductPicturePublishActionDTO::clientUploadId.
  return actions.dispatch(
    PRODUCT_PICTURE_PUBLISH,
    { clientUploadId },
    { replySchema: catalogPictureReplySchema },
  )
}
