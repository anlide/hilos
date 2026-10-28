import { expect, test } from '@playwright/test'

import {
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectStandaloneWebsockets,
  watchDaemonWebsocketsFrames,
  gotoPage,
  grantAdminToSelf,
  openTableFixture,
  tableTextToggle,
  expectTableTextControl,
  expectTableTextGeometry,
  settleTableLayout,
  shownByTestId,
  watchTableModalFocus,
} from '../../../../../framework/frontend/e2e/index.js'

test('Node pages expose one entity heading, link tabs and tables named through the Shell', async ({
  page,
}) => {
  // Each addressed page is a real subscription opened from the keyboard.
  test.slow()
  const reports = watchDaemonWebsocketsFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_NODE_PATH)
    await expectDaemonNodeHead(
      expect,
      page,
      'hilos_daemon_node',
      'standalone',
      {
        showLogLinks: false,
      },
    )
    for (const tab of DAEMON_NODE_TABS.slice(1)) {
      const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
      await link.focus()
      await expect(link).toBeFocused()
      await link.press('Enter')
      await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
      await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
        showLogLinks: false,
      })
      if (tab.key === 'hilos_daemon_websockets') {
        await expectStandaloneWebsockets(expect, page, reports.frames, {
          showLogLinks: false,
        })
      } else if (tab.key !== 'hilos_daemon_env')
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
  } finally {
    reports.stop()
  }
})

test.describe('SDK reading accessibility, synthetic fixture ingress', () => {
  test.beforeEach(async ({ page }) => {
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await page.getByTestId('geometry-height-mode').selectOption('lines')
    await page.getByTestId('geometry-lines').selectOption('3')
    await page.getByTestId('geometry-height-content').selectOption('parts')
    await page.getByTestId('geometry-reading').click()
    await expectTableTextControl(expect, page, 'a', false)
  })

  test('H05 Enter/Space keep the native button and focus through live shortening and collapse', async ({
    page,
  }, info) => {
    const control = tableTextToggle(page, 'a')
    await control.focus()
    await control.press('Enter')
    await expectTableTextControl(expect, page, 'a', true)
    await expect(control).toBeFocused()
    const original = await control.elementHandle()
    await page.getByTestId('geometry-payload').selectOption('empty')
    // Synthetic ingress preserves the reader's focus; clicking a fixture
    // publisher normally would move focus to that publisher instead.
    await control.focus()
    await control.press('F7')
    await expectTableTextControl(expect, page, 'a', true)
    await expect(control).toBeFocused()
    await settleTableLayout(page)
    await info.attach('expanded-empty-minimum', {
      body: await expectTableTextGeometry(
        expect,
        page,
        'a',
        { mode: 'lines', count: 3 },
        true,
      ),
      contentType: 'application/json',
    })
    await control.press('Space')
    await expectTableTextControl(expect, page, 'a', false)
    await expect(control).toBeFocused()
    expect(
      await original!.evaluate(
        (button) =>
          button.isConnected &&
          button === document.activeElement &&
          button.textContent?.trim() === 'Show in full',
      ),
    ).toBe(true)
    await control.press('Tab')
    await expect(tableTextToggle(page, 'a')).toHaveCount(0)
    expect(await original!.evaluate((button) => button.isConnected)).toBe(false)
    await original!.dispose()
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-selected',
      'null',
    )
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-requests',
      '0',
    )
    await expect(page.getByTestId('geometry-actions')).toHaveText('')
    await expect(page.getByTestId('modal')).toHaveCount(0)
  })

  test('H05 text and detail keep independent expanded states and unique targets in both mounted branches', async ({
    page,
  }) => {
    const text = tableTextToggle(page, 'a')
    const detail = shownByTestId(page, 'hilos-table-expand-a')
    await text.focus()
    await text.press('Space')
    await expectTableTextControl(expect, page, 'a', true)
    await expect(detail).toHaveAttribute('aria-expanded', 'false')
    await detail.focus()
    await detail.press('Enter')
    await expect(detail).toHaveAttribute('aria-expanded', 'true')
    await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
    await expectTableTextControl(expect, page, 'a', true)
    const targets = await page
      .getByTestId('hilos-table-row-a')
      .getByTestId('hilos-table-text-toggle')
      .getAttribute('aria-controls')
    expect(targets).not.toBe(await detail.getAttribute('aria-controls'))
    const ids = await page
      .locator('[id]')
      .evaluateAll((nodes) => nodes.map((node) => node.id))
    expect(new Set(ids).size).toBe(ids.length)
    await text.focus()
    await text.press('Enter')
    await expectTableTextControl(expect, page, 'a', false)
    await expect(detail).toHaveAttribute('aria-expanded', 'true')
    await expect(text).toBeFocused()
  })

  for (const focus of [
    'text',
    'detail',
    'action',
    'outside',
    'root',
  ] as const) {
    test(`H06/B05 ${focus}: departure returns focus only from the departing occurrence`, async ({
      page,
    }) => {
      if (focus === 'root') {
        await page.getByTestId('geometry-no-search').click()
        await expectTableTextControl(expect, page, 'a', false)
      }
      const text = tableTextToggle(page, 'a')
      await text.click()
      await expectTableTextControl(expect, page, 'a', true)
      if (focus === 'detail') {
        await shownByTestId(page, 'hilos-table-expand-a').click()
        await expect(
          shownByTestId(page, 'geometry-detail-action-a'),
        ).toBeVisible()
      }
      const control =
        focus === 'outside'
          ? page.getByTestId('geometry-target')
          : focus === 'detail'
            ? shownByTestId(page, 'geometry-detail-action-a')
            : focus === 'action'
              ? shownByTestId(page, 'geometry-action-a')
              : text
      await control.focus()
      await expect(control).toBeFocused()
      await control.press('F8')
      await expect(page.getByTestId('geometry-text-a')).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-table-row-a')
          .getByTestId('hilos-table-text-toggle'),
      ).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-table-card-a')
          .getByTestId('hilos-table-text-toggle'),
      ).toHaveCount(0)
      await settleTableLayout(page)
      await expect(
        focus === 'outside'
          ? control
          : page.getByTestId(
              focus === 'root' ? 'hilos-viewport-table' : 'hilos-table-search',
            ),
      ).toBeFocused()
    })
  }

  test('H06 modal keeps focus through reading departure and returns to the table fallback', async ({
    page,
  }, info) => {
    await tableTextToggle(page, 'a').click()
    await expectTableTextControl(expect, page, 'a', true)
    const trigger = shownByTestId(page, 'geometry-action-a')
    const focus = await watchTableModalFocus(trigger)
    try {
      await trigger.focus()
      await trigger.press('Enter')
      const modal = page.getByTestId('modal')
      await expect(modal).toBeFocused()
      await expect(modal).toHaveAccessibleName('Inspect geometry record')
      await page.getByTestId('geometry-modal-remove').focus()
      await page.getByTestId('geometry-modal-remove').press('Enter')
      await expect(page.getByTestId('geometry-text-a')).toHaveCount(0)
      await settleTableLayout(page)
      const removed = await focus.read()
      expect(removed.inDialog).toBe(true)
      expect(removed.openerConnected).toBe(false)
      expect(removed.visibleTriggers).toBe(0)
      await expect(page.getByTestId('geometry-modal-remove')).toBeFocused()
      await modal.press('Escape')
      await expect(modal).toHaveCount(0)
      await expect(page.getByTestId('hilos-table-search')).toBeFocused()
      await info.attach('reading-modal-focus', {
        body: JSON.stringify(
          { removed, returned: await focus.read() },
          null,
          2,
        ),
        contentType: 'application/json',
      })
    } finally {
      await focus.dispose()
    }
  })
})
