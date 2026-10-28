import { test, expect } from '@playwright/test'
import type { Browser, BrowserContext, Locator, Page } from '@playwright/test'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import {
  dismissToasts,
  expectMaintenanceOpen,
  MAINTENANCE_PATH,
  presentMaintenanceCode,
  shownByTestId,
  expectPageReady,
  expectSelfReload,
  gotoPage,
  markDocument,
  grantAdminToSelf,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  inspectProtectedMode,
  openProtectedModeIfAny,
  ProtectedModeCommandRefused,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'

// A real restore through the Backups screen (HIL-1679), on the demo that carries
// the backup area. The freeze is the one the backup agent asks for when an
// operator confirms a full archive. The initiating browser then works from
// Maintenance: the ladder, a code, and the two exits. The test driver's freeze
// stays in backup-reopen.spec.ts.
//
// The runner is serialized (CI=1 → workers: 1) and the database reset belongs to
// test:e2e-full. Teardown opens whatever freeze this file left, then deletes the
// archive so a shared storage directory does not keep it.

const BACKUP_PATH = '/hilos/backup'
const RESTORE_OPERATION = 'restore'
const BACKUP_AGENT = 'hilos_backup'
const READY_CARD = /Backup "([^"]+)" is ready\./

/** The three controls on the initiating browser's ladder. */
const RESTORE_ACTIONS = [
  'hilos-maintenance-restore-pass',
  'hilos-maintenance-restore-open',
  'hilos-maintenance-restore-close',
] as const

/**
 * The sentence a close shows when its result never arrives.
 * Copied from HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.unconfirmedNote. The e2e
 * package builds against no framework source.
 */
const CLOSE_UNCONFIRMED =
  'The result of closing the window is unconfirmed. Check the state in Maintenance before trying again.'

// Measured on a quiet box: the open scenario 10.0s, the close scenario's
// body inside the same cap. The waits below are ceilings for the dump and
// the replay, not a raised test cap.
const ARCHIVE_MS = 120_000
const FREEZE_MS = 90_000
const REPLAY_MS = 180_000

const COMMAND_HOST =
  process.env.COMMAND_HOST ?? 'binance-btc-tracker-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const OPERATOR_OPEN = 'protected-mode:open'
const REPLY_TIMEOUT_MS = 15_000

const sendOperatorOpen = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
  refuse: (message: string) => new ProtectedModeCommandRefused(message),
})

/** Archive this file created and still has to remove. Empty once it is gone. */
let leftoverBackupId = ''

test.afterEach(async ({ page }) => {
  // Open, not leave. The test driver's open lifts a freeze that driver
  // started, and a manual window. While a restore is still active that
  // driver is stopped, and asking it waits out a silence before the
  // operator command that can open this freeze. The operator command is
  // asked again while the browser's own close still holds its slot.
  const before = await inspectProtectedMode()
  if (before.operation !== RESTORE_OPERATION || before.phase === 'inactive') {
    await openProtectedModeIfAny()
  }
  await openOperatorProtectedModeIfAny(page)
  await deleteLeftoverBackup(page)
})

test('a real restore lets the initiating browser open the system from Maintenance, and the minted code no longer admits', async ({
  page,
  browser,
}) => {
  const party = await openOutsiders(browser, 2)
  const admitted = party.pages[0]
  const waiting = party.pages[1]
  if (admitted === undefined || waiting === undefined) {
    throw new Error(
      'the restore needs two administrators besides the initiator',
    )
  }

  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, BACKUP_PATH)
    const backupId = await captureFullBackup(page)
    await replayBackup(page, backupId)

    // The window is open. Backups points the initiator to the Maintenance ladder.
    const reopen = page.getByTestId('hilos-backup-reopen')
    await expect(reopen).toHaveAttribute('href', MAINTENANCE_PATH)
    await reopen.click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/maintenance$/)
    await expectInitiatorLadder(page)

    const code = await mintRestoreCode(page)
    await expect(page.getByTestId('hilos-maintenance-pass-count')).toHaveText(
      '1',
    )

    // The code is the door. One administrator is let through it and reads the
    // other-browser line. The one still at the door has the field and has not
    // used the code.
    await expect(waiting.getByTestId('maintenance-pass-form')).toBeVisible()
    await presentMaintenanceCode(admitted, code)
    await expect(admitted.getByTestId('maintenance')).toBeHidden()
    await expectPageReady(expect, admitted)
    await expectOtherBrowser(admitted)
    await expectInitiatorLadder(page)

    await dismissToasts(page)
    await press(page.getByTestId('hilos-maintenance-restore-open'))
    await expect(
      page.getByTestId('hilos-maintenance-restore-open-modal'),
    ).toBeVisible()
    // Marked before the confirm: the lift reloads every open page, and a mark
    // set afterwards can land on the document that replaced this one.
    for (const openPage of [page, admitted, waiting]) {
      await markDocument(openPage)
    }
    await press(page.getByTestId('hilos-maintenance-restore-open-confirm'))

    for (const openPage of [page, admitted, waiting]) {
      await expectSelfReload(
        openPage,
        test.info().project.use.navigationTimeout,
      )
      await expectPageReady(expect, openPage)
      await expect(openPage.getByTestId('maintenance')).toBeHidden()
      await expect(openPage.getByTestId('maintenance-pass-form')).toHaveCount(0)
      await expect(openPage.getByTestId('conn-state')).toHaveText('connected')
      await expectMaintenanceOpen(expect, openPage)
      await expect(openPage.getByTestId('hilos-toasts')).not.toContainText(code)
    }
  } finally {
    await party.close()
  }
})

test('closing the restore window from Maintenance takes the page and the dialog away and returns every browser to the stub', async ({
  page,
  browser,
}) => {
  const party = await openOutsiders(browser, 1)
  const admitted = party.pages[0]
  if (admitted === undefined) {
    throw new Error('the close needs the administrator the code lets in')
  }

  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, BACKUP_PATH)
    const backupId = await captureFullBackup(page)
    await replayBackup(page, backupId)
    const reopen = page.getByTestId('hilos-backup-reopen')
    await expect(reopen).toHaveAttribute('href', MAINTENANCE_PATH)
    await reopen.click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/maintenance$/)
    await expectInitiatorLadder(page)

    const code = await mintRestoreCode(page)
    await presentMaintenanceCode(admitted, code)
    await expect(admitted.getByTestId('maintenance')).toBeHidden()
    await expectPageReady(expect, admitted)
    await expectOtherBrowser(admitted)

    await dismissToasts(page)
    await press(page.getByTestId('hilos-maintenance-restore-close'))
    await expect(
      page.getByTestId('hilos-maintenance-restore-close-modal'),
    ).toBeVisible()
    await press(page.getByTestId('hilos-maintenance-restore-close-confirm'))

    // The protected-mode frame removes the section and the dialog. That is the
    // screen moving; the action's own reply can arrive after it, and a missing
    // reply must not be reported as a refusal.
    for (const openPage of [page, admitted]) {
      await expect(openPage.getByTestId('maintenance')).toBeVisible()
      await expect(openPage.getByTestId('maintenance')).toHaveAttribute(
        'data-operation',
        RESTORE_OPERATION,
      )
      await expect(
        openPage.getByTestId('hilos-maintenance-restore-close-modal'),
      ).toHaveCount(0)
      await expect(
        openPage.getByTestId('hilos-maintenance-restore-ladder'),
      ).toHaveCount(0)
      await expect(openPage.getByTestId('hilos-maintenance-state')).toHaveCount(
        0,
      )
      await expect(openPage.getByTestId('maintenance-pass-form')).toHaveCount(0)
      await expect(
        openPage.getByTestId('maintenance-pass-pending'),
      ).toHaveCount(0)
      await expect(openPage.getByTestId('hilos-toast-error')).toHaveCount(0)
      await expect(openPage.getByTestId('hilos-toasts')).not.toContainText(
        CLOSE_UNCONFIRMED,
      )
      await expect(openPage.getByTestId('hilos-toasts')).not.toContainText(code)
    }
  } finally {
    await party.close()
  }
})

/**
 * Administrators created before the freeze, each already on Maintenance.
 *
 * Registration is closed once the node freezes, so both accounts exist first.
 * They sit on the section's address, which is where the stub offers the code.
 *
 * @param browser The Playwright browser.
 * @param count How many outside administrators to open.
 * @returns Their pages, and a close that drops the contexts.
 */
async function openOutsiders(
  browser: Browser,
  count: number,
): Promise<{ pages: Page[]; close: () => Promise<void> }> {
  const contexts: BrowserContext[] = []
  try {
    const pages: Page[] = []
    for (let index = 0; index < count; index += 1) {
      const context = await browser.newContext()
      contexts.push(context)
      const outsider = await context.newPage()
      pages.push(outsider)
      await grantAdminToSelf(expect, outsider)
      await gotoPage(expect, outsider, MAINTENANCE_PATH)
    }

    return {
      pages,
      close: async () => {
        for (const context of contexts) await context.close()
      },
    }
  } catch (error) {
    for (const context of contexts) await context.close()
    throw error
  }
}

/**
 * Run one full backup from the page's own dialog and name the archive it made.
 *
 * The name is read off the card the finished run raises. Scope full is what a
 * restore of this stand replays; the dialog opens on that scope and the test
 * picks it anyway.
 *
 * @param page Page of an admin standing on the backup page.
 * @returns The backup id.
 */
async function captureFullBackup(page: Page): Promise<string> {
  await press(shownByTestId(page, 'hilos-table-main-action'))
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeVisible()
  await dialog.getByTestId('hilos-backup-create-scope').selectOption('full')
  await press(dialog.getByTestId('hilos-backup-create-confirm'))
  await expect(dialog).toBeHidden()
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)

  const card = page.getByTestId('hilos-toasts').getByText(READY_CARD)
  await expect(card).toBeVisible({ timeout: ARCHIVE_MS })
  const backupId = READY_CARD.exec((await card.textContent()) ?? '')?.[1] ?? ''
  expect(backupId).not.toBe('')
  leftoverBackupId = backupId
  await dismissToasts(page)

  await expect
    .poll(
      async () => {
        await gotoPage(expect, page, BACKUP_PATH)
        return page
          .locator(`[data-id="hilos-backup-restore-${backupId}"]`)
          .count()
      },
      { timeout: ARCHIVE_MS },
    )
    .toBeGreaterThan(0)
  await expect(page.getByTestId(`hilos-table-row-${backupId}`)).toContainText(
    'full',
  )

  return backupId
}

/**
 * Confirm the typed id and wait until the verification window has the initiator.
 *
 * The stub comes up for the replay, carrying this operation, and goes away when
 * the window opens. The banner is that window on the browser that started it.
 *
 * @param page The initiating browser, on the backup page, with the archive in view.
 * @param backupId Archive to replay.
 */
async function replayBackup(page: Page, backupId: string): Promise<void> {
  await dismissToasts(page)
  await press(shownByTestId(page, `hilos-backup-restore-${backupId}`))
  const input = page.getByTestId('hilos-backup-restore-id')
  await expect(input).toBeVisible()
  await typeInto(input, backupId)
  await press(page.getByTestId('hilos-backup-restore-confirm'))
  await expect(page.getByTestId('hilos-backup-restore-confirm')).toHaveCount(0)

  const stub = page.getByTestId('maintenance')
  await expect(stub).toBeVisible({ timeout: FREEZE_MS })
  await expect(stub).toHaveAttribute('data-operation', RESTORE_OPERATION)
  await expect(page.getByTestId('protected-mode-banner')).toBeVisible({
    timeout: REPLAY_MS,
  })
  await expect(stub).toBeHidden()
}

/**
 * The initiating browser's ladder: the card, the three actions, and the backup agent.
 *
 * @param page The initiating browser, on Maintenance, inside the window.
 */
async function expectInitiatorLadder(page: Page): Promise<void> {
  await expect(
    page.getByTestId('hilos-maintenance-restore-ladder'),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-maintenance-initiator')).toContainText(
    BACKUP_AGENT,
  )
  await expect(page.getByTestId('hilos-maintenance-other-browser')).toHaveCount(
    0,
  )
  for (const id of RESTORE_ACTIONS) {
    const button = page.getByTestId(id)
    await expect(button).toBeVisible()
    await expect(button).toBeEnabled()
  }
}

/**
 * A browser the code let in: the line, and none of the three actions.
 *
 * @param page The admitted browser, on Maintenance.
 */
async function expectOtherBrowser(page: Page): Promise<void> {
  await expect(
    page.getByTestId('hilos-maintenance-other-browser'),
  ).toBeVisible()
  await expect(
    page.getByTestId('hilos-maintenance-restore-ladder'),
  ).toHaveCount(0)
  for (const id of RESTORE_ACTIONS) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
}

/**
 * Mint one restore code, read it once, and close the result.
 *
 * The dialog closing is the reply having landed. The code is not left on the page.
 *
 * @param page The initiating browser, on Maintenance, with the ladder up.
 * @returns The code the result showed.
 */
async function mintRestoreCode(page: Page): Promise<string> {
  await dismissToasts(page)
  await press(page.getByTestId('hilos-maintenance-restore-pass'))
  await press(page.getByTestId('hilos-maintenance-restore-pass-confirm'))
  const value = page.getByTestId('hilos-maintenance-restore-pass-value')
  await expect(value).toBeVisible()
  const code = (await value.innerText()).trim()
  expect(code).not.toBe('')
  await expect(page.getByTestId('hilos-toasts')).not.toContainText(code)
  await press(page.getByTestId('hilos-maintenance-restore-pass-cancel'))
  await expect(
    page.getByTestId('hilos-maintenance-restore-pass-modal'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-maintenance-restore-pass-value'),
  ).toHaveCount(0)
  await expect(page.getByTestId('hilos-maintenance-state')).not.toContainText(
    code,
  )

  return code
}

/**
 * Presses a control the way a person does: into view, shown, enabled, focused, clicked.
 *
 * @param control The control to press.
 */
async function press(control: Locator): Promise<void> {
  await control.scrollIntoViewIfNeeded()
  await expect(control).toBeVisible()
  await expect(control).toBeEnabled()
  await control.focus()
  await control.click()
}

/**
 * Opens a restore freeze through the backup agent, which owns `protected-mode:open`.
 *
 * A node that is already open is left alone. The browser close keeps the
 * operator slot until the agent's tick sees the active phase, so an open
 * asked in that gap is refused and asked again. Any other refusal that
 * leaves the freeze standing fails here, in the spec that left it.
 *
 * @param page The test's page, so a lift this call caused can be waited out.
 */
async function openOperatorProtectedModeIfAny(page: Page): Promise<void> {
  const before = await inspectProtectedMode()
  if (before.phase === 'inactive') return

  await markDocument(page)
  let caused = false
  await expect
    .poll(
      async () => {
        try {
          await sendOperatorOpen(OPERATOR_OPEN, {})
          caused = true
          return 'opened'
        } catch (error) {
          const after = await inspectProtectedMode()
          if (after.phase === 'inactive') return 'opened'
          if (
            error instanceof ProtectedModeCommandRefused &&
            error.message.includes('still in flight')
          ) {
            return 'busy'
          }
          throw error
        }
      },
      { timeout: 20_000, intervals: [200, 500, 1_000] },
    )
    .toBe('opened')

  if (caused)
    await expectSelfReload(page, test.info().project.use.navigationTimeout)
}

/**
 * Deletes the archive this file created, once the node is open again.
 *
 * @param page A page that can open Backups.
 */
async function deleteLeftoverBackup(page: Page): Promise<void> {
  const backupId = leftoverBackupId
  if (backupId === '') return

  await gotoPage(expect, page, BACKUP_PATH)
  await dismissToasts(page)
  const remove = shownByTestId(page, `hilos-backup-delete-${backupId}`)
  await expect(remove).toBeVisible({ timeout: ARCHIVE_MS })
  await press(remove)
  await press(page.getByTestId('hilos-backup-delete-confirm'))
  await expect(
    page.locator(
      `[data-id="hilos-table-row-${backupId}"] [data-id^="hilos-backup-delete-"]`,
    ),
  ).toHaveCount(0, { timeout: ARCHIVE_MS })
  leftoverBackupId = ''
}
