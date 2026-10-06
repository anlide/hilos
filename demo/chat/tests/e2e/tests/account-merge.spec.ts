import { expect, test } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, signUp } from '../helpers/session.js'
import { goToLastPage } from '../helpers/table.js'

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
