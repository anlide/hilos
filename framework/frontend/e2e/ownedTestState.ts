import { randomBytes } from 'node:crypto'
import type { Expect } from '@playwright/test'

import {
  cleanupNotification,
  emitNotification,
  inspectNotification,
  type EmittedNotification,
  type NotificationDraft,
  type NotificationInspection,
} from '../scripts/notifications.mjs'
import { captureSetting, restoreSetting } from '../scripts/settings.mjs'
import {
  captureOAuthProvider,
  restoreOAuthProvider,
} from '../scripts/oauthProviders.mjs'
import { StandCommandEndpointError } from '../scripts/standCommand.mjs'
import type { TestCleanup } from './cleanupFixtures.js'

/**
 * Capture raw state and register restoration before the caller changes a setting.
 *
 * @param cleanup Scenario's test-scoped cleanup fixture.
 * @param key Setting to borrow.
 */
export async function borrowSetting(cleanup: TestCleanup, key: string) {
  const snapshot = await captureSetting(key)
  cleanup.defer(() => restoreSetting(snapshot))
  return snapshot
}

/**
 * Capture a provider and reserve exact cleanup before the caller's first mutation.
 * The raw snapshot stays inside this Node closure.
 *
 * @param cleanup Scenario's test-scoped cleanup fixture.
 * @param providerKey External provider directory key.
 */
export async function borrowOAuthProvider(
  cleanup: TestCleanup,
  providerKey: string,
): Promise<void> {
  const snapshot = await captureOAuthProvider(providerKey)
  cleanup.defer(async () => {
    const current = await captureOAuthProvider(providerKey)
    await restoreOAuthProvider(snapshot, current.row?.id ?? null)
  })
}

/**
 * Register a recipient and exact title before a UI action with a fixed title.
 * The scenario must supply its own recipient for that title.
 *
 * @param cleanup Scenario's test-scoped cleanup fixture.
 * @param userId Scenario's own recipient.
 * @param title Exact product title.
 * @param expect The demo runner's own assertion instance.
 */
export function ownNotificationSubject(
  cleanup: TestCleanup,
  userId: number,
  title: string,
  expect: Expect,
): void {
  deferNotification(cleanup, userId, title, expect)
}

/**
 * Reserve a unique title before emitting once; a failed reply remains a failure.
 *
 * @param cleanup Scenario's test-scoped cleanup fixture.
 * @param userId Recipient id.
 * @param draft Notification fields, with a title to suffix before sending.
 * @param expect The demo runner's own assertion instance.
 * @returns Persisted id, queued channels and the exact reserved title.
 */
export async function emitOwnedNotification(
  cleanup: TestCleanup,
  userId: number,
  draft: NotificationDraft,
  expect: Expect,
): Promise<EmittedNotification & { title: string }> {
  const title = `${draft.title} ${randomBytes(8).toString('hex')}`
  const owned = deferNotification(cleanup, userId, title, expect)
  try {
    owned.expected = await emitNotification(userId, { ...draft, title })
  } catch (failure) {
    if (failure instanceof StandCommandEndpointError) {
      owned.notSent = true
      throw failure
    }
    try {
      await inspectNotification(userId, title)
    } catch (inspectionFailure) {
      throw new AggregateError(
        [failure, inspectionFailure],
        'Notification emit failed and its exact subject could not be inspected.',
        { cause: inspectionFailure },
      )
    }
    throw failure
  }
  assertEmission(await inspectNotification(userId, title), owned.expected)
  return { ...owned.expected, title }
}

/** State belongs to the scenario's cleanup closure. */
interface OwnedNotification {
  expected?: EmittedNotification
  notSent: boolean
}

/**
 * Register cleanup before the action and observe only this exact subject.
 *
 * @param cleanup Scenario's test-scoped cleanup fixture.
 * @param userId Recipient id.
 * @param title Exact title fixed before sending.
 * @param expect The demo runner's own assertion instance.
 * @returns The emission expectation kept by this cleanup closure.
 */
function deferNotification(
  cleanup: TestCleanup,
  userId: number,
  title: string,
  expect: Expect,
): OwnedNotification {
  const owned: OwnedNotification = { notSent: false }
  cleanup.defer(async () => {
    if (owned.notSent) return
    let observed: NotificationInspection | undefined
    await expect
      .poll(
        async () => {
          observed = await inspectNotification(userId, title)
          return (
            observed.notificationId !== null &&
            observed.deliveries.every(
              (delivery) => delivery.status !== 'pending',
            )
          )
        },
        {
          message: `Notification cleanup needs a terminal subject for ${userId}: ${title}`,
        },
      )
      .toBe(true)
    if (!observed || observed.notificationId === null) {
      throw new Error(
        'Notification cleanup could not resolve its exact subject.',
      )
    }
    const result = await cleanupNotification(userId, title)
    if (!result.deleted) {
      throw new Error(
        'Notification cleanup did not delete its observed subject.',
      )
    }
    if (owned.expected) assertEmission(observed, owned.expected)
  })
  return owned
}

/**
 * @param observed Exact subject read through the owner.
 * @param emitted Successful emit response to match.
 */
function assertEmission(
  observed: NotificationInspection,
  emitted: EmittedNotification,
): void {
  if (
    observed.notificationId !== emitted.notificationId ||
    JSON.stringify([...observed.queuedChannels].sort()) !==
      JSON.stringify([...emitted.queuedChannels].sort())
  ) {
    throw new Error(
      'Notification inspection does not match the successful emit reply.',
    )
  }
}
