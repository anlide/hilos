import type { Expect, Page } from '@playwright/test'

import { deliveryObject } from './deliveryFrames.js'
import { readerMillisecondMoment } from './moments.js'
import type { SocketFrame } from './socket.js'

/** A real profile list delivery, with its original physical transport evidence. */
export interface ProfileSessionSnapshot {
  readonly frame: SocketFrame
  readonly payload: Record<string, unknown>
  readonly sessions: readonly Record<string, unknown>[]
  readonly connections: readonly Record<string, unknown>[]
}

/** Read the latest initial or live sessions list without repairing invalid fields. */
export function profileSessionSnapshot(
  frames: readonly SocketFrame[],
): ProfileSessionSnapshot | undefined {
  for (const frame of frames.slice().reverse()) {
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    if (
      wire.type !== 'page_response' ||
      data.page !== 'hilos_profile_sessions'
    ) {
      continue
    }
    const payload = deliveryObject(data.payload)
    const list = deliveryObject(deliveryObject(payload.lists).profileSessions)
    if (!Array.isArray(list.items) || list.items.length !== 1) continue
    const slots = deliveryObject(deliveryObject(list.items[0]).slots)
    if (!Array.isArray(slots.sessions) || !Array.isArray(slots.connections)) {
      continue
    }
    return {
      frame,
      payload,
      sessions: slots.sessions.map(deliveryObject),
      connections: slots.connections.map(deliveryObject),
    }
  }
  return undefined
}

/** A contemporary UTC millisecond value: seconds and double multiplication fail. */
function expectMillisecondMoment(expect: Expect, moment: unknown): number {
  expect(moment).toEqual(expect.any(Number))
  expect(Number.isSafeInteger(moment)).toBe(true)
  expect(moment).toBeGreaterThanOrEqual(Date.UTC(2020, 0, 1))
  expect(moment).toBeLessThan(Date.UTC(2100, 0, 1))
  return moment as number
}

/** Numeric profile date options, independent of the production formatter. */
const PROFILE_DATE_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  hour: 'numeric',
  minute: 'numeric',
  second: 'numeric',
}

/** Match one browser's real session and expanded tabs to their captured moments. */
export async function expectProfileSessionMoments(
  expect: Expect,
  page: Page,
  snapshot: ProfileSessionSnapshot,
  timeZone: string,
  selfAcceptKey: string,
  tabCount: number,
  browserTimeZone = timeZone,
): Promise<void> {
  expect(snapshot.frame.failure).toBeUndefined()
  expect(snapshot.frame.raw).toEqual(expect.any(String))
  expect(snapshot.sessions).toHaveLength(1)
  expect(snapshot.connections).toHaveLength(tabCount)
  const session = snapshot.sessions[0]!
  expect(session.id).toEqual(expect.any(Number))
  expect(session.browserTimeZone).toBe(browserTimeZone)
  expect(session.impersonatorUserId).toBeNull()
  const createdAt = expectMillisecondMoment(expect, session.createdAt)
  expectMillisecondMoment(expect, session.lastSeenAt)
  const expiresAt =
    session.expiresAt === null
      ? null
      : expectMillisecondMoment(expect, session.expiresAt)
  const format = (moment: number) =>
    readerMillisecondMoment(page, moment, timeZone, PROFILE_DATE_FORMAT)
  const row = page.getByTestId('profile-session-row')
  await expect(row).toHaveCount(1)
  await expect(row.getByTestId('profile-session-this')).toHaveText('this one')
  await expect(row.getByTestId('profile-session-device')).toHaveText(
    `${session.deviceName ?? 'Unknown device'} · ${browserTimeZone}`,
  )
  await expect(row).toContainText(
    `created ${await format(createdAt)} · last seen now · expires ${expiresAt === null ? 'never' : await format(expiresAt)}`,
  )
  await expect(row.getByTestId('profile-session-tabs')).toHaveText(
    `tabs: ${tabCount}`,
  )
  await expect(row.getByTestId('profile-session-tabs')).toHaveAttribute(
    'aria-expanded',
    'true',
  )
  const connections = snapshot.connections.map((connection) => {
    expect(connection.sessionId).toBe(session.id)
    expect(connection.acceptKey).toEqual(expect.any(String))
    return {
      acceptKey: connection.acceptKey as string,
      connectedAt: expectMillisecondMoment(expect, connection.connectedAt),
    }
  })
  expect(
    connections.filter(({ acceptKey }) => acceptKey === selfAcceptKey),
  ).toHaveLength(1)
  const shortKey = (acceptKey: string) =>
    acceptKey.length <= 8
      ? acceptKey
      : `${acceptKey.slice(0, 4)}…${acceptKey.slice(-4)}`
  const tabs = await Promise.all(
    connections
      .sort((left, right) => right.connectedAt - left.connectedAt)
      .map(async ({ acceptKey, connectedAt }) => ({
        key: shortKey(acceptKey),
        opened: `opened ${await format(connectedAt)}`,
      })),
  )
  const tabRows = row.getByTestId('profile-session-tab')
  await expect(tabRows).toHaveCount(tabCount)
  await expect(tabRows.locator('code')).toHaveText(tabs.map((tab) => tab.key))
  await expect(tabRows.locator(':scope > span:not([data-id])')).toHaveText(
    tabs.map((tab) => tab.opened),
  )
  const currentTab = tabRows.filter({
    has: page.getByTestId('profile-session-tab-this'),
  })
  await expect(currentTab).toHaveCount(1)
  await expect(currentTab.locator('code')).toHaveText(shortKey(selfAcceptKey))
  await expect(currentTab.getByTestId('profile-session-tab-this')).toHaveText(
    'this tab',
  )
}
