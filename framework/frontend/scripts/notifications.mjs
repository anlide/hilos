import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 10_000 })

/** The draft fields the emit command accepts; type and title are required. */
/**
 * @typedef {object} NotificationDraft
 * @property {string} type Machine notification type.
 * @property {string} title Rendered title, which the bell row shows.
 * @property {string} [body] Rendered body, omitted for a title-only notification.
 * @property {string} [severity] Severity level; the backend defaults to `info`.
 * @property {string[]} [channels] Channel narrowing; omitted means every allowed channel.
 */

/** What the emit produced, as the delivery journal holds it. */
/**
 * @typedef {object} EmittedNotification
 * @property {number} notificationId Id of the persisted notification.
 * @property {string[]} queuedChannels Channels that got a delivery row.
 */

/**
 * Emit one notification to a user through the live daemon.
 *
 * @param {number} userId Recipient's durable user id.
 * @param {NotificationDraft} draft The notification to emit.
 * @returns {Promise<EmittedNotification>} The persisted id and queued channels.
 */
export async function emitNotification(userId, draft) {
  const reply = await sendCommand('test:notification:emit', {
    userId,
    ...draft,
  })

  return {
    notificationId: Number(reply.notificationId),
    queuedChannels:
      /** @type {string[] | undefined} */ (reply.queuedChannels) ?? [],
  }
}
