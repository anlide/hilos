import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  clickSubmit,
  typeInto,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { walkLogStore } from '../../../../../framework/frontend/scripts/standLogs.mjs'
import {
  dictateModelAnswer,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { answerBotNow, openBotEdit, watchBotSearch } from '../helpers/bots.js'

// spec-owner: demo — chat's live bot agents and the replies they publish

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

interface ActivatedBot {
  name: string
  description: string
}

/** Open the settings table with one key in its live window. */
async function openSetting(page: Page, key: string): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings')
  await typeInto(page.getByTestId('hilos-table-search'), key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

/**
 * Activate a leader and put a unique key in the prompt it will send to the model.
 *
 * @returns The activated bot's id.
 */
async function activateBot(
  page: Page,
  name: string,
  key: string,
  activated: ActivatedBot[],
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<number> {
  const id = Number(await openBotEdit(page, name, windows))
  const description = await page
    .getByTestId('admin-bots-description')
    .inputValue()
  activated.push({ name, description })
  await typeInto(
    page.getByTestId('admin-bots-description'),
    `${description} ${key}`,
  )
  await page.getByTestId('admin-bots-active').check()
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

  return id
}

/** Restore a seeded bot so another spec sees its original fixture. */
async function restoreBot(
  page: Page,
  bot: ActivatedBot,
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<void> {
  await openBotEdit(page, bot.name, windows)
  await typeInto(page.getByTestId('admin-bots-description'), bot.description)
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

// FIXME(F-055, 2026-10-30): Expected empty-model error does not appear in Logs · свой · попытка 3 · HIL-1636
test(
  'a saved invalid bot model logs an error and a later request uses the restored model',
  { tag: '@quarantine:F-055' },
  async ({ page }) => {
    // One leader and no chat message: each request is the test-only command that
    // sends the bot to the model now, past its delay and its chance to decline.
    const windows = await watchBotSearch(page)
    await grantAdminToSelf(expect, page)
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

      const botId = await activateBot(
        page,
        'Marcus',
        botKey,
        activated,
        windows,
      )
      await answerBotNow(botId)

      await gotoPage(expect, page, '/hilos/logs')
      const error = page
        .getByTestId('hilos-logs-recent-row')
        .filter({ hasText: 'Model is required for Ollama' })
        .filter({ hasText: 'agent-bot_' })
      // The command is accepted before the bot reaches the model, and a line
      // reaches this screen by the walk of the log store: walk before each probe.
      await expect
        .poll(async () => {
          await walkLogStore()
          return error.first().isVisible()
        })
        .toBe(true)
      await gotoPage(expect, page, '/')
      await expect(
        page.getByTestId('event-text').filter({ hasText: answer }),
      ).toHaveCount(0)

      await openSetting(page, settingKey)
      await clearCustomSetting(page, settingKey)
      changed = false

      await answerBotNow(botId)
      await gotoPage(expect, page, '/')
      await expect(
        page.getByTestId('event-text').filter({ hasText: answer }),
      ).toHaveCount(1)
    } finally {
      if (changed) {
        await openSetting(page, settingKey)
        await clearCustomSetting(page, settingKey)
      }
      for (const bot of activated.reverse()) {
        await restoreBot(page, bot, windows)
      }
    }
  },
)
