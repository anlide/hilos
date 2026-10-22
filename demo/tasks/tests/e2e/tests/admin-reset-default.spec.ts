import { expect, test } from '@playwright/test'

import {
  dismissToasts,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

test('channel reset applies the env value shown in Back to', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications/email')

  const row = shownByTestId(
    page,
    'hilos-table-row-notifications.channel.email.smtp_host',
  )
  const reset = shownByTestId(page, 'hilos-channel-field-reset-smtp_host')
  await shownByTestId(page, 'hilos-channel-field-edit-smtp_host').click()
  await typeInto(
    page.getByTestId('hilos-channel-edit-value'),
    'override.example',
  )
  await clickSubmit(page.getByTestId('hilos-channel-edit-save'))
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expect(row).toContainText('override.example')
  await expect(reset).toBeEnabled()

  await dismissToasts(page)
  await reset.click()
  const back = page.getByTestId('hilos-channel-reset-default')
  await expect(back).toHaveText('tasks-mailpit-test From env')
  await expect(back.locator('.badge')).toHaveText('From env')
  const preview = (await back.innerText()).replace('From env', '').trim()
  await expect(page.getByTestId('hilos-channel-reset-now')).toHaveText(
    'override.example',
  )
  await clickSubmit(page.getByTestId('hilos-channel-reset-confirm'))
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expect(row).toContainText(preview)
  await expect(row.locator(':scope > td > .badge')).toHaveText('From env')
  await expect(reset).toBeDisabled()
  await expect(row).not.toContainText('override.example')
})

test('provider resets apply env credentials and the recipe scope without exposing secrets', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  // TasksOAuthProviderDirectory uses OAuthProviderPreset::GITHUB (oauth:github).
  await gotoPage(page, '/hilos/security/oauth/oauth:github')

  for (const field of [
    {
      key: 'client_id',
      override: 'reset-override-client',
      fallback: 'stand-github-client',
      source: 'From env',
    },
    {
      key: 'client_secret',
      override: 'reset-override-secret',
      fallback: 'Set',
      source: 'From env',
    },
    {
      key: 'scope',
      override: 'repo',
      fallback: 'read:user user:email',
      source: 'Default',
    },
  ]) {
    await dismissToasts(page)
    const value = shownByTestId(page, `hilos-oauth-field-value-${field.key}`)
    const reset = shownByTestId(page, `hilos-oauth-field-reset-${field.key}`)
    await shownByTestId(page, `hilos-oauth-field-edit-${field.key}`).click()
    await typeInto(page.getByTestId('hilos-oauth-field-input'), field.override)
    await clickSubmit(page.getByTestId('hilos-oauth-field-save'))
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(value).toHaveText(
      field.key === 'client_secret' ? 'Set' : field.override,
    )
    await expect(reset).toBeEnabled()

    await dismissToasts(page)
    await reset.click()
    const back = page.getByTestId('hilos-oauth-field-reset-default')
    await expect(back).toHaveText(`${field.fallback} ${field.source}`)
    await expect(back.locator('.badge')).toHaveText(field.source)
    const preview = (await back.innerText()).replace(field.source, '').trim()
    // The invisible twin reserves room; only this stable node is a warning.
    await expect(
      page.getByTestId('hilos-oauth-field-reset-signin'),
    ).toHaveCount(0)
    if (field.key === 'client_secret') {
      await expect(page.getByTestId('modal')).not.toContainText(
        'stand-github-secret',
      )
      await expect(page.getByTestId('modal')).not.toContainText(field.override)
    }
    await clickSubmit(page.getByTestId('hilos-oauth-field-reset-confirm'))
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(value).toHaveText(preview)
    await expect(reset).toBeDisabled()
    if (field.key === 'client_secret') {
      await expect(value).not.toContainText('stand-github-secret')
      await expect(value).not.toContainText(field.override)
    }
  }
})

test('return address reset applies the env address shown in Back to', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/security/oauth')

  const value = page.getByTestId('hilos-oauth-redirect-value')
  const reset = page.getByTestId('hilos-oauth-redirect-reset')
  await page.getByTestId('hilos-oauth-redirect-edit').click()
  await typeInto(
    page.getByTestId('hilos-oauth-redirect-input'),
    'https://override.example/auth/callback',
  )
  await clickSubmit(page.getByTestId('hilos-oauth-redirect-save'))
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expect(value).toHaveText('https://override.example/auth/callback')
  await expect(reset).toBeEnabled()

  await dismissToasts(page)
  await reset.click()
  const back = page.getByTestId('hilos-oauth-redirect-reset-default')
  await expect(back).toHaveText(
    'https://tasks-nginx-test/auth/callback From env',
  )
  await expect(back.locator('.badge')).toHaveText('From env')
  const preview = (await back.innerText()).replace('From env', '').trim()
  await clickSubmit(page.getByTestId('hilos-oauth-redirect-reset-confirm'))
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expect(value).toHaveText(preview)
  await expect(reset).toBeDisabled()
})
