import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoAdmitted, gotoPage } from '../helpers/page.js'
import { clickSubmit, login, PASSWORD, signUp } from '../helpers/session.js'

test('a blocked account prepares and downloads its data after a credited password sign-in', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const strangerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  try {
    await signUpAdmin(page)
    const person = await signUp(personPage)
    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(personPage.getByTestId('account-blocked')).toBeVisible()

    // This sign-in supplies the fresh proof; a card raised by session loss alone must ask again.
    await clickSubmit(personPage.getByTestId('account-blocked-sign-out'))
    await expect(personPage.getByTestId('account-blocked')).toHaveCount(0)
    await clickSubmit(personPage.getByTestId('message-signin'))
    await login(personPage, person.email, PASSWORD)
    await expect(personPage.getByTestId('account-blocked')).toBeVisible()
    await personPage.setViewportSize({ width: 375, height: 900 })
    await expect(personPage.getByTestId('data-export')).toContainText(
      'Your data',
    )

    const otherTab = await personContext.newPage()
    // The block card replaces the page outlet, so there is no page-ready marker to await.
    await gotoAdmitted(otherTab, '/')
    await expect(otherTab.getByTestId('account-blocked')).toBeVisible()
    await clickSubmit(personPage.getByTestId('data-export-prepare'))
    await expect(personPage.getByTestId('data-export-ready')).toBeVisible()
    await expect(personPage.getByTestId('data-export-step-up')).toHaveCount(0)
    await expect(otherTab.getByTestId('data-export-ready')).toBeVisible()

    const downloading = personPage.waitForEvent('download')
    await clickSubmit(personPage.getByTestId('data-export-download'))
    const download = await downloading
    expect(download.suggestedFilename()).toMatch(
      /^your-data-\d{4}-\d{2}-\d{2}\.zip$/,
    )
    expect(await download.failure()).toBeNull()
    const path = await download.path()
    if (path === null) throw new Error('The browser did not save the export')
    const bytes = await readFile(path)
    expect(bytes.length).toBeGreaterThan(0)
    expect(bytes.subarray(0, 2).toString()).toBe('PK')

    const refused = await strangerContext.request.get('/_hilos/data-export')
    expect(refused.status()).toBe(401)
    expect(refused.headers()['cache-control']).toContain('no-store')
  } finally {
    await personContext.close()
    await strangerContext.close()
  }
})
