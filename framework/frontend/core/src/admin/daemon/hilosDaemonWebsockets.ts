import { z } from 'zod'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** Page-data key of the addressed node's WebSocket count. */
export const DAEMON_WEBSOCKETS_DATA = 'websockets'

export const daemonWebsocketsSchema = z
  .strictObject({
    connections: z.number().int().nonnegative().nullable(),
    dataNode: z.boolean(),
  })
  .readonly()

export type HilosDaemonWebsocketsData = z.infer<typeof daemonWebsocketsSchema>

/** @param scopes Page scopes filled centrally by bindPageScope. */
export function readHilosDaemonWebsockets(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonWebsocketsData | null> {
  const source = scopes.pageDataSignal(DAEMON_WEBSOCKETS_DATA)
  return computedSignal(() => {
    const parsed = daemonWebsocketsSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
