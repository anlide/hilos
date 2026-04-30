/**
 * Chat-specific augmentation of HilosActionMap (framework).
 *
 * Every action that demo/chat fires via `sendAction()` must be listed here
 * so that call sites get full compile-time checking of the action name and
 * its payload shape.
 *
 * This file has no runtime exports — it only augments the SDK's action map
 * via TypeScript declaration merging. It MUST be imported early (from
 * `main.ts`) so the augmentation is applied before any `sendAction()` call.
 */
import type {} from '@hilos/sdk/types/actionMap'

declare module '@hilos/sdk/types/actionMap' {
  interface HilosActionMap {
    /** Change own display name (pilot for success/fail ack pattern). */
    rename: { newName: string }

    /** Admin update of a chat user (currently: name only). */
    user_update: { id: number; name: string }

    /** Create a new bot from the admin bots page. */
    bot_create: {
      name: string
      description: string
      style: string
      active: boolean
    }

    /** Update an existing bot. */
    bot_update: {
      id: number
      name: string
      description: string
      style: string
      active: boolean
    }

    /** Delete a bot by id. */
    bot_delete: { id: number }

    /** Create a moderator-prompt piece. */
    moderator_piece_create: {
      section: string
      promptPiece: string
    }

    /** Update a moderator-prompt piece. */
    moderator_piece_update: {
      id: number
      section: string
      promptPiece: string
    }

    /** Delete a moderator-prompt piece by id. */
    moderator_piece_delete: { id: number }

    /** Start binary file upload — server responds with file_upload_ready / file_upload_rejected. */
    file_upload_init: {
      filename: string
      mimeType: string
      size: number
      clientUploadId: string
    }

    /** Delete one uploaded attachment draft. */
    attachment_draft_delete: { draftId: string }

    /** Send a chat message with optional uploaded attachment drafts. */
    message: { content: string; attachmentDraftIds: string[] }
  }
}

export {}
