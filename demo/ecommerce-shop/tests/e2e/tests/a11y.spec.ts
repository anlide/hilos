import type { Page } from '@playwright/test'
import { expect, test } from '../test.js'

import {
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  clickSubmit,
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
  expectTableTileDeparture,
  settleTableLayout,
  shownByTestId,
  watchTableModalFocus,
  PAGE_READY,
  STATUS_HISTORY_PATH,
  STATUS_PATH,
  STATUS_SUBSCRIBERS_PATH,
  cleanupFixtures,
  expectPageReady,
  expectPublicStatusAccessibility,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusSubscribeDialog,
  expectStatusSubscriberRemoveDialog,
  expectStatusSubscribersAccessibility,
  gotoPublicStatus,
  removeStatusSubscribersIfAny,
  searchedStatusSubscriberIds,
  statusSubscriberId,
  subscribeToStatus,
  typeInto,
  uniqueEmail,
  watchDeliveryFrames,
  watchPublicStatus,
  type TestCleanup,
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

test.describe('SDK Tiles accessibility, synthetic fixture ingress', () => {
  test.beforeEach(async ({ page }) => {
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await page.getByTestId('geometry-tiles').check()
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await expect(page.getByTestId('hilos-table-view-table')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  async function expectUniqueIds(page: Page): Promise<void> {
    const ids = await page
      .locator('[id]')
      .evaluateAll((nodes) => nodes.map((node) => node.id))
    expect(new Set(ids).size).toBe(ids.length)
  }

  test('T01 Tab, Enter and Space switch the view on buttons that keep focus; a tile reads and opens through unique targets of a named list', async ({
    page,
  }) => {
    const state = page.getByTestId('geometry-state')
    const root = page.getByTestId('hilos-viewport-table')
    const table = page.getByTestId('hilos-table-view-table')
    const tiles = page.getByTestId('hilos-table-view-tiles')
    const list = root.getByRole('list', {
      name: 'Geometry records',
      exact: true,
    })
    const tile = page.getByTestId('hilos-table-tile-a')
    const text = tableTextToggle(page, 'a')
    const detail = shownByTestId(page, 'hilos-table-expand-a')
    await page.getByTestId('geometry-height-mode').selectOption('lines')
    await page.getByTestId('geometry-lines').selectOption('3')
    await page.getByTestId('geometry-height-content').selectOption('parts')
    await page.getByTestId('geometry-reading').click()
    await expectTableTextControl(expect, page, 'a', false)
    const sent = (await state.getAttribute('data-requests'))!
    const url = page.url()

    await test.step('Tab reaches Tiles and Enter chooses it on the same focused button', async () => {
      const group = page.getByTestId('hilos-table-view')
      await expect(group).toHaveRole('group')
      await expect(group).toHaveAccessibleName('Table view')
      await table.focus()
      await table.press('Tab')
      await expect(tiles).toBeFocused()
      await tiles.press('Enter')
      await expect(state).toHaveAttribute('data-view', 'tiles')
      await expect(tiles).toHaveAttribute('aria-pressed', 'true')
      await expect(table).toHaveAttribute('aria-pressed', 'false')
      await expect(tiles).toBeFocused()
      await settleTableLayout(page)
      await expect(state).toHaveAttribute('data-requests', sent)
      expect(page.url()).toBe(url)
    })

    await test.step('the tiles are one named list and the hidden Table offers no control', async () => {
      await expect(list).toHaveCount(1)
      await expect(list.getByRole('listitem')).toHaveCount(7)
      await expect(root.getByRole('table')).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-table-row-a')
          .getByTestId('hilos-table-text-toggle'),
      ).toBeHidden()
      await expectTableTextControl(expect, page, 'a', false)
    })

    await test.step("a tile's reading and detail controls keep their own states and targets", async () => {
      await text.focus()
      await text.press('Space')
      await expectTableTextControl(expect, page, 'a', true)
      await expect(text).toBeFocused()
      await expect(detail).toHaveAttribute('aria-expanded', 'false')
      await detail.focus()
      await detail.press('Enter')
      await expect(detail).toHaveAttribute('aria-expanded', 'true')
      await expect(detail).toBeFocused()
      await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
      await expectTableTextControl(expect, page, 'a', true)
      const reading = (await text.getAttribute('aria-controls'))!
      const panel = (await detail.getAttribute('aria-controls'))!
      expect(reading).not.toBe(panel)
      await expect(tile.locator(`[id="${reading}"]`)).toBeVisible()
      await expect(tile.locator(`[id="${panel}"]`)).toBeVisible()
      // The copies in the hidden Table and cards stay out of the tree.
      await expect(page.getByRole('button', { expanded: true })).toHaveCount(2)
      await expectUniqueIds(page)
      await text.press('Enter')
      await expectTableTextControl(expect, page, 'a', false)
      await expect(text).toBeFocused()
      await expect(detail).toHaveAttribute('aria-expanded', 'true')
    })

    await test.step('Shift+Tab and Space bring Table back on its focused button with the same states', async () => {
      await tiles.focus()
      await tiles.press('Shift+Tab')
      await expect(table).toBeFocused()
      await table.press('Space')
      await expect(state).toHaveAttribute('data-view', 'table')
      await expect(table).toHaveAttribute('aria-pressed', 'true')
      await expect(tiles).toHaveAttribute('aria-pressed', 'false')
      await expect(table).toBeFocused()
      await settleTableLayout(page)
      await expect(list).toHaveCount(0)
      await expect(tile.getByTestId('hilos-table-expand-a')).toBeHidden()
      await expectTableTextControl(expect, page, 'a', false)
      await expect(detail).toHaveAttribute('aria-expanded', 'true')
      await expect(page.getByRole('button', { expanded: true })).toHaveCount(1)
      await expectUniqueIds(page)
      await expect(state).toHaveAttribute('data-requests', sent)
      expect(page.url()).toBe(url)
    })
  })

  for (const outcome of ['replacement', 'view', 'removed', 'root'] as const) {
    test(`T06 marked modal focus: ${outcome}`, async ({ page }, info) => {
      const state = page.getByTestId('geometry-state')
      await clickSubmit(expect, page.getByTestId('hilos-table-view-tiles'))
      await expect(state).toHaveAttribute('data-view', 'tiles')
      if (outcome === 'root') {
        await clickSubmit(expect, page.getByTestId('geometry-no-search'))
        await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
        await expect(state).toHaveAttribute('data-view', 'tiles')
      }
      await settleTableLayout(page)
      const tileAction = page
        .getByTestId('hilos-table-tile-a')
        .getByTestId('geometry-action-a')
      const focus = await watchTableModalFocus(tileAction)
      try {
        await tileAction.focus()
        await tileAction.press('Enter')
        const modal = page.getByTestId('modal')
        await expect(modal).toBeFocused()
        await expect(modal).toHaveAccessibleName('Inspect geometry record')
        if (outcome === 'view') {
          // Table work behind the open dialog, as a view change from code.
          await page
            .getByTestId('hilos-table-view-table')
            .evaluate((button: HTMLButtonElement) => button.click())
          await expect(state).toHaveAttribute('data-view', 'table')
          await settleTableLayout(page)
          await expect(modal).toBeFocused()
        } else {
          const delivery = page.getByTestId(
            outcome === 'replacement'
              ? 'geometry-modal-snapshot'
              : 'geometry-modal-remove',
          )
          await delivery.focus()
          await delivery.press('Enter')
          if (outcome === 'replacement')
            await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
              'Fresh a',
            )
          else
            await expectTableTileDeparture(expect, page, 'a', 'Removed', [
              'geometry-name-a',
              'geometry-picture-a',
              'geometry-text-a',
              'geometry-badge-a',
              'geometry-action-a',
              'geometry-detail-a',
              'geometry-work-a',
            ])
          await settleTableLayout(page)
          await expect(delivery).toBeFocused()
        }
        expect((await focus.read()).inDialog).toBe(true)
        await page.getByTestId('geometry-modal-snapshot').focus()
        await page.getByTestId('geometry-modal-snapshot').press('Tab')
        await expect(page.getByTestId('modal-close')).toBeFocused()
        await page.getByTestId('modal-close').press('Shift+Tab')
        await expect(page.getByTestId('geometry-modal-snapshot')).toBeFocused()
        await modal.press('Escape')
        await expect(modal).toHaveCount(0)
        const target =
          outcome === 'replacement'
            ? tileAction
            : page.getByTestId(
                outcome === 'root'
                  ? 'hilos-viewport-table'
                  : 'hilos-table-search',
              )
        await expect(target).toBeVisible()
        await expect(target).toBeFocused()
        if (outcome === 'replacement') {
          await expect(state).toHaveAttribute('data-view', 'tiles')
          await expect(target).toHaveAttribute(
            'data-hilos-focus-return',
            'inspect-geometry',
          )
          expect((await focus.read()).openerConnected).toBe(false)
        }
        await info.attach(`tiles-modal-${outcome}`, {
          body: JSON.stringify(await focus.history(), null, 2),
          contentType: 'application/json',
        })
      } finally {
        await focus.dispose()
      }
    })
  }
})

/** The page has settled at its address under one heading, its tab alone current. */
async function expectStatusPage(
  page: Page,
  pageKey: string,
  path: string,
): Promise<void> {
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(path)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Status')
  const tabs = page.getByRole('navigation', { name: 'Status pages' })
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(tabs.getByTestId(`hilos-page-tab-${pageKey}`)).toHaveAttribute(
    'aria-current',
    'page',
  )
}

/** Follow a Status tab from the keyboard to the page it names. */
async function pressStatusTab(
  page: Page,
  pageKey: string,
  path: string,
): Promise<void> {
  const tab = page.getByTestId(`hilos-page-tab-${pageKey}`)
  await tab.focus()
  await expect(tab).toBeFocused()
  await tab.press('Enter')
  await expectStatusPage(page, pageKey, path)
}

test.describe('Status accessibility', () => {
  // The public day strip and the Subscribers table read moments in the
  // browser's zone: picked at random from the IANA list when these tests were
  // written, and stated here rather than left to the stand, which runs this
  // project on UTC. Contexts opened by a test inherit it.
  test.use({ timezoneId: 'America/Campo_Grande' })

  const statusTest = test.extend<{ cleanup: TestCleanup }>({
    cleanup: cleanupFixtures,
  })

  statusTest(
    'Status Subscribers and History name their tables, sort and filter from the keyboard, and the removal dialog returns focus',
    async ({ page, browser, cleanup }) => {
      const guestContext = await browser.newContext()
      cleanup.defer(() => guestContext.close())
      const guest = await guestContext.newPage()
      const admin = watchDeliveryFrames(page)
      const reader = watchDeliveryFrames(guest)
      const email = uniqueEmail()
      try {
        await grantAdminToSelf(expect, page)
        cleanup.defer(() => removeStatusSubscribersIfAny(expect, page, [email]))

        await statusTest.step(
          'a guest leaves an address awaiting confirmation',
          async () => {
            await gotoPublicStatus(expect, guest)
            await expect(guest.getByTestId('conn-state')).toHaveText(
              'connected',
            )
            await subscribeToStatus(expect, guest, reader, email)
          },
        )

        await statusTest.step(
          'the Overview titles the tab and opens Subscribers from the keyboard',
          async () => {
            await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
            await expectStatusPage(page, 'hilos_status', STATUS_PATH)
            await expect(page).toHaveTitle('Status · Hilos Flowers')
            await expect(page.getByTestId('page-title')).toHaveText(
              'Status · Hilos Flowers',
            )
            await pressStatusTab(
              page,
              'hilos_status_subscribers',
              STATUS_SUBSCRIBERS_PATH,
            )
          },
        )

        const id = await statusTest.step(
          'Subscribers names its table, sorts and filters from the keyboard, and finds the pending address',
          async () => {
            await expectStatusSubscribersAccessibility(expect, page, admin)
            const filter = page.getByTestId('hilos-table-filter-confirmed')
            const toggle = filter.getByTestId('hilos-dropdown-toggle')
            const pending = filter.getByTestId('hilos-dropdown-option-1')
            await toggle.focus()
            await toggle.press('ArrowDown')
            await expect(toggle).toHaveAttribute('aria-expanded', 'true')
            await expect(filter.getByRole('listbox')).toHaveAccessibleName(
              'Confirmed',
            )
            await expect(
              filter.getByTestId('hilos-dropdown-option--1'),
            ).toBeFocused()
            // Any comes first, then Yes and No.
            await page.keyboard.press('ArrowDown')
            await page.keyboard.press('ArrowDown')
            await expect(pending).toBeFocused()
            await pending.press('Enter')
            await expect(toggle).toBeFocused()
            await expect(toggle).toHaveAttribute('aria-expanded', 'false')
            await expect(toggle).toHaveAccessibleName('Confirmed: No')
            const searching = admin.sent.length
            await typeInto(page.getByTestId('hilos-table-search'), email)
            // Every keystroke asks for a window, and every window draws the
            // rows afresh: the dialog opens only over the answer to the whole
            // address, once nothing is pending.
            await expect
              .poll(() => searchedStatusSubscriberIds(admin, searching, email))
              .toHaveLength(1)
            await expect(page.getByTestId('hilos-table-loading')).toBeHidden()
            const [subscriberId] = searchedStatusSubscriberIds(
              admin,
              searching,
              email,
            )!
            expect(await statusSubscriberId(expect, page, email)).toBe(
              subscriberId,
            )
            await expect(
              shownByTestId(
                page,
                `hilos-status-subscriber-confirmed-${subscriberId}`,
              ),
            ).toHaveText('Awaiting confirmation')
            return subscriberId
          },
        )

        await statusTest.step(
          'the removal dialog is named, keeps focus inside and returns it on Escape and Cancel',
          () => expectStatusSubscriberRemoveDialog(expect, page, id),
        )

        await statusTest.step(
          'History names its table under the same heading',
          async () => {
            await pressStatusTab(
              page,
              'hilos_status_history',
              STATUS_HISTORY_PATH,
            )
            await expect(
              page.getByTestId('hilos-status-history-table').getByRole('table'),
            ).toHaveAccessibleName('Status')
          },
        )
      } finally {
        await test.info().attach('status-subscribers-accessibility-wire', {
          body: JSON.stringify({ admin, guest: reader }),
          contentType: 'application/json',
        })
        admin.stop()
        reader.stop()
      }
    },
  )

  test('public /status names its days and opens its subscription dialog from the keyboard', async ({
    page,
  }) => {
    const log = watchPublicStatus(page)
    try {
      await gotoPublicStatus(expect, page)
      await expectPublicStatusPair(expect, page, log, ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
      )
      await expect(page).toHaveTitle('Status · Hilos Flowers')
      await expect(page.getByTestId('page-title')).toHaveText(
        'Status · Hilos Flowers',
      )
      // The subscription control is offered over a live connection only.
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await expectPublicStatusAccessibility(expect, page, 'Service')
      await expectStatusSubscribeDialog(expect, page)
      await page.keyboard.press('Escape')
      await expect(page.getByTestId('modal')).toBeHidden()
      expectPublicStatusReadOnly(expect, log)
    } finally {
      await log.stop()
      await test.info().attach('public-status-accessibility-files', {
        body: JSON.stringify(log),
        contentType: 'application/json',
      })
    }
  })
})
