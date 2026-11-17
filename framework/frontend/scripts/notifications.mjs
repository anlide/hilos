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
  validateSubject(userId, draft.title)
  const reply = await sendCommand('test:notification:emit', {
    ...draft,
    userId,
  })

  if (
    !hasFields(reply, ['notificationId', 'queuedChannels']) ||
    !positiveId(reply.notificationId) ||
    !channelList(reply.queuedChannels)
  ) {
    throw new Error('Invalid notification emit reply.')
  }
  return {
    notificationId: reply.notificationId,
    queuedChannels: reply.queuedChannels,
  }
}

/**
 * @typedef {object} NotificationInspection
 * @property {number | null} notificationId Exact notification, absent when no row matches.
 * @property {string[]} queuedChannels Channels present in the delivery journal.
 * @property {{deliveryId: number, channel: string, status: 'pending' | 'sent' | 'failed'}[]} deliveries Delivery states.
 */

/**
 * Read one exact recipient and title, refusing duplicate subjects in the owner.
 *
 * @param {number} userId Recipient id.
 * @param {string} title Exact title reserved before the action.
 * @returns {Promise<NotificationInspection>} Current notification and journal state.
 */
export async function inspectNotification(userId, title) {
  validateSubject(userId, title)
  const reply = await sendCommand('test:notification:inspect', {
    userId,
    title,
  })
  if (
    !hasFields(reply, ['notificationId', 'queuedChannels', 'deliveries']) ||
    (reply.notificationId !== null && !positiveId(reply.notificationId)) ||
    !channelList(reply.queuedChannels) ||
    !Array.isArray(reply.deliveries)
  ) {
    throw new Error('Invalid notification inspection reply.')
  }
  const deliveryIds = new Set()
  const channels = new Set()
  for (const delivery of reply.deliveries) {
    if (
      !hasFields(delivery, ['deliveryId', 'channel', 'status']) ||
      !positiveId(delivery.deliveryId) ||
      deliveryIds.has(delivery.deliveryId) ||
      typeof delivery.channel !== 'string' ||
      delivery.channel.length === 0 ||
      !['pending', 'sent', 'failed'].includes(delivery.status)
    ) {
      throw new Error('Invalid notification delivery reply.')
    }
    deliveryIds.add(delivery.deliveryId)
    channels.add(delivery.channel)
  }
  if (
    channels.size !== reply.queuedChannels.length ||
    !reply.queuedChannels.every((channel) => channels.has(channel)) ||
    (reply.notificationId === null && reply.deliveries.length !== 0)
  ) {
    throw new Error('Notification inspection fields disagree.')
  }
  return /** @type {NotificationInspection} */ (reply)
}

/**
 * Ask the personal owner to delete one notification after its deliveries settle.
 *
 * @param {number} userId Recipient id.
 * @param {string} title Exact title reserved before the action.
 * @returns {Promise<{deleted: boolean}>} Whether a notification was removed.
 */
export async function cleanupNotification(userId, title) {
  validateSubject(userId, title)
  const reply = await sendCommand('test:notification:cleanup', {
    userId,
    title,
  })
  if (!hasFields(reply, ['deleted']) || typeof reply.deleted !== 'boolean') {
    throw new Error('Invalid notification cleanup reply.')
  }
  return { deleted: reply.deleted }
}

/**
 * @param {number} userId Recipient id.
 * @param {string} title Exact title.
 */
function validateSubject(userId, title) {
  if (!positiveId(userId) || typeof title !== 'string') {
    throw new Error(
      'Notification commands require a positive integer userId and a string title.',
    )
  }
}

/**
 * @param {unknown} value Candidate id.
 * @returns {value is number} Whether the value is a positive JSON integer.
 */
function positiveId(value) {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

/**
 * @param {unknown} value Candidate channel list.
 * @returns {value is string[]} Whether it holds unique nonempty names.
 */
function channelList(value) {
  return (
    Array.isArray(value) &&
    value.every((name) => typeof name === 'string' && name.length > 0) &&
    new Set(value).size === value.length
  )
}

/**
 * @param {unknown} value Candidate reply.
 * @param {string[]} fields Complete field set.
 * @returns {value is Record<string, unknown>} Whether all and only those fields exist.
 */
function hasFields(value, fields) {
  return (
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    Object.keys(value).length === fields.length &&
    fields.every((field) => Object.hasOwn(value, field))
  )
}
