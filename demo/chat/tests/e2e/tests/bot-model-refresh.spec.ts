import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  dictateModelAnswer,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'
import { tableRowKeyByText } from '../helpers/table'

// spec-owner: demo — chat's live bot agents and the replies they publish

interface ActivatedBot {
  name: string
  description: string
}

/** Open the settings table with one key in its live window. */
async function openSetting(page: Page, key: string): Promise<void> {
  await gotoPage(page, '/hilos/settings')
  await typeInto(page.getByTestId('hilos-table-search'), key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

/** Open a seeded bot's edit dialog after finding it in the live window. */
async function openBot(page: Page, name: string): Promise<void> {
  await gotoPage(page, '/hilos/app/bots')
  await typeInto(page.getByTestId('hilos-table-search'), name)
  const rowKey = await tableRowKeyByText(page, name)
  await clickSubmit(shownByTestId(page, `admin-bots-edit-${rowKey}`))
  await expect(page.getByTestId('admin-bots-name')).toHaveValue(name)
}

/** Activate a leader and put a unique key in the prompt it will send to the model. */
async function activateBot(
  page: Page,
  name: string,
  key: string,
  activated: ActivatedBot[],
): Promise<void> {
  await openBot(page, name)
  const description = await page
    .getByTestId('admin-bots-description')
    .inputValue()
  activated.push({ name, description })
  await typeInto(
    page.getByTestId('admin-bots-description'),
    `${description} ${key}`,
  )
  await page.getByTestId('admin-bots-active').check()
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/** Restore a seeded bot so another spec sees its original fixture. */
async function restoreBot(page: Page, bot: ActivatedBot): Promise<void> {
  await openBot(page, bot.name)
  await typeInto(page.getByTestId('admin-bots-description'), bot.description)
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

test('a saved invalid bot model logs an error and a later request uses the restored model', async ({
  page,
}) => {
  // The leaders each decide whether to react. Three message rounds give the role
  // another real context change if all three decline an earlier one.
  test.setTimeout(test.info().timeout * 5)
  await signUpAdmin(page)
  const settingKey = 'chat_bot_model'
  const botKey = modelKey()
  const answer = `bot recovered ${botKey}`
  const activated: ActivatedBot[] = []
  let changed = false

  try {
    await openSetting(page, settingKey)
    // The stand model accepts every nonempty name. A saved empty name makes the
    // real Ollama client refuse before it could consume this dictated answer.
    await setCustomSetting(page, settingKey, '')
    changed = true
    await dictateModelAnswer(botKey, answer)

    for (const name of ['Marcus', 'Elena', 'David']) {
      await activateBot(page, name, botKey, activated)
    }

    await gotoPage(page, '/hilos/logs')
    const error = page
      .getByTestId('hilos-logs-recent-row')
      .filter({ hasText: 'Model is required for Ollama' })
      .filter({ hasText: 'agent-bot_' })
    await expect(error.first()).toBeVisible({ timeout: 60_000 })
    await gotoPage(page, '/')
    await expect(
      page.getByTestId('event-text').filter({ hasText: answer }),
    ).toHaveCount(0)

    await openSetting(page, settingKey)
    await clearCustomSetting(page, settingKey)
    changed = false

    await gotoPage(page, '/')
    const botReply = page.getByTestId('event-text').filter({ hasText: answer })
    for (let attempt = 0; attempt < 3; attempt += 1) {
      const messageKey = modelKey()
      await dictateModerationVerdict(messageKey, true, 'ok')
      await dictateModelAnswer(
        messageKey,
        JSON.stringify({
          topic: 'technology',
          topicConfidence: 0.9,
          summary: messageKey,
        }),
      )
      await typeInto(
        page.getByTestId('message-input'),
        `please discuss ${messageKey}`,
      )
      await expect(page.getByTestId('message-send')).toBeEnabled({
        timeout: 20_000,
      })
      await clickSubmit(page.getByTestId('message-send'))
      await expect(
        page.getByTestId('event-text').filter({ hasText: messageKey }),
      ).toHaveCount(1)

      try {
        await expect(botReply).toHaveCount(1, { timeout: 25_000 })
        break
      } catch (error) {
        if (attempt === 2) {
          throw error
        }
      }
    }
  } finally {
    if (changed) {
      await openSetting(page, settingKey)
      await clearCustomSetting(page, settingKey)
    }
    for (const bot of activated.reverse()) {
      await restoreBot(page, bot)
    }
  }
})
