import { type HilosAdminAccess } from '../../session/adminAccess.js'
import { type HilosAccountMergeProgress } from './hilosUsers.js'

/**
 * Accept the server's projection whole. A viewer must wait for its withheld
 * replacement if the session verdict arrives before the page frame; the browser
 * never manufactures its own field-by-field privacy projection.
 *
 * @param progress The current safe reader result.
 * @param access The live admin-section verdict.
 */
export function readableHilosAccountMergeProgress(
  progress: HilosAccountMergeProgress | null,
  access: HilosAdminAccess,
): HilosAccountMergeProgress | null {
  if (progress === null || access === 'none') return null
  if (access === 'full') return progress
  if (
    progress.operationId !== null ||
    progress.survivorUserId !== null ||
    progress.loserUserId !== null ||
    progress.stage !== null ||
    progress.outcome !== null ||
    progress.choice !== null ||
    progress.refusal !== null ||
    progress.parts.some((part) => part.userId !== null) ||
    progress.waits.some((wait) => wait.userId !== null)
  ) {
    return null
  }
  return progress
}
