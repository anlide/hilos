import { z } from 'zod'

/**
 * Signal `type` of the initiator's frame to one connection: whether it waits on a check of one
 * kind (PHP `HilosSignalConstants::HILOS_MODERATION_CHECK`).
 */
export const SIGNAL_MODERATION_CHECK = 'hilos_moderation_check'

/** Moderation kind of a person's profile photo (PHP `ModerationKindKey::PROFILE_PHOTO`). */
export const PROFILE_PHOTO_KIND = 'hilos_profile_photo'

/** Check progress payload (PHP `ModerationCheckSignalData`). */
export const moderationCheckSchema = z.looseObject({
  kind: z.string(),
  checking: z.boolean(),
})
