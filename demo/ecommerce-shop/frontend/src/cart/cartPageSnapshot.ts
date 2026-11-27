import {
  SIGNAL_HANDSHAKE_RESPONSE,
  computedSignal,
  createSignal,
  subscribeSignal,
  type HilosConnection,
  type ReadonlySignal,
  type ScopeManager,
} from '@hilos/core/base'

import { cartSnapshotSchema, type CartSnapshot } from '../types/Cart.js'

/**
 * Follow fresh cart snapshots from one page in this tab. Bind before boot so
 * the first answer and identity changes are observed while the view is unmounted.
 *
 * @param scopes The application's page scope manager.
 * @param currentUserId The signed-in person, or null for a guest.
 * @param connection The tab's connection and session lifecycle events.
 * @param pageKey The page that supplies the cart snapshot.
 */
export function createCartPageSignal(
  scopes: ScopeManager,
  currentUserId: ReadonlySignal<number | null>,
  connection: Pick<HilosConnection, 'on' | 'state'>,
  pageKey: string,
): ReadonlySignal<CartSnapshot | null> {
  const activeCartData = computedSignal(() => {
    const scope = scopes.page()

    return scope?.key === pageKey
      ? scope.data.entrySignal('cart').get()
      : undefined
  })
  const snapshot = createSignal<CartSnapshot | null>(null)
  const clear = () => snapshot.set(null)
  let rotating = false

  // An owner change clears the projection without re-reading the slot. An old
  // guest answer can still be there when the user returns to being a guest.
  subscribeSignal(currentUserId, clear)
  subscribeSignal(activeCartData, (entry) => {
    if (entry === undefined) {
      clear()
      return
    }
    if (rotating || connection.state !== 'connected') return

    const result = cartSnapshotSchema.safeParse(entry.value)
    if (result.success && result.data.userId === currentUserId.get()) {
      snapshot.set(
        entry.pageSubscriptionProof === undefined
          ? result.data
          : {
              ...result.data,
              pageSubscriptionProof: entry.pageSubscriptionProof,
            },
      )
    }
  })
  connection.on('projectSignal', (signal) => {
    // A guest's answer has no session ID. The server re-answers the page for
    // the session established by the handshake.
    if (
      signal.type === SIGNAL_HANDSHAKE_RESPONSE &&
      currentUserId.get() === null
    ) {
      clear()
    }
  })
  connection.on('sessionRotate', () => {
    // The old socket can still be finishing its outstanding replies.
    rotating = true
    clear()
  })
  connection.on('state', (state) => {
    if (state !== 'connected') {
      rotating = false
      clear()
    }
  })

  return snapshot
}
