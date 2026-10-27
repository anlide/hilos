// Eager wire declarations have no dependency on the session reader that
// registers them. The held profile module reexports these same values/types.
import { z } from 'zod'

/** PHP `HilosSignalConstants::HILOS_PROFILE_NAME_STATE`, addressed to one session. */
export const SIGNAL_PROFILE_NAME_STATE = 'hilos_profile_name_state'

const profileNameChangeSchema = z.discriminatedUnion('phase', [
  z.strictObject({
    requestId: z.string(),
    name: z.string(),
    phase: z.enum(['checking', 'applying', 'done']),
    refusal: z.null(),
  }),
  z.strictObject({
    requestId: z.string(),
    name: z.string(),
    phase: z.literal('refused'),
    refusal: z.string().min(1),
  }),
])

/** Exact public `ProfileNameStateSignalData` payload, including explicit nulls. */
export const profileNameStateSchema = z
  .strictObject({
    userId: z.number().int().nullable(),
    change: profileNameChangeSchema.nullable(),
  })
  .refine((snapshot) => snapshot.userId !== null || snapshot.change === null)

/** One complete attempt, with server words only on a refused outcome. */
export type HilosProfileNameChange = Readonly<
  z.infer<typeof profileNameChangeSchema>
>

/** One complete server answer; `change: null` is a known absence of an attempt. */
export interface HilosProfileNameState {
  readonly userId: number | null
  readonly change: HilosProfileNameChange | null
}
