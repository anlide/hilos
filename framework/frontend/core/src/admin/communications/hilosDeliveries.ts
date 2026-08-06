// The framework Hilos delivery-logs admin headless: the delivery-journal
// view-model, the row resolver, the status filter catalog, and the table / retry
// factories the per-framework HilosCommunicationsDeliveriesPage view renders from.
// It is framework-agnostic (imports no UI framework) and reads only @hilos/core
// primitives, so the Vue/React/Angular deliveries views stay thin
// (multiframework-core.md).
//
// The journal is the one notifications table that grows without bound, so its
// backend serves each window straight from SQL (a ViewportTable), not from a
// runtime projection: there are no live per-row deltas, and a refresh is a
// re-request of the window. The channel/status/period filters and the
// type/recipient search ride the open viewport filter map — the per-channel route
// opens the otherwise cross-cutting journal by presetting the channel filter. The
// single row action is retry, valid only on a failed delivery: it dispatches as a
// tracked action and re-queues the delivery on the backend, which returns the
// updated row through the next window (there is no new server->client signal). A
// project supplies a HilosDeliveriesContext and the framework owns the rest.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readNumber, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { hilosToasts } from '../../state/toasts.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** One row of the delivery-logs table — the framework delivery-journal view-model. */
export interface HilosDeliveryRow {
  /** The delivery id; also the table row key. */
  readonly rowKey: string
  /** ISO-8601 creation timestamp. */
  readonly createdAt: string
  /** Delivery channel name (e.g. `mail`). */
  readonly channel: string
  /** Delivery status (`pending` | `sent` | `failed`). */
  readonly status: string
  /** Delivery attempts made. */
  readonly attempts: number
  /** ISO-8601 delivered timestamp, or empty string when not sent. */
  readonly deliveredAt: string
  /** Last failure detail (a domain phrase), or empty string when none. */
  readonly lastError: string
  /** Recipient user id, or null when the notification is gone. */
  readonly userId: number | null
  /** Recipient display label (empty when the project resolves none). */
  readonly userLabel: string
  /** Notification machine type. */
  readonly notificationType: string
  /** Notification title (the body is never shown — it may carry personal detail). */
  readonly notificationTitle: string
}

/** Row payload key of the recipient user id. */
const DELIVERY_USER_ID_FIELD = 'userId'

/** Row payload key of the creation instant (also the table's default sort field). */
export const DELIVERY_CREATED_AT_FIELD = 'createdAt'

/** Row payload key of the delivery channel. */
export const DELIVERY_CHANNEL_FIELD = 'channel'

/** Row payload key of the delivery status. */
export const DELIVERY_STATUS_FIELD = 'status'

/** Row payload key of the attempt count. */
export const DELIVERY_ATTEMPTS_FIELD = 'attempts'

/** Row payload key of the delivered instant. */
export const DELIVERY_DELIVERED_AT_FIELD = 'deliveredAt'

/** Row payload key of the last failure detail. */
export const DELIVERY_LAST_ERROR_FIELD = 'lastError'

/** Row payload key of the recipient display label (also the recipient column key). */
export const DELIVERY_USER_LABEL_FIELD = 'userLabel'

/** Row payload key of the notification machine type. */
const DELIVERY_NOTIFICATION_TYPE_FIELD = 'notificationType'

/** Row payload key of the notification title (also the notification column key). */
export const DELIVERY_NOTIFICATION_TITLE_FIELD = 'notificationTitle'

// Wire keys: the framework delivery-logs table and its single inline `delivery`
// slot, and the retry action name. A project binds its backend to these keys
// (Hilos::TABLES / PAGE_TABLES / the deliveries page action).
const DELIVERIES_TABLE = 'hilosNotificationDeliveries'
const DELIVERY_SLOT = 'delivery'
const DELIVERIES_PAGE_SIZE = 25
const DELIVERY_RETRY_ACTION = 'communications_delivery_retry'
/** The page's own actions, so an addressed failure notice for one is recognized as ours. */
const DELIVERY_ACTIONS = new Set<string>([DELIVERY_RETRY_ACTION])
/** The delivery status a retry is valid for (matches the backend DeliveryStatus::FAILED). */
const DELIVERY_STATUS_FAILED = 'failed'

/** Filter-map key: narrow the journal to one channel (the per-channel route preset). */
export const DELIVERY_FILTER_CHANNEL = 'channel'
/** Filter-map key: narrow to one delivery status. */
export const DELIVERY_FILTER_STATUS = 'status'
/** Filter-map key: inclusive lower bound on created_at (SQL datetime). */
export const DELIVERY_FILTER_FROM = 'from'
/** Filter-map key: inclusive upper bound on created_at (SQL datetime). */
export const DELIVERY_FILTER_TO = 'to'

/** A selectable delivery status: its wire value and a human-readable label. */
export interface HilosDeliveryStatusOption {
  /** The wire status value sent as the status filter (empty clears the filter). */
  readonly value: string
  /** The label shown in the status picker. */
  readonly label: string
}

/**
 * The status options the journal's status picker offers. The empty value is the
 * "all statuses" choice that clears the filter; the rest match the backend
 * DeliveryStatus values (`pending` | `sent` | `failed`).
 */
export const HILOS_DELIVERY_STATUSES: readonly HilosDeliveryStatusOption[] = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'sent', label: 'Sent' },
  { value: DELIVERY_STATUS_FAILED, label: 'Failed' },
]

/**
 * The project-supplied context the delivery-logs admin reads from: the
 * scope-partitioned stores that own the page-scoped journal table, the live
 * connection the table sends its viewport over, and the action lifecycle the
 * retry tracked action dispatches over. Everything else (the table key, slot name,
 * view-model, filters, and behavior) is the framework's; the journal is served on
 * its backend.
 */
export interface HilosDeliveriesContext {
  /** The connection the table sends its viewport over and receives its window from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the retry tracked action dispatches over. */
  readonly actions: ActionLifecycle
}

/** The delivery mutation surface a deliveries view binds to. */
export interface HilosDeliveriesActions {
  /**
   * Retry a failed delivery, as a tracked action. The backend resets its attempts
   * to zero, sets it back to pending, clears the last error, and re-sends the
   * channel's deliver signal; a non-failed delivery is rejected on the action's
   * `::fail`. The updated row returns through the next window refresh.
   *
   * @param deliveryId The delivery id (also the table row key).
   */
  sendDeliveryRetry(deliveryId: number): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Read a recipient user id, keeping null when the notification (and its user) is gone. */
function readUserId(slot: Record<string, unknown>): number | null {
  const value = slot[DELIVERY_USER_ID_FIELD]

  return typeof value === 'number' ? value : null
}

/**
 * Resolve one raw delivery table row into its view-model. The projected delivery
 * fields ride a single inline `delivery` slot (no entity reference — a delivery row
 * is page-scoped, keyed by its id), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosDeliveryRow(row: TableRow): HilosDeliveryRow {
  const slot = recordSlot(row.slots[DELIVERY_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key. It must not travel inside the slot: a slot
    // payload carrying `id` is ingested as an entity fragment and replaced by a
    // reference, which would strip every other field off the row (normalizer.ts).
    rowKey: String(row.rowKey),
    createdAt: readString(slot, DELIVERY_CREATED_AT_FIELD),
    channel: readString(slot, DELIVERY_CHANNEL_FIELD),
    status: readString(slot, DELIVERY_STATUS_FIELD),
    attempts: readNumber(slot, DELIVERY_ATTEMPTS_FIELD),
    deliveredAt: readString(slot, DELIVERY_DELIVERED_AT_FIELD),
    lastError: readString(slot, DELIVERY_LAST_ERROR_FIELD),
    userId: readUserId(slot),
    userLabel: readString(slot, DELIVERY_USER_LABEL_FIELD),
    notificationType: readString(slot, DELIVERY_NOTIFICATION_TYPE_FIELD),
    notificationTitle: readString(slot, DELIVERY_NOTIFICATION_TITLE_FIELD),
  }
}

/** A failed delivery — the only kind the retry action can re-queue. */
export function isDeliveryRetryable(row: HilosDeliveryRow): boolean {
  return row.status === DELIVERY_STATUS_FAILED
}

/** The delivery-logs table handle a view drives: the controller plus its mount lifecycle. */
export interface HilosDeliveriesTable {
  /** The server-windowed controller the view renders rows, descriptor, and filters from. */
  readonly controller: TableViewportController<HilosDeliveryRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the delivery-logs table: search, the domain
 * filters (channel, status, period), sort, and paging change the viewport
 * descriptor sent over the connection, and the backend replies a window plus the
 * total count scoped to the table's (page, tableKey) address. There are no live
 * deltas — the journal is served from SQL. Rows resolve through
 * {@link resolveHilosDeliveryRow}. Newest first by default. The per-channel route
 * opens the journal with a channel preset through `initialFilter`. The returned
 * handle's `start` binds the table and requests the first window; `dispose`
 * unbinds it (the view calls them on mount / unmount).
 *
 * @param context The project context (connection and scope stores).
 * @param initialFilter The initial filter map — the per-channel route's channel preset, or none.
 */
export function createHilosDeliveriesTable(
  context: HilosDeliveriesContext,
  initialFilter?: Record<string, unknown>,
): HilosDeliveriesTable {
  const controller = new TableViewportController<HilosDeliveryRow>({
    resolve: resolveHilosDeliveryRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.COMMUNICATIONS_DELIVERIES,
        DELIVERIES_TABLE,
        descriptor,
      ),
    pageSize: DELIVERIES_PAGE_SIZE,
    initialFilter,
    initialSort: { field: DELIVERY_CREATED_AT_FIELD, direction: 'desc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        // A retry action_error without a requestId answers no pending request: nothing
        // on this page is waiting for it, so it surfaces as a toast rather than an inline
        // error; the correlated ones stay with the tracked action that dispatched them.
        context.connection.on('actionError', (signal) => {
          if (
            signal.requestId === undefined &&
            DELIVERY_ACTIONS.has(signal.action)
          ) {
            hilosToasts.push(signal.reason, { severity: 'error' })
          }
        }),
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.COMMUNICATIONS_DELIVERIES,
            tableKey: DELIVERIES_TABLE,
          },
          controller,
        ),
        // Re-request the window whenever the socket (re)connects: the initial
        // request below can run before the connection is open, and a reconnect
        // is a fresh exchange that no longer remembers this connection's window.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
            controller.start()
          }
        }),
      )
      controller.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The delivery mutation surface: retry submits as a tracked action over the
 * lifecycle. It returns an ActionHandle whose `done` resolves on the backend's
 * `::success` ack and rejects on `::fail` — a view surfaces the failure
 * (authoritative-backend). The re-queued row returns over the next window refresh,
 * so this surface only dispatches.
 *
 * @param context The project context (the action lifecycle the retry dispatches over).
 */
export function createHilosDeliveriesActions(
  context: HilosDeliveriesContext,
): HilosDeliveriesActions {
  return {
    sendDeliveryRetry(deliveryId) {
      return context.actions.dispatch(DELIVERY_RETRY_ACTION, { deliveryId })
    },
  }
}
