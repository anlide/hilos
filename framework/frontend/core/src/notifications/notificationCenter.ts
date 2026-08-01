// Notification-center core: the framework-owned live channel and store for the
// durable notification model (HIL-102 backend, HIL-195 UI). The center has no
// page subscription — the SubscriptionRegistry holds one page per connection, so
// an always-on page would clobber the route page. Instead a connection joins the
// per-user WebSocket group `hilos_notifications:<userId>` for live created/read
// signals and asks for its initial snapshot with the fire-and-forget
// `notification_sync` action; both ride the framework group_subscribe / action
// frames the backend already speaks (AbstractHilosNotificationsPage). The store
// here is the reactive half a bell view renders; the binder wires it to a
// connection and keeps the group joined across reconnects.
import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

/** Server→client signal `type` carrying a freshly created notification (PHP `NotificationSignalName::CREATED`). */
export const NOTIFICATION_SIGNAL_CREATED = 'notification_created'

/** Server→client signal `type` carrying a mark-read, one row or all (PHP `NotificationSignalName::READ`). */
export const NOTIFICATION_SIGNAL_READ = 'notification_read'

/** Server→client signal `type` carrying the snapshot reply (PHP `HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_NOTIFICATIONS`). */
export const NOTIFICATION_SIGNAL_SNAPSHOT =
  'subscription_page_hilos_notifications'

/** Client→server action requesting the recipient's snapshot (PHP `NotificationAction::SYNC`). */
export const NOTIFICATION_ACTION_SYNC = 'notification_sync'

/** Client→server action marking one notification read (PHP `NotificationAction::MARK_READ`). */
export const NOTIFICATION_ACTION_MARK_READ = 'notification_mark_read'

/** Client→server action marking every notification read (PHP `NotificationAction::MARK_ALL_READ`). */
export const NOTIFICATION_ACTION_MARK_ALL_READ = 'notification_mark_all_read'

/** Group-name prefix; the recipient user id is appended (PHP `NotificationGroup::PREFIX`). */
const NOTIFICATION_GROUP_PREFIX = 'hilos_notifications:'

/** The mark-all sentinel of the read signal's `id` (PHP `NotificationReadSignalData::ALL`). */
const NOTIFICATION_READ_ALL = 'all'

/** Recent rows the store keeps, mirroring `AbstractHilosNotificationsPage::RECENT_LIMIT`. */
const RECENT_LIMIT = 20

/**
 * Builds the per-user notification group name a connection joins for live signals.
 *
 * @param userId Recipient user id.
 */
export function notificationGroupName(userId: number): string {
  return `${NOTIFICATION_GROUP_PREFIX}${userId}`
}

/**
 * One notification on the wire: the CREATED shape (PHP `NotificationCreatedSignalData`),
 * reused for every snapshot row. `readAt` is null while unread.
 */
const notificationRowSchema = z.looseObject({
  id: z.number().int(),
  userId: z.number().int(),
  type: z.string(),
  severity: z.string(),
  title: z.string(),
  body: z.string().nullable().optional(),
  data: z.record(z.string(), z.unknown()).nullable().optional(),
  readAt: z.string().nullable().optional(),
  createdAt: z.string().nullable().optional(),
})

export type HilosNotification = z.infer<typeof notificationRowSchema>

/** Payload of the mark-read signal: a single notification id, or the "all" sentinel. */
const notificationReadSchema = z.looseObject({
  id: z.union([z.number().int(), z.literal(NOTIFICATION_READ_ALL)]),
})

/** Payload of the snapshot reply: the recent rows newest-first plus the unread badge count. */
const notificationSnapshotSchema = z.looseObject({
  recent: z.array(notificationRowSchema),
  unreadCount: z.number().int(),
})

export type HilosNotificationSnapshot = z.infer<
  typeof notificationSnapshotSchema
>

/**
 * The notification signal schemas keyed for a connection's `projectSchemas`, so
 * the parse boundary validates what {@link bindNotificationsScope} ingests.
 * {@link createHilosConnection} merges them in, so a project never restates them.
 */
export const NOTIFICATION_SIGNAL_SCHEMAS = {
  [NOTIFICATION_SIGNAL_CREATED]: notificationRowSchema,
  [NOTIFICATION_SIGNAL_READ]: notificationReadSchema,
  [NOTIFICATION_SIGNAL_SNAPSHOT]: notificationSnapshotSchema,
}

/** The reactive notification state a bell view renders. */
export interface HilosNotificationStore {
  /** The recent notifications, newest first (capped at the snapshot limit). */
  readonly notifications: ReadonlySignal<readonly HilosNotification[]>
  /** The unread badge count; server-authoritative from the snapshot, delta'd live. */
  readonly unreadCount: ReadonlySignal<number>
  /**
   * Replace the state from a fresh snapshot (the sync reply, once per join).
   *
   * @param snapshot Recent rows plus the unread count.
   */
  ingestSnapshot(snapshot: HilosNotificationSnapshot): void
  /**
   * Prepend a newly created notification and bump the unread count.
   *
   * @param row The created notification.
   */
  onCreated(row: HilosNotification): void
  /**
   * Apply a mark-read to the store: one row by id, or every row for the "all"
   * sentinel. Fired for the recipient's own action and their other devices alike.
   *
   * @param idOrAll The marked-read notification id, or the "all" sentinel.
   */
  onRead(idOrAll: number | typeof NOTIFICATION_READ_ALL): void
  /** Reset to empty (e.g. sign-out). */
  clear(): void
}

/**
 * Create an independent notification store.
 *
 * Applications use the shared {@link hilosNotifications}; this factory exists so a
 * test (or a second window) gets its own state.
 */
export function createHilosNotificationStore(): HilosNotificationStore {
  const notifications: WritableSignal<readonly HilosNotification[]> =
    createSignal<readonly HilosNotification[]>([])
  const unreadCount = createSignal(0)

  function markedReadRow(row: HilosNotification): HilosNotification {
    return { ...row, readAt: new Date().toISOString() }
  }

  return {
    notifications,
    unreadCount,
    ingestSnapshot(snapshot) {
      notifications.set(snapshot.recent.slice(0, RECENT_LIMIT))
      unreadCount.set(snapshot.unreadCount)
    },
    onCreated(row) {
      const next = [
        row,
        ...notifications.get().filter((existing) => existing.id !== row.id),
      ].slice(0, RECENT_LIMIT)
      notifications.set(next)
      if (row.readAt == null) {
        unreadCount.set(unreadCount.get() + 1)
      }
    },
    onRead(idOrAll) {
      if (idOrAll === NOTIFICATION_READ_ALL) {
        notifications.set(
          notifications
            .get()
            .map((row) => (row.readAt == null ? markedReadRow(row) : row)),
        )
        unreadCount.set(0)

        return
      }

      let wasUnread = true
      notifications.set(
        notifications.get().map((row) => {
          if (row.id !== idOrAll) {
            return row
          }
          if (row.readAt != null) {
            wasUnread = false

            return row
          }

          return markedReadRow(row)
        }),
      )
      if (wasUnread) {
        unreadCount.set(Math.max(0, unreadCount.get() - 1))
      }
    },
    clear() {
      notifications.set([])
      unreadCount.set(0)
    },
  }
}

/**
 * The application-wide notification store.
 *
 * One per loaded SDK: the bell view mounted in the shell renders it, and
 * {@link bindNotificationsScope} feeds it from the connection. A test that needs
 * isolation builds its own with {@link createHilosNotificationStore}.
 */
export const hilosNotifications: HilosNotificationStore =
  createHilosNotificationStore()

/**
 * Wire a notification store to a connection: route the live signals into the
 * store, and keep the per-user group joined with a fresh snapshot request on
 * every connect (and reconnect). Register before the socket opens so the first
 * snapshot lands.
 *
 * The group name needs the recipient's own id, so the join waits until the
 * session's user id is known (the handshake response). A connection with no user
 * (anonymous, or a demo without auth) never joins and never asks for a snapshot,
 * so activating this on such a demo is a no-op rather than an error.
 *
 * @param connection The application's Hilos connection.
 * @param store The notification store to feed (usually {@link hilosNotifications}).
 * @param userId The session's current user id signal (null until the handshake lands).
 */
export function bindNotificationsScope(
  connection: HilosConnection,
  store: HilosNotificationStore,
  userId: ReadonlySignal<number | null>,
): void {
  connection.on('projectSignal', (signal) => {
    switch (signal.type) {
      case NOTIFICATION_SIGNAL_SNAPSHOT:
        // Validated against notificationSnapshotSchema at the parse boundary; this
        // cast is the declared typed selector for that schema's output.
        store.ingestSnapshot(signal.data as HilosNotificationSnapshot)
        break
      case NOTIFICATION_SIGNAL_CREATED:
        store.onCreated(signal.data as HilosNotification)
        break
      case NOTIFICATION_SIGNAL_READ:
        store.onRead(
          (signal.data as { id: number | typeof NOTIFICATION_READ_ALL }).id,
        )
        break
      default:
        break
    }
  })

  // The user id we have joined the group for on the current socket; reset on any
  // non-connected transition so a reconnect re-joins (a fresh socket loses every
  // server-side membership).
  let joinedFor: number | null = null

  function maybeJoin(): void {
    if (connection.state !== 'connected') {
      return
    }
    const uid = userId.get()
    if (uid === null || joinedFor === uid) {
      return
    }
    joinedFor = uid
    connection.subscribeToGroup(notificationGroupName(uid))
    connection.sendAction(NOTIFICATION_ACTION_SYNC, {})
  }

  connection.on('state', (state) => {
    if (state !== 'connected') {
      joinedFor = null

      return
    }
    maybeJoin()
  })
  // The handshake response lands after `connected`, so the id often arrives once
  // already connected; re-check the join whenever it changes.
  subscribeSignal(userId, () => {
    maybeJoin()
  })
}
