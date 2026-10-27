// The name window's tab-local intent and unsaved edit data. The server's held
// STATE owns the attempt; this record grants neither a send nor a step-up proof.
// browserStorage is the existing browser persistence boundary; Hilos::$rt keeps
// the shared operation, not a tab's visibility or draft (framework-first.md).
import { z } from 'zod'
import { browserStorage } from '../browser/browserStorage.js'
import { browserValue } from '../browser/browserValue.js'
import { type HilosRowEditCheckpoint } from '../conflict/rowEditSession.js'
import {
  type HilosProfileRenameFields,
  type HilosProfileRenameStep,
} from './profileRename.js'

/** The one sessionStorage key for this tab's name window. */
export const PROFILE_RENAME_MEMORY_STORAGE_KEY = 'hilos.profile.rename.window'

/** Declared beside the write so the ordinary privacy sweep erases it. */
export const PROFILE_RENAME_BROWSER_VALUE = browserValue({
  store: 'session',
  key: PROFILE_RENAME_MEMORY_STORAGE_KEY,
  label: 'Name change window',
})

/** Untrusted window data; the caller must match userId to authoritative identity. */
export interface HilosProfileRenameMemory {
  readonly version: 1
  readonly userId: number
  /** Visibility intent, preserved on disposal and set false only on explicit close. */
  readonly open: boolean
  /** A presentation step, never evidence that confirmation was granted. */
  readonly step: HilosProfileRenameStep
  /** Exact unsaved input, including text that cannot currently be saved. */
  readonly draft: string
  /** Detached merge data, without the session's local save permission. */
  readonly checkpoint: HilosRowEditCheckpoint<
    HilosProfileRenameFields,
    string
  > | null
  /** For comparison with current STATE only; never permission to resend. */
  readonly requestId: string | null
}

/** Exact versioned data boundary, rejecting secrets and server outcomes at every level. */
export const profileRenameMemorySchema = z.strictObject({
  version: z.literal(1),
  userId: z.number().int().positive(),
  open: z.boolean(),
  step: z.enum(['closed', 'step-up', 'form']),
  draft: z.string(),
  checkpoint: z
    .strictObject({
      baseline: z.strictObject({
        values: z.strictObject({ name: z.string() }),
        refreshed: z.array(z.literal('name')),
      }),
      form: z.string(),
      awaitingOccupation: z.boolean(),
    })
    .nullable(),
  requestId: z.string().min(1).nullable(),
}) satisfies z.ZodType<HilosProfileRenameMemory>

/** Read validated data, or null for an absent, corrupt or inaccessible store. */
export function readProfileRenameMemory(): HilosProfileRenameMemory | null {
  const storage = browserStorage('sessionStorage')
  try {
    const raw = storage?.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)
    if (raw === null || raw === undefined) return null
    const parsed = profileRenameMemorySchema.safeParse(JSON.parse(raw))

    return parsed.success ? parsed.data : null
  } catch {
    return null
  }
}

/**
 * Persist only validated data. An unavailable store leaves the live window usable.
 *
 * @param memory The tab's intent and edit data, with no server authority.
 */
export function writeProfileRenameMemory(
  memory: HilosProfileRenameMemory,
): void {
  const storage = browserStorage('sessionStorage')
  try {
    const parsed = profileRenameMemorySchema.safeParse(memory)
    if (!parsed.success) return
    storage?.setItem(
      PROFILE_RENAME_MEMORY_STORAGE_KEY,
      JSON.stringify(parsed.data),
    )
  } catch {
    // Remembering a draft is optional; a denied store must not break the flow.
  }
}

/** Forget the whole checkpoint on authoritative sign-out, person change or erase. */
export function clearProfileRenameMemory(): void {
  const storage = browserStorage('sessionStorage')
  try {
    storage?.removeItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)
  } catch {
    // The live reader still discards its data when storage refuses removal.
  }
}
