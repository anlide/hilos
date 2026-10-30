import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * Hold an exact declared revision through the existing test-only command.
 *
 * @param {number} userId Test-owned person whose holding changes.
 * @param {string} document Declared document key.
 * @param {string} revisionId Exact revision to hold or restore.
 * @returns {Promise<{userId: number, document: string, revisionId: string, standing: 'none'|'covered'|'window'|'lapsed', deadline: string|null, frozen: boolean}>}
 * @throws {Error} When the command refuses or returns an invalid holding.
 */
export async function holdLegalRevision(userId, document, revisionId) {
  const reply = await sendCommand('test:legal:hold', {
    userId,
    document,
    revisionId,
  })
  if (
    reply.userId !== userId ||
    reply.document !== document ||
    reply.revisionId !== revisionId ||
    !['none', 'covered', 'window', 'lapsed'].includes(String(reply.standing)) ||
    !(reply.deadline === null || typeof reply.deadline === 'string') ||
    typeof reply.frozen !== 'boolean'
  ) {
    throw new Error('Invalid test:legal:hold reply')
  }

  return {
    userId,
    document,
    revisionId,
    standing: /** @type {'none'|'covered'|'window'|'lapsed'} */ (
      reply.standing
    ),
    deadline: /** @type {string|null} */ (reply.deadline),
    frozen: reply.frozen,
  }
}
