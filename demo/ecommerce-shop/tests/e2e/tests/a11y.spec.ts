import { expect, test } from '@playwright/test'

import {
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

test('Node pages expose one entity heading, link tabs and tables named through the Shell', async ({
  page,
}) => {
  // Each addressed page is a real subscription opened from the keyboard.
  test.slow()
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, DAEMON_NODE_PATH)
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node', 'standalone', {
    showLogLinks: false,
  })
  for (const tab of DAEMON_NODE_TABS.slice(1)) {
    const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
    await link.focus()
    await expect(link).toBeFocused()
    await link.press('Enter')
    await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
    await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
      showLogLinks: false,
    })
    if (tab.key !== 'hilos_daemon_env')
      await expectDaemonNodeTableName(expect, page)
    await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
    await expectDaemonNodeHead(
      expect,
      page,
      'hilos_daemon_node',
      'standalone',
      {
        showLogLinks: false,
      },
    )
  }
})
