// Notification-center core: the framework-owned live channel and store for the
// durable notification model (HIL-102 backend, HIL-195 UI). The center has no
// page subscription — the SubscriptionRegistry holds one page per connection, so
// an always-on page would clobber the route page. Instead a connection joins the
// notification group for live created/read signals, and the join itself answers
// with the initial snapshot: one frame, no follow-up request (HIL-721). The
// client names the group WITHOUT the recipient — the server builds
// `hilos_notifications:<userId>` out of the identity behind the socket, and a
// name that tried to carry someone else's id is refused. The store here is the
// reactive half a bell view renders; the binder wires it to a connection and
// keeps the group joined across reconnects.
import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_GROUP_RESPONSE,
  SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'
import {
  type GroupResponse,
  type GroupSubscriptionError,
} from '../protocol/groupError.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../session/sessionScope.js'
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

/** Client→server action marking one notification read (PHP `NotificationAction::MARK_READ`). */
export const NOTIFICATION_ACTION_MARK_READ = 'notification_mark_read'

/** Client→server action marking every notification read (PHP `NotificationAction::MARK_ALL_READ`). */
export const NOTIFICATION_ACTION_MARK_ALL_READ = 'notification_mark_all_read'

/** The group the bell joins, named without a recipient (PHP `NotificationGroup::NAME`). */
export const NOTIFICATION_GROUP = 'hilos_notifications'

/** Prefix of the FULL name the server answers with; the recipient is appended (PHP `NotificationGroup::PREFIX`). */
const NOTIFICATION_GROUP_PREFIX = `${NOTIFICATION_GROUP}:`

/** The mark-all sentinel of the read signal's `id` (PHP `NotificationReadSignalData::ALL`). */
const NOTIFICATION_READ_ALL = 'all'

/** Recent rows the store keeps, mirroring `AbstractHilosNotificationsGroup::RECENT_LIMIT`. */
const RECENT_LIMIT = 20

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

/** Content of the join answer: the recent rows newest-first plus the unread badge count. */
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
}

/** The reactive notification state a bell view renders. */
export interface HilosNotificationStore {
  /** The recent notifications, newest first (capped at the snapshot limit). */
  readonly notifications: ReadonlySignal<readonly HilosNotification[]>
  /** The unread badge count; server-authoritative from the snapshot, delta'd live. */
  readonly unreadCount: ReadonlySignal<number>
  /**
   * Replace the state from a fresh snapshot (the join answer, once per join).
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
 * store, and keep the group joined on every connect (and reconnect). The join
 * answers with the snapshot, so there is nothing else to ask for. Register
 * before the socket opens so that answer lands.
 *
 * A connection with no user (anonymous, or a demo without auth) never joins, so
 * activating this on such a demo is a no-op rather than an error.
 *
 * The join still waits for the handshake, per SOCKET rather than per app, but the
 * reason has changed and a maintainer should know which one it is now. It is no
 * longer that the name needs the recipient's id — the client does not name the
 * recipient at all any more, the server builds the name out of the identity
 * behind the socket. It is that the SERVER needs that identity to build it with,
 * and a group_subscribe has no server-side park to wait in: parkUntilIdentified()
 * holds page_subscribe and page_access_reassess only, so a join that overtook the
 * handshake is judged against a connection nobody has heard of and refused as
 * anonymous. A reconnect while signed in is exactly that case.
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
      case SIGNAL_HANDSHAKE_RESPONSE:
        // This socket's identity has landed. The session scope is bound first
        // (bootHilos), so its own listener has already written the id this reads.
        sessionAnswered = true
        maybeJoin()
        break
      case SIGNAL_TYPE_GROUP_RESPONSE:
        ingestJoinAnswer(signal.data as GroupResponse)
        break
      case SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR:
        // Nothing is drawn: the bell lives in the application shell, not on a page,
        // and has no error surface of its own. A refusal here means a server defect
        // or a forged frame rather than anything the person did, so the store is
        // left as it is and the next connect tries again.
        reportRefusal(signal.data as GroupSubscriptionError)
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
  // Whether the current socket's handshake has answered — see the note above.
  // TODO(HIL-599): the server now holds a frame from a connection it has not been
  // told about yet and judges it once the identity lands, so this hold is a second
  // lock rather than the only one. It stays until the server side has been in the
  // wild long enough to trust alone - revisit after 2027-12-28, and only on the
  // owner's word, as a leaf of its own.
  let sessionAnswered = false

  function maybeJoin(): void {
    if (connection.state !== 'connected' || !sessionAnswered) {
      return
    }
    const uid = userId.get()
    if (uid === null || joinedFor === uid) {
      return
    }
    joinedFor = uid
    connection.subscribeToGroup(NOTIFICATION_GROUP)
  }

  /**
   * Take the snapshot out of a join answer, if this answer is the bell's.
   *
   * The frame type is common to every group, so the name is what tells them apart,
   * and the payload is validated here rather than at the parse boundary for the
   * same reason: only this binder knows what shape its own group answers with.
   *
   * @param answer The group-join answer as it arrived.
   */
  function ingestJoinAnswer(answer: GroupResponse): void {
    if (!answer.group.startsWith(NOTIFICATION_GROUP_PREFIX)) {
      return
    }
    const snapshot = notificationSnapshotSchema.safeParse(answer.payload ?? {})
    if (!snapshot.success) {
      console.warn(
        '[hilos] notification group answered with an unreadable snapshot',
        snapshot.error,
      )
      return
    }
    store.ingestSnapshot(snapshot.data)
  }

  /**
   * Write down a refused join, if the refusal is the bell's, and let it be retried.
   *
   * Clearing the join mark is what makes the refusal recoverable without a reconnect.
   * It matters for one refusal in particular: the server builds the group name out of
   * the identity behind the socket, and the worker serving the group learns that
   * identity over the runtime sync rather than from the frame — so a join that
   * overtakes it is refused as anonymous (the residue of HIL-599, whose server-side
   * park covers page frames only). Left marked as joined, this connection would then
   * carry no notifications at all until its socket dropped; unmarked, the next cue
   * that reaches maybeJoin() — a state change, a login, the next handshake — joins
   * again. Nothing is scheduled here: a refusal must not become a retry loop against
   * a server that means it.
   *
   * @param refusal The group-join refusal as it arrived.
   */
  function reportRefusal(refusal: GroupSubscriptionError): void {
    if (refusal.group !== NOTIFICATION_GROUP) {
      return
    }
    joinedFor = null
    console.warn(
      `[hilos] notification group join refused: ${refusal.errorCode} - ${refusal.message}`,
    )
  }

  connection.on('state', (state) => {
    if (state !== 'connected') {
      joinedFor = null
      sessionAnswered = false
    }
  })
  // A login upgrades the session on a socket that already answered, so the id
  // arrives without a handshake behind it; re-check the join whenever it changes.
  subscribeSignal(userId, () => {
    maybeJoin()
  })
}
