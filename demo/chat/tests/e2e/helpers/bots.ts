import { expect, test, type Page } from '@playwright/test'

import {
  shownByTestId,
  watchTableModalFocus,
  watchTableSearch,
  clickSubmit,
  typeInto,
  gotoPage,
  tableRowKeyByText,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// A bot's own reaction waits a random delay and draws a chance. A spec that
// needs a bot's answer asks Chat's test-only command to send that bot to the
// model now, and then waits for the outcome over the socket.

const BOT_ANSWER_NOW_COMMAND = 'test:chat:bot-answer-now'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

/** Observe every document before a seeded-bot search starts. */
export function watchBotSearch(page: Page) {
  return watchTableSearch(page, { page: 'admin_bots', tableKey: 'bots' })
}

/**
 * Find one seeded name, consume its final window, then open its actual occurrence.
 *
 * @returns The row key of the opened bot, which is its id.
 */
export async function openBotEdit(
  page: Page,
  name: string,
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<string> {
  await gotoPage(expect, page, '/hilos/app/bots')
  const mark = await windows.mark()
  let target: Awaited<ReturnType<typeof watchTableModalFocus>> | undefined
  const event: Record<string, unknown> = { name, mark }
  try {
    await typeInto(page.getByTestId('hilos-table-search'), name)
    // A semantic seeded-name lookup addresses the record. The searched window
    // must contain exactly this one key, with every typing reply processed.
    const rowKey = await tableRowKeyByText(expect, page, name)
    await windows.settled(expect, mark, name, [rowKey])
    const trigger = shownByTestId(page, `admin-bots-edit-${rowKey}`)
    target = await watchTableModalFocus(trigger)
    event.before = await target.read()
    expect(event.before).toMatchObject({
      openerConnected: true,
      sameTrigger: true,
      visibleTriggers: 1,
    })
    await clickSubmit(expect, trigger)
    await expect(page.getByTestId('admin-bots-name')).toHaveValue(name)
    event.opened = await target.read()
    expect(event.opened).toMatchObject({
      openerConnected: true,
      sameTrigger: true,
      inDialog: true,
    })

    return rowKey
  } finally {
    await test.info().attach(`bot-search-${name}`, {
      body: JSON.stringify(
        { event, frames: await windows.read(), target: await target?.read() },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    await target?.dispose()
  }
}

/**
 * Have one active bot go to the model now.
 *
 * Chat's library hands the bot's agent the answer-now signal and accepts at
 * once, without waiting for the model: the agent's next free tick goes to the
 * model past the reaction delay, the chance, the pause after its own message
 * and the topic. The production values do not change. A stopped or unknown
 * bot is refused here and now rather than later as an answer that never comes.
 *
 * @param botId The active bot that answers now.
 * @throws {Error} When the command is refused or answers for another bot.
 */
export async function answerBotNow(botId: number): Promise<void> {
  const reply = await command(BOT_ANSWER_NOW_COMMAND, { botId })
  if (
    !reply ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    Object.keys(reply).join() !== 'botId' ||
    reply.botId !== botId
  )
    throw new Error(`Invalid ${BOT_ANSWER_NOW_COMMAND} reply for bot ${botId}`)
}
