import type { Expect, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { expectReaderMillisecondMoment } from './moments.js'

/** Read the latest real profile-list password row, retaining its delivery. */
function profilePasswordSnapshot(log: DeliveryFrameLog) {
  for (const packet of log.packets.slice().reverse()) {
    for (const frame of packet.frames.slice().reverse()) {
      const wire = deliveryObject(frame.wire)
      const data = deliveryObject(wire.data)
      if (
        wire.type !== 'page_response' ||
        data.page !== 'hilos_profile_sign_in'
      )
        continue
      const list = deliveryObject(
        deliveryObject(deliveryObject(data.payload).lists).profileIdentities,
      )
      if (!Array.isArray(list.items)) continue
      for (const item of list.items) {
        const identities = deliveryObject(deliveryObject(item).slots).identities
        if (!Array.isArray(identities)) continue
        const identity = identities
          .map(deliveryObject)
          .find((row) => row.type === 'password')
        if (identity) return { packet, frame, identity }
      }
    }
  }
  return undefined
}

/** Match the password's server-authored moment to its exact reader-local caption. */
export async function expectProfilePasswordCaption(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  after = 0,
) {
  await expect
    .poll(() => profilePasswordSnapshot(log)?.identity.passwordChangedAt)
    .toBeGreaterThan(after)
  const snapshot = profilePasswordSnapshot(log)!
  expect(snapshot.frame.failure).toBeUndefined()
  expect(snapshot.frame.raw).toEqual(expect.any(String))
  const timeZone = await page.evaluate(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone,
  )
  const moment = await expectReaderMillisecondMoment(
    expect,
    page
      .getByTestId('profile-identity-item')
      .and(page.locator(`[data-identity-key="${snapshot.identity.id}"]`))
      .getByTestId('identity-identifier'),
    snapshot.identity.passwordChangedAt,
    timeZone,
    'Set · last changed ',
  )
  return {
    ...snapshot,
    changedAt: snapshot.identity.passwordChangedAt as number,
    caption: `Set · last changed ${moment}`,
  }
}
