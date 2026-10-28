import { expect, test } from '@playwright/test'

import {
  deliveryObject,
  expectProfileSessionMoments,
  profileSessionSnapshot,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  createTestUser,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ timezoneId: 'Asia/Tokyo', locale: 'en-US' })

test('keeps real session and tab moments in milliseconds through live rebuild and reload', async ({
  context,
  page,
}, testInfo) => {
  const frames: SocketFrame[] = []
  const socket = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile/sessions', PAGE_READY)
    await expect.poll(() => profileSessionSnapshot(frames)).toBeDefined()
    const initial = profileSessionSnapshot(frames)!
    const self = deliveryObject(
      deliveryObject(initial.payload.data).selfConnection,
    )
    expect(self.acceptKey).toEqual(expect.any(String))
    expect(self.connectedAt).toBe(initial.connections[0]?.connectedAt)
    await clickSubmit(expect, page.getByTestId('profile-session-tabs'))
    await expectProfileSessionMoments(
      expect,
      page,
      initial,
      'Asia/Tokyo',
      self.acceptKey as string,
      1,
    )

    // Open only after the first snapshot: this must rebuild the first tab's list.
    const liveStart = frames.length
    const second = await context.newPage()
    await gotoPage(expect, second, '/profile/sessions', PAGE_READY)
    await expect
      .poll(
        () =>
          profileSessionSnapshot(frames.slice(liveStart))?.connections.length,
      )
      .toBe(2)
    const live = profileSessionSnapshot(frames.slice(liveStart))!
    expect(live.frame.socket).toBe(initial.frame.socket)
    expect(live.frame.message).toBeGreaterThan(initial.frame.message)
    expect(live.sessions[0]?.id).toBe(initial.sessions[0]?.id)
    expect(live.sessions[0]?.createdAt).toBe(initial.sessions[0]?.createdAt)
    expect(live.connections).toEqual(
      expect.arrayContaining([...initial.connections]),
    )
    await expectProfileSessionMoments(
      expect,
      page,
      live,
      'Asia/Tokyo',
      self.acceptKey as string,
      2,
    )

    const reloadStart = frames.length
    await page.reload()
    await expectPageReady(expect, page)
    await expect
      .poll(
        () =>
          profileSessionSnapshot(frames.slice(reloadStart))?.connections.length,
      )
      .toBe(2)
    const reloaded = profileSessionSnapshot(frames.slice(reloadStart))!
    expect(reloaded.frame.socket).toBeGreaterThan(live.frame.socket)
    const reloadedSelf = deliveryObject(
      deliveryObject(reloaded.payload.data).selfConnection,
    )
    expect(reloadedSelf.acceptKey).toEqual(expect.any(String))
    expect(reloadedSelf.acceptKey).not.toBe(self.acceptKey)
    expect(reloadedSelf.connectedAt).toBe(
      reloaded.connections.find(
        (connection) => connection.acceptKey === reloadedSelf.acceptKey,
      )?.connectedAt,
    )
    expect(reloaded.sessions[0]?.id).toBe(initial.sessions[0]?.id)
    expect(reloaded.sessions[0]?.createdAt).toBe(initial.sessions[0]?.createdAt)
    await clickSubmit(expect, page.getByTestId('profile-session-tabs'))
    await expectProfileSessionMoments(
      expect,
      page,
      reloaded,
      'Asia/Tokyo',
      reloadedSelf.acceptKey as string,
      2,
    )
    expect(frames.filter((frame) => frame.failure !== undefined)).toEqual([])
  } finally {
    socket.stop()
    await testInfo.attach('profile-session-transport', {
      body: JSON.stringify(frames, null, 2),
      contentType: 'application/json',
    })
  }
})
