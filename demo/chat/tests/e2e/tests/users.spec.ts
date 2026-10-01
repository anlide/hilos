import { expect, test } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../helpers/adminViewMode'
import { gotoPage } from '../helpers/page'
import { clickSubmit, signUp } from '../helpers/session'
import { goToLastPage } from '../helpers/table'

// The framework people section under admin view mode (HIL-1263): a viewer — a guest
// included — opens every window of the person card directly, without the confirmation
// step, and every mutation control stands disabled by the mode. The takeover is one of
// the card's windows since HIL-1170; the users list carries none.
test.describe('the people section in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens every window of the people section and has nothing to send from it', async ({
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

      // 1. User card: the takeover window
      await gotoPage(page, `/hilos/user/${userBId}`)
      await clickSubmit(page.getByTestId('hilos-user-impersonate-open'))
      await expect(
        page.getByTestId('hilos-user-impersonate-step-up'),
      ).toHaveCount(0)
      const impersonateConfirm = page.getByTestId(
        'hilos-user-impersonate-confirm',
      )
      await expect(impersonateConfirm).toBeDisabled()
      await expect(impersonateConfirm).toHaveAttribute(
        'aria-describedby',
        /(^| )hilos-view-mode-strip-text( |$)/,
      )
      await clickSubmit(page.getByTestId('hilos-user-impersonate-cancel'))

      // 2. User card: lifecycle modals (admin, block, deletion)
      await gotoPage(page, `/hilos/user/${userAId}`)
      for (const key of ['admin', 'block', 'deletion'] as const) {
        await clickSubmit(page.getByTestId(`hilos-user-${key}-open`))
        await expect(
          page.getByTestId('hilos-user-lifecycle-step-up'),
        ).toHaveCount(0)
        const lifecycleConfirm = page.getByTestId(
          'hilos-user-lifecycle-confirm',
        )
        await expect(lifecycleConfirm).toBeDisabled()
        await expect(lifecycleConfirm).toHaveAttribute(
          'aria-describedby',
          /(^| )hilos-view-mode-strip-text( |$)/,
        )
        await clickSubmit(page.getByTestId('hilos-user-lifecycle-cancel'))
      }

      // 3. User card: merge modal
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
      await clickSubmit(page.getByTestId('hilos-user-merge-cancel'))
      await clickSubmit(page.getByTestId('modal-confirm-discard'))

      // 4. User card: rename modal
      await clickSubmit(page.getByTestId('hilos-user-edit'))
      await expect(page.getByTestId('hilos-user-save')).toBeDisabled()
      await clickSubmit(page.getByTestId('hilos-user-cancel'))
    } finally {
      await userAContext.close()
      await userBContext.close()
    }
  })
})
