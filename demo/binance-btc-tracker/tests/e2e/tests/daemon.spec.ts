import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import {
  armViewerSubmit,
  expectViewerRestartRefused,
  setAdminViewMode,
} from '../helpers/adminViewMode.js'
import {
  RESTART_AGENT,
  readRestartWorker,
  withStoppedRestartWorker,
} from '../helpers/daemonRestart.js'
import { gotoPage, PAGE_READY } from '../helpers/page'
import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'

// The tracker stand enables the framework Daemon section. Its standalone
// workers page waits for the node picture and opens a worker's agent panel.
test('shows the standalone node and its workers', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/workers', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-node-diagram')).toHaveCount(0)

  const expand = page.locator('[data-id^="hilos-table-expand-"]').first()
  await expect(expand).toBeVisible()
  await expand.click()
  await expect(
    page.locator('[data-id^="hilos-table-row-detail-"]').first(),
  ).toBeVisible()
})

test('finds the section node agent on the standalone agents page', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect.poll(() => rows.count()).toBeGreaterThan(1)

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('hilos_daemon_node', { delay: 10 })
  await expect(
    page.locator('[data-id^="hilos-table-row-hilos_daemon_node"]'),
  ).toBeVisible()
  await expect(rows).toHaveCount(1)
})

test('shows the standalone node and its schedule', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/cron', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(
    page.locator(
      '[data-id^="hilos-table-row-"][data-id$="/hilos_sessions_expire"]',
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-cron-not-leader')).toHaveCount(0)
})

test.describe('restarting an agent', () => {
  // The master's 13-second caller window must be judged on the first attempt.
  test.describe.configure({ retries: 0 })
  test.afterEach(() => setAdminViewMode(false))

  test('restarts the settings library while two viewers and a new tab see the shared mark', async ({
    page,
    context,
  }) => {
    await signUpAdmin(page)
    const worker = await readRestartWorker(page)
    await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)
    const observer = await context.newPage()
    const newcomer = await context.newPage()
    try {
      await gotoPage(observer, '/hilos/daemon/standalone/agents', PAGE_READY)
      const button = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      await button.scrollIntoViewIfNeeded()
      await expect(button).toBeVisible()
      await expect(button).toBeEnabled()
      await button.focus()
      await button.click()
      await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
        `php cli.php agent:restart ${RESTART_AGENT}`,
      )
      await withStoppedRestartWorker(worker, async () => {
        const confirm = page.getByTestId('hilos-daemon-restart-confirm')
        await confirm.scrollIntoViewIfNeeded()
        await expect(confirm).toBeVisible()
        await expect(confirm).toBeEnabled()
        await confirm.focus()
        await confirm.click()
        const mark = `hilos-daemon-agent-restarting-${RESTART_AGENT}`
        await expect(shownByTestId(page, mark)).toHaveText('Restarting')
        await expect(shownByTestId(observer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            observer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
        await gotoPage(newcomer, '/hilos/daemon/standalone/agents', PAGE_READY)
        await expect(shownByTestId(newcomer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            newcomer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
      })
      await expect(page.getByTestId('modal')).toHaveCount(0)
      for (const viewer of [page, observer, newcomer]) {
        await expect(
          viewer.getByTestId(`hilos-daemon-agent-restarting-${RESTART_AGENT}`),
        ).toHaveCount(0)
        await expect(
          shownByTestId(viewer, `hilos-daemon-agent-restart-${RESTART_AGENT}`),
        ).toBeEnabled()
        await expect(
          viewer.getByTestId(`hilos-table-row-${RESTART_AGENT}`),
        ).toBeVisible()
      }
      await expect(
        page.getByTestId('hilos-toast-success').filter({
          hasText: `Agent ${RESTART_AGENT} restarted on node standalone.`,
        }),
      ).toBeVisible()
    } finally {
      await observer.close()
      await newcomer.close()
    }
  })

  test('a view-mode viewer cannot open restart or submit a forged request', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await armViewerSubmit(page)
    await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expectViewerRestartRefused(page, RESTART_AGENT)
    await expect(page.getByTestId('modal')).toHaveCount(0)
  })
})
