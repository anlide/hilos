import { readFile, rename, unlink, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import process from 'node:process'
import { setTimeout as delay } from 'node:timers/promises'

/** The file channel exists only inside the isolated test runner's run mount. */
export function standaloneStatusControl() {
  const directory = process.env.STATUS_CONTROL_DIR
  const runId = process.env.STATUS_RUN_ID
  if (!directory || !runId)
    throw new Error('Standalone Status requires its run-scoped fixture')
  let sequence = 0
  return {
    directory,
    /** @param {'stop' | 'start' | 'inspect'} operation The sole pinned daemon operation. */
    async request(operation) {
      sequence++
      const temporary = join(directory, 'request.tmp')
      await writeFile(temporary, JSON.stringify({ runId, sequence, operation }))
      await rename(temporary, join(directory, 'request.json'))
      const deadline = Date.now() + 30_000
      while (Date.now() < deadline) {
        try {
          const response = JSON.parse(
            await readFile(join(directory, 'response.json'), 'utf8'),
          )
          await unlink(join(directory, 'response.json'))
          if (response.sequence !== sequence)
            throw new Error('Foreign standalone Status response')
          if (response.error) throw new Error(response.error)
          return response.result
        } catch (error) {
          if (/** @type {NodeJS.ErrnoException} */ (error).code !== 'ENOENT')
            throw error
        }
        await delay(50)
      }
      throw new Error('Standalone Status control did not answer')
    },
  }
}
