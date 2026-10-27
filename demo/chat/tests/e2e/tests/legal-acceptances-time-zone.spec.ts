import { expect, test } from '@playwright/test'

import {
  expectLegalAcceptanceMoments,
  forwardSessionTimeZone,
  watchDeliveryFrames,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test.use({ locale: 'en-GB', timezoneId: 'America/Los_Angeles' })

test('displays legal acceptances in automatic browser time zone and updates reactively without reload on session time zone change', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const log = watchDeliveryFrames(page)
  const socket = await forwardSessionTimeZone(page, null)
  try {
    const userId = await signUpAdmin(expect, page, {
      entryId: 'message-signin',
    })
    await gotoPage(expect, page, '/hilos/legal/acceptances')

    const initialExpected = await expectLegalAcceptanceMoments(
      expect,
      page,
      log,
      userId,
      'America/Los_Angeles',
    )

    const loadsBeforeReplay = fullLoads

    socket.replay('Asia/Tokyo')

    const tokyoExpected = await expectLegalAcceptanceMoments(
      expect,
      page,
      log,
      userId,
      'Asia/Tokyo',
    )

    expect(fullLoads).toBe(loadsBeforeReplay)
    expect(tokyoExpected).not.toBe(initialExpected)
  } finally {
    socket.dispose()
    log.stop()
  }
})
