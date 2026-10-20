// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'

/**
 * Server→client (WS_USER): the standing of the person a card shows, whenever it
 * changed (PHP `HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE`, HIL-945).
 */
export const SIGNAL_ACCOUNT_STANDING_STATE = 'hilos_account_standing_state'

/**
 * The live standing frame (PHP `AccountStandingStateSignalData`). Both members
 * are read past the parse boundary rather than at it: a viewer of the admin
 * view mode is sent hidden marks in their place, and such a frame is still one
 * to take — it says the standing cannot be read ({@link readHilosAccountStanding}).
 */
export const accountStandingStateSchema = z.looseObject({
  userId: z.unknown(),
  accountStanding: z.unknown(),
})

/**
 * The live standing frame keyed for a connection's `projectSchemas`.
 * {@link createHilosConnection} merges it in.
 */
export const ACCOUNT_STANDING_SIGNAL_SCHEMAS = {
  [SIGNAL_ACCOUNT_STANDING_STATE]: accountStandingStateSchema,
}
