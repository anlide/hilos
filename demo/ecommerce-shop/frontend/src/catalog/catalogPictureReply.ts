import { z } from '@hilos/core/base'

// Reply keys match CatalogPicturePublishReplyDTO::fileId and ::picture.
export const catalogPictureReplySchema = z.object({
  fileId: z.number().int().positive(),
  picture: z.string().min(1),
})

/** The published file and its catalog_sm address on the wire. */
export type CatalogPictureReply = z.infer<typeof catalogPictureReplySchema>

/** A published catalog picture held by a dialog draft. */
export interface CatalogPicture {
  /** The file the new entity will bind. */
  readonly fileId: number
  /** The catalog_sm copy shown in the preview. */
  readonly url: string
}
