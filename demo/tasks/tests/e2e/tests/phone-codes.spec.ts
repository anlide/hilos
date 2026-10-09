import { test, expect, type Locator } from '@playwright/test'

import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'
import {
  uniquePhone,
  waitForSmsCode,
} from '../../../../../framework/frontend/scripts/standSms.mjs'
import {
  setTelegramReachable,
  waitForTelegramCode,
} from '../../../../../framework/frontend/scripts/standTelegram.mjs'
import { readRegisterCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'
import {
  clickSubmit,
  continueFromDone,
  logout,
  openSignIn,
  PASSWORD,
  signInByPhone,
  submitFirstPassword,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session'

// Code channels (HIL-492). Delivery of a login code became a registry, so what is
// exercised here is what a registry is FOR: the same number reaches its account
// through whichever channel can carry it, and a channel that cannot costs the person
// nothing on the way to one that can.
//
// The Telegram leg goes through the stand's mock Gateway
// (framework/frontend/scripts/standTelegram.mjs) rather than around it: the daemon
// builds a real request, posts it, and the mock refuses one that carries no bearer
// token — so a transport quietly removed would fail here.
//
// Not covered, and deliberately: a project whose registry has no Telegram at all draws
// no icon row. That is a different build of the demo, not a state a spec can arrange,
// and the channel list is unit-tested instead (CodeChannelRegistryTest).

test('signs in with the code delivered over Telegram', async ({ page }) => {
  // No global reset: every state the mock holds is keyed by number, and the number
  // is unique per test, so these specs are isolated without reaching into a store
  // the other workers share.
  const phone = uniquePhone()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(page)
  await typeInto(page.getByTestId('auth-identifier'), phone)

  // A number reveals its channels instead of a password: choosing one IS the send,
  // and there is no separate send button behind the icon. For a number with no
  // account the choice is only STORED — an account is never made by a click that
  // never showed the terms — so the terms screen is what sends.
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  // The code screen opens on the agent's outcome signal, not on the click, and it
  // names the channel the code actually went over.
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-delivered-channel')).toContainText(
    'Telegram',
  )

  await typeInto(
    page.getByTestId('auth-code'),
    await waitForTelegramCode(phone),
  )
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)

  await expect(page.getByTestId('self-user')).toHaveText(/^User[1-9]\d{5}$/)
})

test('leaves a number that is not on Telegram free to sign in by SMS', async ({
  page,
}) => {
  const phone = uniquePhone()
  await setTelegramReachable(phone, false)

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(page)
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  // The refusal says so where the send was made from, and the code screen never
  // opens: nothing was minted, so there is no code to enter.
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toHaveCount(0)

  // Back at the field the refused channel is dimmed — it is dimmed about this
  // NUMBER, so it stays that way until the number is edited.
  await page.getByTestId('auth-restart').click()
  await expect(page.getByTestId('auth-channel-telegram')).toBeDisabled()

  // The whole point of probing before minting: SMS still has this number's first
  // code to give, because the refused channel spent no cooldown.
  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-delivered-channel')).toContainText('SMS')

  await typeInto(page.getByTestId('auth-code'), await waitForSmsCode(phone))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)

  await expect(page.getByTestId('self-user')).toHaveText(/^User[1-9]\d{5}$/)
})

test('says the code could not be sent when the Telegram gateway fails the send', async ({
  page,
}) => {
  // The probe still passes and only the send is refused, so this is not the "number
  // is not on Telegram" case above: the refusal travels back through the transport
  // that delivers the code, as a 500 the daemon reads as a failed send (HIL-922).
  const phone = uniquePhone()
  await dictateGatewayBehavior('/telegram/sendVerificationMessage', phone, {
    status: 500,
  })

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(page)
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  await expect(page.getByTestId('auth-error')).toContainText(
    'Could not send the code',
  )
  await expect(page.getByTestId('auth-code')).toHaveCount(0)
})

test('comes back to the phone code screen after a reload, and finishes there', async ({
  page,
}) => {
  const phone = uniquePhone()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  await expect(page.getByTestId('auth-code')).toBeVisible()
  const code = await waitForSmsCode(phone)

  // The tab keeps nothing across a reload, so the step it comes back to is the one
  // the SERVER remembers: the code went out, so the session is still waiting on this
  // number and is given its screen back rather than an empty identifier field
  // (HIL-486).
  await page.reload()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-identifier')).toHaveCount(0)

  // Down to the channel: which one carried the code is part of what is remembered,
  // because the screen has to name it and the click that chose it is gone.
  await expect(page.getByTestId('auth-delivered-channel')).toContainText('SMS')
  await expect(page.getByTestId('auth-expires-in')).toContainText(/\d+:\d{2}/)

  // And it is the same registration: the code texted before the reload is the one
  // this screen still accepts, and accepting it makes the account.
  await typeInto(page.getByTestId('auth-code'), code)
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})

test('opens the code screen of a new send with an empty field, even after a code was typed (HIL-1173)', async ({
  page,
}) => {
  const phone = uniquePhone()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await signInByPhone(page, phone)
  await logout(page)
  await gotoPage(page, '/profile')

  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(page.getByTestId('auth-code'), '000000')
  await expect(page.getByTestId('auth-code')).toHaveValue('000000')

  await page.getByTestId('auth-restart').click()
  await expect(page.getByTestId('auth-identifier')).toHaveValue(phone)

  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toHaveValue('')
})

/** Seconds in a minute, for reading the `m:ss` countdown as a number. */
const SECONDS_PER_MINUTE = 60

/**
 * Read the countdown as seconds, so two readings can be compared as numbers.
 *
 * A line that is not there yet - or says something else - answers a value no
 * countdown can hold, so a poll waiting for the number to shrink goes on waiting
 * instead of passing on the absence of one.
 *
 * @param countdown The locator of the countdown line.
 * @returns Seconds it says are left, or an unreachably large number while it says nothing readable.
 */
async function remainingSeconds(countdown: Locator): Promise<number> {
  const parts = /(\d+):(\d{2})/.exec((await countdown.textContent()) ?? '')

  return parts === null
    ? Number.MAX_SAFE_INTEGER
    : Number(parts[1]) * SECONDS_PER_MINUTE + Number(parts[2])
}

test('counts the code down and comes back to it, still counting, after a reload', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()

  // The screen says how long the code it is asking for is still good for, and the
  // number moves on its own - a caption that never changed would satisfy every
  // other assertion here (HIL-486).
  const countdown = page.getByTestId('auth-expires-in')
  await expect(countdown).toContainText(/\d+:\d{2}/)
  const started = await remainingSeconds(countdown)
  await expect
    .poll(async () => remainingSeconds(countdown))
    .toBeLessThan(started)
  const beforeReload = await remainingSeconds(countdown)

  // The whole reason the moment comes from the server instead of from a duration
  // this tab started counting: a reload has no memory of when the counting began.
  // Coming back to a FULL countdown would be the same defect as coming back to the
  // address field, so what is asserted is that it kept SHRINKING across the reload.
  await page.reload()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect
    .poll(async () => remainingSeconds(countdown))
    .toBeLessThan(beforeReload)

  // And it is the same registration: the code mailed before the reload is the one
  // this screen still accepts.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})
