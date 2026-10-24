import { expect, test } from '@playwright/test'

import {
  countSentFrames,
  expectTableRowOrder,
  expectUserActivityDisplay,
  expectUserActivityMoment,
  forwardSessionTimeZone,
  ROWS,
  shownByTestId,
  watchClientDocument,
  watchFirstRowTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, PASSWORD, signUp, typeInto } from '../helpers/session.js'
import { goToLastPage } from '../helpers/table.js'

test.describe('merge candidate activity in the reader time zone', () => {
  test.use({ locale: 'en-GB', timezoneId: 'Asia/Tokyo' })

  test('returns an open candidate window from the own zone to Automatic without subscribing again', async ({
    browser,
    page,
  }) => {
    const socket = await forwardSessionTimeZone(page, 'Europe/Kyiv')
    const pageRequests = countSentFrames(page, '"type":"page_')
    const tableRequests = countSentFrames(page, '"type":"table_')
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const candidateContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
      locale: 'en-GB',
      timezoneId: 'Asia/Tokyo',
    })
    try {
      const readerId = await signUpAdmin(page)
      const candidate = await signUp(await candidateContext.newPage())
      await gotoPage(page, `/hilos/user/${readerId}`)
      await clickSubmit(page.getByTestId('hilos-user-merge-open'))
      await typeInto(page.getByTestId('step-up-password'), PASSWORD)
      await clickSubmit(page.getByTestId('hilos-user-merge-step-up-confirm'))
      const choice = shownByTestId(
        page,
        `hilos-user-merge-row-${candidate.userId}`,
      )
      if (!(await choice.isVisible())) await goToLastPage(page)
      await expect(choice).toBeVisible()
      const moment = expectUserActivityMoment(
        expect,
        socket.frames,
        'mergeCandidates',
        candidate.userId,
      )
      const ownTime = await expectUserActivityDisplay(
        expect,
        page,
        candidate.userId,
        moment,
        'Europe/Kyiv',
        'candidate',
      )
      const document = await watchClientDocument(page)
      try {
        const rowTop = await watchFirstRowTop(page)
        const keys = await page
          .getByTestId('modal')
          .locator(ROWS)
          .evaluateAll((rows) =>
            rows.map((row) =>
              row.getAttribute('data-id')!.slice('hilos-table-row-'.length),
            ),
          )
        expect(keys.length).toBeGreaterThan(1)
        const requests = [pageRequests(), tableRequests()]
        expect(requests[0]).toBeGreaterThan(0)
        expect(requests[1]).toBeGreaterThan(0)
        const sockets = socket.sockets()
        expect(socket.sessions.at(-1)).toMatchObject({
          data: {
            entities: {
              currentUser: { id: readerId, timeZone: 'Europe/Kyiv' },
            },
          },
        })

        socket.replay(null)
        const automaticTime = await expectUserActivityDisplay(
          expect,
          page,
          candidate.userId,
          moment,
          'Asia/Tokyo',
          'candidate',
        )
        expect(automaticTime).not.toBe(ownTime)
        await expect(choice).toBeVisible()
        expect(socket.sockets()).toBe(sockets)
        expect([pageRequests(), tableRequests()]).toEqual(requests)
        await document.unchanged()
        await expectTableRowOrder(expect, page, keys)
        await rowTop.unchanged()
        expect(
          expectUserActivityMoment(
            expect,
            socket.frames,
            'mergeCandidates',
            candidate.userId,
          ),
        ).toBe(moment)
        expect(socket.sessions.at(-1)).toMatchObject({
          data: { entities: { currentUser: { id: readerId, timeZone: null } } },
        })
        await test.info().attach('merge-candidate-reader-zone-wire', {
          body: JSON.stringify({
            frames: socket.frames,
            sessions: socket.sessions,
          }),
          contentType: 'application/json',
        })
      } finally {
        await document.dispose()
      }
      await clickSubmit(page.getByTestId('hilos-user-merge-cancel'))
      await expect(page.getByTestId('modal')).toHaveCount(0)
    } finally {
      socket.dispose()
      await candidateContext.close()
    }
  })
})

// The merge window under the admin view mode (HIL-1263): the merge is the account
// side, so its viewer case lives here, beside the merge itself; the rest of the
// person's card is binance-btc-tracker's users.spec.ts (HIL-1273).
test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens the merge window, reads the other account as hidden and has nothing to confirm', async ({
    browser,
    page,
  }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const userAContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const userBContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const userAPage = await userAContext.newPage()
    const userBPage = await userBContext.newPage()

    try {
      const { userId: userAId } = await signUp(userAPage)
      const { userId: userBId } = await signUp(userBPage)

      await setAdminViewMode(true)

      await gotoPage(page, `/hilos/user/${userAId}`)
      await clickSubmit(page.getByTestId('hilos-user-merge-open'))
      const mergeChoice = shownByTestId(page, `hilos-user-merge-row-${userBId}`)
      if (!(await mergeChoice.isVisible())) {
        await goToLastPage(page)
      }
      await expect(mergeChoice).toBeVisible()
      await mergeChoice.check()
      await clickSubmit(page.getByTestId('hilos-user-merge-next'))

      const summary = page.getByTestId('hilos-user-merge-summary')
      await expect(summary).toContainText('Hidden')
      const mergeConfirm = page.getByTestId('hilos-user-merge-confirm')
      await expect(mergeConfirm).toBeDisabled()
      await expect(
        page.getByTestId('hilos-user-merge-second-factor-both'),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-user-merge-second-factor-survivor'),
      ).toHaveCount(0)
      await clickSubmit(page.getByTestId('hilos-user-merge-cancel'))
      await clickSubmit(page.getByTestId('modal-confirm-discard'))
    } finally {
      await userAContext.close()
      await userBContext.close()
    }
  })
})
