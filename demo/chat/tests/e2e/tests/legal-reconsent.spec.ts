import { expect, test, type Page } from '@playwright/test'
import { setAdmin } from '../helpers/adminGrant.js'
import { holdLegalRevision } from '../helpers/legalHold.js'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  clickSubmit,
  login,
  logout,
  signUp,
  typeInto,
} from '../helpers/session.js'
import {
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'

// "The terms have changed" (HIL-500). Chat's third terms revision is substantial
// and took effect the day it was published, so a person the test:legal:hold
// command puts back on the second is past the deadline at once: frozen under
// the default setting, reminded under "remind". The count of days left inside a
// window has no browser case - there is no shared clock to move - and is held by
// the SDK unit tests and the framework integration test.

/** The revision the person is put back on: the one before the demo's reset clause. */
const HELD_TERMS = '2026-09-27'

/**
 * Edits the refusal setting through its admin page, in a tab of its own.
 *
 * @param admin A page signed in as an administrator.
 * @param value 'freeze' or 'remind'.
 */
async function setRefusal(admin: Page, value: string): Promise<void> {
  await gotoPage(admin, '/hilos/legal/settings')
  await clickSubmit(
    shownByTestId(admin, 'legal-setting-edit-legal.refusal_after_deadline'),
  )
  const input = admin.getByTestId('legal-setting-input')
  await expect(input).toBeVisible()
  if ((await input.inputValue()) === value) {
    await clickSubmit(admin.getByTestId('legal-setting-cancel'))
  } else {
    await input.selectOption(value)
    await clickSubmit(admin.getByTestId('legal-setting-save'))
  }
  await expect(input).toBeHidden()
}

test('a person past the deadline is frozen until they accept, and keeps their data', async ({
  page,
}) => {
  const person = await signUp(page)

  const held = await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
  expect(held.standing).toBe('lapsed')
  expect(held.frozen).toBe(true)

  // The open tab learns the freeze on the sessions library's next tick.
  const screen = page.getByTestId('legal-frozen-screen')
  await expect(screen).toBeVisible()
  await expect(screen.getByTestId('legal-reconsent-badge')).toContainText(
    'account frozen · deadline passed 1 October 2026',
  )
  const change = screen.getByTestId('legal-reconsent-change')
  await expect(change).toHaveCount(1)
  await expect(change).toHaveAttribute('data-clause', 'standard.availability')
  await expect(screen.getByTestId('legal-reconsent-later')).toHaveCount(0)
  await expect(page.getByTestId('legal-reconsent-icon')).toHaveCount(0)
  await expect(page.getByTestId('legal-reconsent-modal')).toHaveCount(0)

  // The person's data stays theirs: its page is open while frozen.
  await screen.getByTestId('legal-reconsent-data-link').click()
  await expect(page).toHaveURL(/\/profile\/data$/)
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)

  // Back through the shell's own link: a frozen page shows no outlet to wait on.
  await page.getByTestId('nav-brand').click()
  await expect(screen).toBeVisible()
  await clickSubmit(screen.getByTestId('legal-reconsent-accept'))

  // Accepting opens the product at once, without a reload.
  await expect(screen).toHaveCount(0)
  const key = modelKey()
  const text = `accepted the new terms ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))
  await expect(
    page.getByTestId('event-text').filter({ hasText: text }),
  ).toBeVisible()
})

test('under "remind" the window rises on sign-in and the header icon keeps the reminder', async ({
  page,
}) => {
  // The setting is the whole node's, which is safe only because the chat suite
  // runs in a single worker; it goes back to "freeze" whatever happens below.
  const admin = await signUp(page)
  await setAdmin(admin.userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  try {
    await setRefusal(page, 'remind')
    await logout(page)

    const person = await signUp(page)
    const held = await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
    expect(held.standing).toBe('lapsed')
    expect(held.frozen).toBe(false)

    // Mid-work nothing rises over the page: only the icon appears.
    const icon = page.getByTestId('legal-reconsent-icon')
    await expect(icon).toBeVisible()
    await expect(icon).toHaveAttribute(
      'title',
      'The terms have changed — please review them',
    )
    await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
    await expect(page.getByTestId('legal-reconsent-modal')).toHaveCount(0)

    // A sign-in in this tab raises the window by itself.
    await logout(page)
    await page.getByTestId('message-signin').click()
    await login(page, person.email)
    const window = page.getByTestId('legal-reconsent-modal')
    await expect(window).toBeVisible()
    await expect(window.getByTestId('legal-reconsent-badge')).toContainText(
      'deadline passed 1 October 2026',
    )
    await expect(window.getByTestId('legal-reconsent-person')).toContainText(
      person.name,
    )

    // Later closes it and records nothing: the icon stays.
    await clickSubmit(window.getByTestId('legal-reconsent-later'))
    await expect(window).toHaveCount(0)
    await expect(icon).toBeVisible()

    await icon.click()
    await expect(window).toBeVisible()
    await clickSubmit(window.getByTestId('legal-reconsent-refuse'))
    const step = window.getByTestId('legal-reconsent-refuse-step')
    await expect(step).toContainText(
      'Nothing changes: this reminder will keep coming back.',
    )
    await expect(step.getByTestId('legal-reconsent-data-link')).toBeVisible()
    await expect(step.getByTestId('legal-reconsent-delete-link')).toBeVisible()
    await clickSubmit(window.getByTestId('legal-reconsent-close'))
    await expect(window).toHaveCount(0)

    await page.setViewportSize({ width: 375, height: 812 })
    await icon.click()
    await expect(window).toBeVisible()
    await expect(window.getByTestId('legal-reconsent-accept')).toBeEnabled()
    expect(await sidewaysOverflow(page)).toEqual([0, 0])
    await clickSubmit(window.getByTestId('legal-reconsent-accept'))
    await expect(window).toHaveCount(0)
    await expect(icon).toHaveCount(0)
  } finally {
    const cleanup = await page.context().newPage()
    try {
      await gotoPage(cleanup, '/')
      if ((await cleanup.getByTestId('nav-logout').count()) > 0) {
        await logout(cleanup)
      }
      await cleanup.getByTestId('message-signin').click()
      await login(cleanup, admin.email)
      await setRefusal(cleanup, 'freeze')
    } finally {
      await cleanup.close()
    }
  }
})

test('a person frozen elsewhere sees the screen on every page but the open ones', async ({
  page,
}) => {
  const person = await signUp(page)
  await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
  await expect(page.getByTestId('legal-frozen-screen')).toBeVisible()

  await gotoPage(page, '/profile/agreements', PAGE_READY)
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
  await page.getByTestId('nav-profile').click()
  await expect(page).toHaveURL(/\/profile$/)
  await expect(page.getByTestId('legal-frozen-screen')).toBeVisible()

  // Accept, so the suite's later specs meet no frozen account.
  await clickSubmit(
    page
      .getByTestId('legal-frozen-screen')
      .getByTestId('legal-reconsent-accept'),
  )
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
})
