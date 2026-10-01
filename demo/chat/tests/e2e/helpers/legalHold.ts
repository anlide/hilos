import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:legal:hold command
// speaks (HIL-324). The Playwright runner has no PHP, so the e2e holds a person
// on a revision over the wire directly; the master parks the request for the
// users library, which rewrites the person's acceptance records and publishes
// their agreements state.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Unlike the admin view mode, the reply waits for an agent: the users library
// writes the records inside a transaction and only then answers.
const REPLY_TIMEOUT_MS = 15_000

const LEGAL_HOLD_COMMAND = 'test:legal:hold'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/** What the command answers: the person's standing on the document afterwards. */
export interface LegalHoldStanding {
  userId: number
  document: string
  revisionId: string
  standing: 'none' | 'covered' | 'window' | 'lapsed'
  deadline: string | null
  frozen: boolean
}

/**
 * Holds a person on one revision of one document: their acceptances of later
 * revisions are forgotten and this one is recorded if it is missing (HIL-324).
 * Window, lapse and freeze then follow by calculation - nothing else is set.
 *
 * The person's open tabs learn the new standing on the sessions library's next
 * tick, like after any write of an acceptance. A master or a library that
 * refuses the command rejects the returned promise.
 *
 * @param userId The person held.
 * @param document The document: 'terms' or 'privacy'.
 * @param revisionId The revision the person is held on.
 */
export async function holdLegalRevision(
  userId: number,
  document: string,
  revisionId: string,
): Promise<LegalHoldStanding> {
  return (await sendCommand(LEGAL_HOLD_COMMAND, {
    userId,
    document,
    revisionId,
  })) as unknown as LegalHoldStanding
}
