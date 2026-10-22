import { expect, test, type Page } from '@playwright/test'

import {
  shownByTestId,
  watchTableModalFocus,
  watchTableSearch,
} from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from './page.js'
import { clickSubmit, typeInto } from './session.js'
import { tableRowKeyByText } from './table.js'

/** Observe every document before a seeded-bot search starts. */
export function watchBotSearch(page: Page) {
  return watchTableSearch(page, { page: 'admin_bots', tableKey: 'bots' })
}

/** Find one seeded name, consume its final window, then open its actual occurrence. */
export async function openBotEdit(
  page: Page,
  name: string,
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<void> {
  await gotoPage(page, '/hilos/app/bots')
  const mark = await windows.mark()
  let target: Awaited<ReturnType<typeof watchTableModalFocus>> | undefined
  const event: Record<string, unknown> = { name, mark }
  try {
    await typeInto(page.getByTestId('hilos-table-search'), name)
    // A semantic seeded-name lookup addresses the record. The searched window
    // must contain exactly this one key, with every typing reply processed.
    const rowKey = await tableRowKeyByText(page, name)
    await windows.settled(expect, mark, name, [rowKey])
    const trigger = shownByTestId(page, `admin-bots-edit-${rowKey}`)
    target = await watchTableModalFocus(trigger)
    event.before = await target.read()
    expect(event.before).toMatchObject({
      openerConnected: true,
      sameTrigger: true,
      visibleTriggers: 1,
    })
    await clickSubmit(trigger)
    await expect(page.getByTestId('admin-bots-name')).toHaveValue(name)
    event.opened = await target.read()
    expect(event.opened).toMatchObject({
      openerConnected: true,
      sameTrigger: true,
      inDialog: true,
    })
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
