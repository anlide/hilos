import { expect, test } from '@playwright/test'

import { dismissToasts } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import {
  clickSubmit,
  PASSWORD,
  signUpPerson,
  typeInto,
} from '../helpers/session'

// Two independent browsers prove the card's writes reach the affected person.
test('blocks an account from its card and signs the person back in when the block is lifted', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  try {
    await signUpAdmin(page)
    const person = await signUpPerson(personPage)
    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    // Blocking is declared off in the list of confirmed operations (HIL-1275): the
    // window opens straight on its own text.
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('step-up')).toHaveCount(0)
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Account blocked. Sessions ended: 1',
    )
    await expect(personPage.getByTestId('account-blocked')).toBeVisible()
    await expect(personPage.getByTestId('account-blocked-heading')).toHaveText(
      'Access closed',
    )
    await expect(page.getByTestId('hilos-user-block-open')).toHaveText(
      'Lift the block',
    )

    await dismissToasts(page)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(personPage.getByTestId('account-blocked')).toHaveCount(0)
    // The tab that showed the card comes back signed in without signing in
    // again (HIL-1188): it trades a ticket for the new cookie and reconnects.
    await expect(personPage.getByTestId('self-user-id')).toHaveText(
      String(person.userId),
    )
  } finally {
    await personContext.close()
  }
})

test('keeps a person who signed out on the card a guest when the block is lifted', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  try {
    await signUpAdmin(page)
    const person = await signUpPerson(personPage)
    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(personPage.getByTestId('account-blocked')).toBeVisible()
    await clickSubmit(personPage.getByTestId('account-blocked-sign-out'))
    await expect(personPage.getByTestId('account-blocked')).toHaveCount(0)

    await dismissToasts(page)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-user-block-open')).toHaveText('Block')
    // Opened again after the lift, the browser goes through the handshake door,
    // which would sign it in had the card's Sign out left anything to return.
    await gotoPage(personPage, '/')
    await expect(personPage.getByTestId('self-anonymous')).toBeVisible()
    await expect(personPage.getByTestId('nav-signin')).toBeVisible()
  } finally {
    await personContext.close()
  }
})

test('schedules and cancels deletion on the card while the person sees it on their own screen', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  try {
    await signUpAdmin(page)
    const person = await signUpPerson(personPage)
    // This demo has no profile root: a scheduled deletion reaches the person as
    // the shell's strip above the page, and it arrives live with the change of
    // their standing (docs/agents/architecture/account-standing.md).
    const strip = personPage.getByTestId('account-deletion-strip')
    await expect(strip).toHaveCount(0)
    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-deletion-open'))
    // Scheduling someone else's deletion asks the administrator's own password
    // first (HIL-1275); calling it off below asks nothing.
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-step-up-confirm'))
    await expect(page.getByTestId('modal')).toContainText('After 30 days')
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-user-deletion-state')).toContainText(
      'Erased on',
    )
    await expect(page.getByTestId('hilos-user-deletion-state')).toContainText(
      '30 days left',
    )
    await expect(strip).toBeVisible()
    await expect(page.getByTestId('hilos-user-deletion-open')).toHaveText(
      'Cancel deletion',
    )

    await dismissToasts(page)
    await clickSubmit(page.getByTestId('hilos-user-deletion-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('step-up')).toHaveCount(0)
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-user-deletion-state')).toContainText(
      'No deletion is scheduled',
    )
    await expect(strip).toHaveCount(0)
  } finally {
    await personContext.close()
  }
})

// Granting rights asks the administrator to confirm it is them, removing them does
// not: removal is declared off, and nobody on this demo switches it on, so the
// test needs no order of its own.
test.describe('admin rights and their confirmation', () => {
  // Granting rights is the administrator's to confirm (HIL-1275), and the
  // confirmation lives on the operation in this browser: the second grant within
  // its lifetime opens straight on its own text.
  test('grants admin rights after the administrator confirms it is them', async ({
    browser,
    page,
  }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const personContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const personPage = await personContext.newPage()
    try {
      await signUpAdmin(page)
      const person = await signUpPerson(personPage)
      await gotoPage(page, `/hilos/user/${person.userId}`)
      await clickSubmit(page.getByTestId('hilos-user-admin-open'))
      await expect(page.getByTestId('modal')).toContainText("Confirm it's you")
      await typeInto(page.getByTestId('step-up-password'), PASSWORD)
      await clickSubmit(
        page.getByTestId('hilos-user-lifecycle-step-up-confirm'),
      )
      await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
      await expect(page.getByTestId('modal')).toBeHidden()
      await expect(page.getByTestId('hilos-toast-success')).toContainText(
        'Admin rights granted',
      )

      // Removing rights is declared off: no step.
      await dismissToasts(page)
      await clickSubmit(page.getByTestId('hilos-user-admin-open'))
      await expect(page.getByTestId('modal')).toBeVisible()
      await expect(page.getByTestId('step-up')).toHaveCount(0)
      await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
      await expect(page.getByTestId('modal')).toBeHidden()

      await dismissToasts(page)
      await clickSubmit(page.getByTestId('hilos-user-admin-open'))
      await expect(page.getByTestId('modal')).toBeVisible()
      await expect(page.getByTestId('step-up')).toHaveCount(0)
      await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
      await expect(page.getByTestId('hilos-toast-success')).toContainText(
        'Admin rights granted',
      )
    } finally {
      await personContext.close()
    }
  })
})
