// Frozen runtime data as the client sees it: on a cluster, part of what a page
// reads may be a copy of another node's, and when the link to that node drops the
// copy is still served and no longer kept up to date (HIL-711). The backend answers
// this connection whether anything its own page reads is in that state, and since
// when; the shell turns that into one mark beside the connection indicator.
import { z } from 'zod'

import { toLocal } from '../session/serverClock.js'

/**
 * The frozen-replica block as it rides the wire (PHP `RtStalenessSignalData`).
 * Loose on the moment — it is null exactly when nothing is frozen — but `stale` is
 * required, because a block that cannot say whether anything is frozen carries no
 * information at all.
 */
export const rtStalenessBlockSchema = z.looseObject({
  stale: z.boolean(),
  since: z.number().nullish(),
})

export type RtStalenessBlock = z.infer<typeof rtStalenessBlockSchema>

/**
 * Frozen-replica state held by the connection: whether anything the open page reads
 * has an unreachable source and, if it has, the server moment the oldest of it
 * stopped being kept up to date. The wire's null is normalized to `undefined` so a
 * consumer has one absent value to test rather than two.
 *
 * The verdict is over the WHOLE page, decided on the backend: a page reading two
 * collections stays affected while either one of them is frozen, and this side does
 * no filtering of its own. It is connection state rather than page state for the
 * same reason the protected mode is — the server already sent it only to the
 * connections it concerns.
 */
export interface RtStalenessStatus {
  readonly stale: boolean
  readonly since: number | undefined
}

/** Nothing frozen: the state every connection starts in. */
export const RT_STALENESS_FRESH: RtStalenessStatus = {
  stale: false,
  since: undefined,
}

/**
 * The words the mark carries — its tooltip, and its text for a screen reader.
 *
 * Authored on the frontend, exactly as `PROTECTED_MODE_PASS_COPY` next door and for
 * the same reason: a second mandatory entry in the backend stub registry would make
 * every project declare a string in order to reword one sentence. Kept in the core
 * so the three view packages cannot drift into three different sentences.
 *
 * `{time}` is replaced with the moment in the reader's own timezone. The wire
 * carries a server moment, so the substitution goes through `serverClock`.
 */
export const RT_STALENESS_COPY = {
  frozen:
    'Some data here may be out of date - the link to its source was lost at {time}.',
} as const

/**
 * The sentence the mark shows, with the moment in the reader's own timezone.
 *
 * Here rather than in each view package so the three shells cannot drift into
 * three different sentences, and so the one conversion this needs happens once:
 * the wire carries a server moment, and `serverClock` is where server time meets
 * the scale a browser can format.
 *
 * @param status The connection's frozen-replica state.
 * @returns The sentence, or undefined when nothing is frozen and there is none.
 */
export function rtStalenessLabel(
  status: RtStalenessStatus,
): string | undefined {
  if (!status.stale) {
    return undefined
  }

  // A frozen state with no moment should not happen — the backend sends the two
  // together — but a mark with a broken sentence in it is worse than one without
  // a time, so the placeholder falls back to the words rather than to `NaN`.
  const time =
    status.since === undefined
      ? 'an unknown time'
      : new Date(toLocal(status.since)).toLocaleTimeString()

  return RT_STALENESS_COPY.frozen.replace('{time}', time)
}

/**
 * Normalize a parsed wire block into the state the connection holds.
 *
 * @param block The parsed block, or undefined when the frame carried none.
 */
export function toRtStalenessStatus(
  block: RtStalenessBlock | undefined,
): RtStalenessStatus {
  if (block === undefined || !block.stale) {
    return RT_STALENESS_FRESH
  }

  return {
    stale: true,
    since: block.since ?? undefined,
  }
}
