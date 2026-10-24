// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'

/** Server→initiator signal `type` carrying one restore runtime snapshot (PHP `BACKUP_RESTORE_PROGRESS`). */
export const BACKUP_RESTORE_PROGRESS_SIGNAL = 'backup_restore_progress'

/**
 * Payload of one restore progress frame — the restore runtime row as the agent
 * photographs it, key for key with what the CLI monitor is answered
 * (PHP `BackupRestoreProgressSignalData`).
 * `phaseStartedAt`, `startedAt` and `finishedAt` are ISO-8601 UTC moments ending
 * in Z, or null when absent. `estimatedSeconds` is a duration in seconds.
 */
export const restoreProgressSchema = z.looseObject({
  running: z.boolean(),
  backupId: z.string().nullable(),
  scope: z.string().nullable(),
  phase: z.string().nullable(),
  phaseStartedAt: z.string().nullable(),
  startedAt: z.string().nullable(),
  finishedAt: z.string().nullable(),
  outcome: z.string().nullable(),
  failureReason: z.string().nullable(),
  estimatedSeconds: z.number().nullable(),
  rehydrateComplete: z.boolean(),
  rehydrateProblems: z.array(z.string()),
  databaseTouched: z.boolean(),
})

/** One restore progress frame: where the run is, and how it ended. */
export type HilosRestoreStatus = z.infer<typeof restoreProgressSchema>

/**
 * The backup signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the restore frames {@link createHilosRestoreProgress} ingests.
 * {@link createHilosConnection} merges them in, so a project never restates them.
 */
export const BACKUP_SIGNAL_SCHEMAS = {
  [BACKUP_RESTORE_PROGRESS_SIGNAL]: restoreProgressSchema,
}
