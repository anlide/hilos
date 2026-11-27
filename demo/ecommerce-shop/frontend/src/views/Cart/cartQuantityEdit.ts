import {
  computedSignal,
  createHilosRowEdit,
  createSignal,
  hilosSignalRowEditSource,
  subscribeSignal,
  type HilosConnection,
  type HilosTrackedRowEdit,
  type ReadonlySignal,
} from '@hilos/core/base'

import {
  cartIdentity,
  type CartLine,
  type CartSnapshot,
} from '../../types/Cart.js'
import { sendCartSetQuantity } from './cartActions.js'

interface QuantityDraft {
  readonly quantity: string
}

/** The exact-quantity window over one confirmed cart line. */
export interface CartQuantityEdit extends Omit<
  HilosTrackedRowEdit<CartLine, QuantityDraft>,
  'open' | 'start' | 'dispose'
> {
  /** The most recent confirmed line, including its stock hint. */
  readonly live: ReadonlySignal<CartLine | undefined>
  /**
   * Open the editor for a stock line of the current cart.
   *
   * @param lineId The line's primary key.
   */
  open(lineId: number): boolean
  /** Start following the cart and its owner, returning the mount cleanup. */
  start(): () => void
}

/**
 * Compare decimal quantities without changing the text sent to the server.
 *
 * @param draft The quantity as entered.
 */
function comparableQuantity(draft: string): string {
  const match = /^[ \t\r\n\v\0]*(-?)([0-9]+)[ \t\r\n\v\0]*$/.exec(draft)
  if (match === null) return draft

  const digits = match[2].replace(/^0+(?=[0-9])/, '')

  return digits === '0' ? digits : `${match[1]}${digits}`
}

/**
 * Keep the edit pinned to the cart and line that opened it.
 *
 * @param cartPage The confirmed Cart page projection.
 * @param currentUserId The tab's current account, or null for a guest.
 * @param connection The tab's session lifecycle.
 */
export function createCartQuantityEdit(
  cartPage: ReadonlySignal<CartSnapshot | null>,
  currentUserId: ReadonlySignal<number | null>,
  connection: Pick<HilosConnection, 'on'>,
): CartQuantityEdit {
  const live = createSignal<CartLine | undefined>(undefined)
  const edit = createHilosRowEdit<CartLine, QuantityDraft>(
    hilosSignalRowEditSource(live),
    {
      initial: { quantity: '' },
      fields: (row) => ({ quantity: String(row.quantity) }),
      draft: ({ quantity }) => ({ quantity: comparableQuantity(quantity) }),
      valid: ({ quantity }) => quantity !== '',
      notice: {
        conflict: (state) =>
          `Quantity changed elsewhere to ${state.fields.quantity.incoming}.`,
      },
    },
  )
  let owner: string | null = null
  const ready = computedSignal(() => {
    const snapshot = cartPage.get()

    return (
      edit.opened.get() &&
      snapshot !== null &&
      snapshot.userId === currentUserId.get() &&
      cartIdentity(snapshot) === owner
    )
  })
  const saveAvailable = computedSignal(
    () => ready.get() && edit.saveAvailable.get(),
  )
  const canSave = computedSignal(() => ready.get() && edit.canSave.get())

  return {
    ...edit,
    live,
    saveAvailable,
    canSave,
    open(lineId) {
      const snapshot = cartPage.get()
      if (
        edit.opened.get() ||
        snapshot === null ||
        snapshot.userId !== currentUserId.get()
      )
        return false

      const line = snapshot.lines.find((row) => row.lineId === lineId)
      if (line?.kind !== 'stock') return false

      owner = cartIdentity(snapshot)
      live.set(line)

      return edit.open()
    },
    keepMine() {
      if (!edit.saving.get()) edit.keepMine()
    },
    takeTheirs() {
      if (!edit.saving.get()) edit.takeTheirs()
    },
    async save(run) {
      const line = live.get()
      if (!canSave.get() || line === undefined) return

      // The session raises saving before dispatch. With conflicts resolved,
      // the latest confirmed quantity is the accepted basis for this request.
      await edit.save(() =>
        run(
          sendCartSetQuantity(
            line.lineId,
            currentUserId.get() === null ? 'guest' : 'account',
            edit.form.get().quantity,
            line.quantity,
          ),
        ),
      )
    },
    start() {
      edit.start()
      const stopCart = subscribeSignal(cartPage, (snapshot) => {
        // A missing snapshot suspends saving and keeps the last known row.
        if (snapshot === null || !edit.opened.get()) return
        if (cartIdentity(snapshot) !== owner) {
          edit.close()

          return
        }

        const row = edit.row.get()
        if (row)
          live.set(snapshot.lines.find((line) => line.lineId === row.lineId))
      })
      const stopOwner = subscribeSignal(currentUserId, edit.close)
      const stopRotation = connection.on('sessionRotate', edit.close)

      return () => {
        stopCart()
        stopOwner()
        stopRotation()
        edit.dispose()
      }
    },
  }
}
