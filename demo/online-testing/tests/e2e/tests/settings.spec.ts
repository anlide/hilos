import { test as base, expect } from '../test.js'

import {
  borrowSetting,
  cleanupFixtures,
  type TestCleanup,
  clearCustomSetting,
  dismissToasts,
  expectDataFreeDeparture,
  expectPageReady,
  expectSettingsPageHead,
  fitsWithoutOverflow,
  gotoPage,
  grantAdminToSelf,
  openSettingEdit,
  setCustomSetting,
  settleTableLayout,
  shownByTestId,
  type TableGeometryWatch,
  typeInto,
  watchHeight,
  watchTableGeometry,
  watchTableSearch,
  watchTopWithin,
  clickSubmit,
} from '../../../../../framework/frontend/e2e/index.js'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Hilos settings admin e2e for the online-testing demo: activating the framework settings
// feature configure-only (a catalog + a thin page + a project BrowserContext)
// makes /hilos/settings render the framework settings table over the live socket.
// The table is a declared one, so it stands in the document twice — rows for a wide
// screen, cards for a narrow one.
// Catalog placeholder rows show without any DB override, search filters the client
// viewport, and a custom value is set on a catalog key from its own row
// (add-by-key) then reset — both round-trip through the backend and re-render with
// no document reload. The settings table is cataloged, so there is no free "add a
// setting" control (data-model.md, "Cataloged tables"); the only mutations live on
// the row. Each scenario captures its borrowed keys before changing them and
// restores their exact raw state through the settings owner.

test('Settings filters its catalog, dismisses its save toast and preserves scalar drafts', async ({
  page,
  cleanup,
}) => {
  await borrowSetting(cleanup, 'example_boolean')
  await borrowSetting(cleanup, 'example_integer')
  await borrowSetting(cleanup, 'example_string')
  await grantAdminToSelf(expect, page)

  await test.step('lists settings in the framework table and filters from the search box', async () => {
    await gotoPage(expect, page, '/hilos/settings')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expectSettingsPageHead(expect, page)

    // Direct entry and F5 keep the same head without a single Overview tab.
    await page.reload()
    await expectPageReady(expect, page)
    await expectSettingsPageHead(expect, page)

    // A cataloged table has no free "add a setting" entry point.
    await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

    const search = page.getByTestId('hilos-table-search')

    // Catalog placeholder rows are present without any DB override. Isolate the
    // family first: adding another catalog key may move it off the first page.
    await typeInto(search, 'example_')
    await expect(
      page.getByTestId('hilos-table-row-example_string'),
    ).toBeVisible()
    await expect(
      page.getByTestId('hilos-table-row-example_boolean'),
    ).toBeVisible()

    // A query no key matches empties the viewport and the table says so, naming the
    // query and offering the reset that brings the rows back; a key query narrows it;
    // clearing restores every row.
    await typeInto(search, 'zzz-no-such-setting-zzz')
    await expect(
      page.getByTestId('hilos-table-row-example_string'),
    ).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    // The words stand in both branches of the table, so they are read off the copy
    // on screen; that they are gone is asserted of both.
    const noMatches = page.getByTestId('hilos-table-no-matches')
    await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
    await expect(
      shownByTestId(page, 'hilos-table-no-matches-terms'),
    ).toContainText('“zzz-no-such-setting-zzz”')
    await shownByTestId(page, 'hilos-table-no-matches-reset').click()
    await expect(
      page.locator('[data-id^="hilos-table-row-"]').first(),
    ).toBeVisible()
    await expect(noMatches).toHaveCount(0)
    await expect(search).toHaveValue('')
    await typeInto(search, 'example_boolean')
    await expect(
      page.getByTestId('hilos-table-row-example_boolean'),
    ).toBeVisible()
    await expect(
      page.getByTestId('hilos-table-row-example_string'),
    ).toHaveCount(0)
    await search.fill('')
    await expect(
      page.locator('[data-id^="hilos-table-row-"]').first(),
    ).toBeVisible()
  })

  await test.step('saving a setting raises a toast the close button dismisses', async () => {
    await typeInto(page.getByTestId('hilos-table-search'), 'example_boolean')
    await expect(
      page.getByTestId('hilos-table-row-example_boolean'),
    ).toBeVisible()

    await clearCustomSetting(page, 'example_boolean')
    await dismissToasts(page)
    const save = page.getByTestId('hilos-settings-edit-save')
    await setCustomSetting(page, 'example_boolean', true)

    // The dialog closes on the action's own `::success` reply, so its going is the
    // action settling — asserted before the toast, which the same reply raises.
    await expect(save).toHaveCount(0)
    const toast = page.getByTestId('hilos-toast-success')
    await expect(toast).toBeVisible()
    await expect(page.getByTestId('hilos-toasts')).toBeVisible()

    // The life bar is drawn only for a measured card that is not an error, so its
    // presence is the one automatic sign that the Angular card was redrawn and
    // that the measurement and the countdown behind it are alive — this SDK has
    // no component test of its own (ng test is blocked upstream, HIL-491).
    await expect(page.getByTestId('hilos-toast-life')).toBeVisible()

    // Dismissed by hand, well inside the stack's own expiry — the close button is
    // what is under test, so the toast has to still be there to close.
    await page.getByTestId('hilos-toast-close').click()
    await expect(toast).toHaveCount(0)

    // Reset the key back to its catalog default.
    await clearCustomSetting(page, 'example_boolean')
    await expect(save).toHaveCount(0)
  })

  await test.step('sets a custom value on a catalog key from its row and resets it, live', async () => {
    let fullLoads = 0
    const countLoad = (): void => {
      fullLoads += 1
    }
    page.on('load', countLoad)
    cleanup.defer(() => page.off('load', countLoad))

    const loadsAfterColdLoad = fullLoads

    // Narrow the table to the row first: the first page holds ten rows, and every
    // catalog key that sorts before this one pushes it further down.
    await typeInto(page.getByTestId('hilos-table-search'), 'example_integer')
    await clearCustomSetting(page, 'example_integer')
    await dismissToasts(page)
    const integerRow = page.getByTestId('hilos-table-row-example_integer')
    await expect(integerRow).toContainText('default')

    // The catalog type stays in the header, and custom-off keeps a disabled,
    // dimmed field mounted before there is a custom value.
    await openSettingEdit(page, 'example_integer')
    await expect(page.getByTestId('hilos-settings-edit-type')).toHaveText(
      'integer',
    )
    const custom = page.getByTestId('hilos-settings-edit-custom')
    const value = page.getByTestId('hilos-settings-edit-value')
    await expect(custom).not.toBeChecked()
    await expect(value).toBeVisible()
    await expect(value).toHaveAttribute('disabled', '')
    await expect(value.locator('..')).toHaveClass(/\bopacity-50\b/)
    const input = await value.elementHandle()
    cleanup.defer(() => input?.dispose())

    // Off/on preserves both the input node and the exact unsaved draft.
    await custom.check()
    await expect(value).toBeEnabled()
    await typeInto(value, '42')
    await custom.uncheck()
    await expect(value).toHaveAttribute('disabled', '')
    await expect(value.locator('..')).toHaveClass(/\bopacity-50\b/)
    await expect(value).toHaveValue('42')
    expect(
      await value.evaluate((node, original) => node === original, input),
    ).toBe(true)
    await custom.check()
    await expect(value).toBeEnabled()
    await expect(value.locator('..')).not.toHaveClass(/\bopacity-50\b/)
    await expect(value).toHaveValue('42')
    expect(
      await value.evaluate((node, original) => node === original, input),
    ).toBe(true)
    const save = page.getByTestId('hilos-settings-edit-save')
    await clickSubmit(expect, save)
    await expect(save).toHaveCount(0)

    // The custom value returns over the live table and the edit dialog closes.
    await expect(integerRow).toContainText('42')
    await expect(integerRow).toContainText('custom')
    await expect(value).toHaveCount(0)

    // Saving with custom off resets to the default rather than writing the draft.
    await dismissToasts(page)
    await openSettingEdit(page, 'example_integer')
    await typeInto(value, '43')
    await custom.uncheck()
    await expect(value).toHaveAttribute('disabled', '')
    await expect(value).toHaveValue('43')
    await clickSubmit(expect, save)
    await expect(save).toHaveCount(0)
    await expect(integerRow).toContainText('default')
    await expect(integerRow).not.toContainText('custom')
    await expect(integerRow).not.toContainText('43')

    await expect(value).toHaveCount(0)

    // And re-opening the dialog arms the switch from the value, not from the row:
    // off, with the disabled value field still visible.
    await openSettingEdit(page, 'example_integer')
    await expect(
      page.getByTestId('hilos-settings-edit-custom'),
    ).not.toBeChecked()
    await expect(value).toBeVisible()
    await expect(value).toHaveAttribute('disabled', '')
    await page.getByTestId('modal-close').click()

    await expect(value).toHaveCount(0)

    // A multi-sentence custom string wraps across the table row without being
    // clipped to a single line, and leaves no toggle control behind.
    await typeInto(page.getByTestId('hilos-table-search'), 'example_string')
    await clearCustomSetting(page, 'example_string')
    await dismissToasts(page)
    const stringRow = page.getByTestId('hilos-table-row-example_string')
    await expect(stringRow).toBeVisible()
    const longValue =
      'First sentence of a long custom value that will wrap across multiple lines. ' +
      'Second sentence ensures enough length to overflow a single table row cell. ' +
      'Third sentence completes the multi-sentence payload.'
    await setCustomSetting(page, 'example_string', longValue)
    const stringValueText = stringRow
      .getByTestId('setting-value')
      .locator('.hilos-table-text')
    await expect(stringValueText).toHaveText(longValue)
    await fitsWithoutOverflow(stringValueText)
    await expect(stringRow.getByTestId('hilos-table-text-toggle')).toHaveCount(
      0,
    )
    await clearCustomSetting(page, 'example_string')
    await expect(stringRow).toContainText('default')
    await expect(stringRow).not.toContainText(longValue)

    // All of it happened over the live socket — no document reload.
    expect(fullLoads).toBe(loadsAfterColdLoad)
  })
})

// Independent true orphans are seeded offline by test:db-prepare after reset,
// before the daemon starts. Each destructive scenario owns its own key.
for (const origin of ['own', 'foreign'] as const) {
  test(`${origin} deletion retains the last settings record until a new search snapshot`, async ({
    page,
  }) => {
    const key = `e2e_geometry_${origin}`
    const sourceWindows = await watchTableSearch(page, {
      page: 'hilos_settings',
      tableKey: 'settings',
    })
    const tabB =
      origin === 'foreign' ? await page.context().newPage() : undefined
    const target = tabB ?? page
    const windows = tabB
      ? await watchTableSearch(tabB, {
          page: 'hilos_settings',
          tableKey: 'settings',
        })
      : sourceWindows
    let geometry: TableGeometryWatch | undefined
    try {
      await grantAdminToSelf(expect, page)
      for (const view of tabB
        ? [
            { page, windows: sourceWindows },
            { page: tabB, windows },
          ]
        : [{ page, windows }]) {
        await gotoPage(expect, view.page, '/hilos/settings')
        await expect(
          view.page.getByTestId('hilos-viewport-table'),
        ).toBeVisible()
        const mark = await view.windows.mark()
        await typeInto(view.page.getByTestId('hilos-table-search'), key)
        await view.windows.renderedSettled(expect, mark, { search: key }, [key])
        await expect(view.page.getByTestId('hilos-table-count')).toHaveText(
          '1 – 1 of 1',
        )
        await expect(
          view.page.getByTestId(`hilos-table-row-${key}`),
        ).toContainText('seed-value')
      }
      await settleTableLayout(target)
      const table = target.getByTestId('hilos-viewport-table')
      const height = await watchHeight(table)
      const footer = await watchTopWithin(
        target.getByTestId('hilos-table-count'),
        table,
      )
      geometry = await watchTableGeometry(expect, target, {
        bodyId: 'hilos-viewport-table',
        projection: 'wide',
        rowKeys: [key],
        lastKey: key,
      })

      await clickSubmit(
        expect,
        shownByTestId(page, `hilos-settings-delete-${key}`),
      )
      await expect(page.getByTestId('modal')).toHaveAccessibleName(
        `Delete · ${key}`,
      )
      const confirm = page.getByTestId('hilos-settings-delete-confirm')
      await clickSubmit(expect, confirm)
      await expect(confirm).toHaveCount(0)
      await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)

      if (origin === 'foreign') {
        // Count zero is independent of the pending membership change. Both
        // mounted projections still carry the old value until this tab applies.
        await expect(
          target.getByTestId(`hilos-table-pending-remove-${key}`),
        ).toHaveCount(2)
        await expect(target.getByTestId('hilos-table-pending')).toHaveText('1')
        await expect(target.getByTestId('hilos-table-count')).toHaveText(
          '0 – 0 of 0',
        )
        await expect(
          target.getByTestId(`hilos-table-row-${key}`),
        ).toContainText('seed-value')
        await expect(target.getByTestId('hilos-table-placeholder')).toHaveCount(
          0,
        )
        await geometry.check()
        await height.unchanged()
        await footer.unchanged()
        await clickSubmit(expect, target.getByTestId('hilos-table-apply'))
      }
      await expectDataFreeDeparture(expect, target, key, 'Removed', [
        `hilos-settings-edit-${key}`,
        `hilos-settings-reset-${key}`,
        `hilos-settings-delete-${key}`,
      ])
      await expect(
        target.locator('tbody > tr[data-id^="hilos-table-row-"]'),
      ).toHaveAttribute('data-id', `hilos-table-row-${key}`)
      await expect(
        target.locator('[data-id^="hilos-table-card-"]'),
      ).toHaveAttribute('data-id', `hilos-table-card-${key}`)
      for (const prefix of ['hilos-table-row-', 'hilos-table-card-']) {
        await expect(target.getByTestId(`${prefix}${key}`)).toHaveText(
          'Removed',
        )
        await expect(target.getByTestId(`${prefix}${key}`)).not.toContainText(
          'seed-value',
        )
      }
      await expect(target.getByTestId('hilos-table-placeholder')).toHaveCount(2)
      await expect(target.getByTestId('hilos-table-count')).toHaveText(
        '0 – 0 of 0',
      )
      await expect(target.getByTestId('hilos-table-apply')).toHaveCount(0)
      await expect(target.getByTestId('hilos-table-pending-row')).toHaveCount(0)
      await expect(target.getByTestId('hilos-table-no-matches')).toHaveCount(0)
      await expect(target.getByTestId('hilos-table-empty')).toHaveCount(0)
      await geometry.check()
      await height.unchanged()
      await footer.unchanged()
      await geometry.stop()

      const emptyMark = await windows.mark()
      const search = `${key}_missing`
      await typeInto(target.getByTestId('hilos-table-search'), search)
      await windows.renderedSettled(expect, emptyMark, { search }, [])
      await expect(
        shownByTestId(target, 'hilos-table-no-matches'),
      ).toBeVisible()
      await expect(
        shownByTestId(target, 'hilos-table-no-matches-terms'),
      ).toContainText(`“${search}”`)
      await expect(target.getByTestId('hilos-table-placeholder')).toHaveCount(0)
      await expect(target.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
      await expect(target.getByTestId(`hilos-table-card-${key}`)).toHaveCount(0)
      await expect(target.getByTestId('hilos-table-count')).toHaveCount(0)
    } finally {
      if (geometry) {
        await geometry.stop()
        await test.info().attach(`${origin}-departure-geometry`, {
          body: await geometry.evidence(),
          contentType: 'application/json',
        })
      }
      await test.info().attach(`${origin}-source-search`, {
        body: JSON.stringify(
          await sourceWindows
            .read()
            .catch((error: unknown) => ({ unavailable: String(error) })),
          null,
          2,
        ),
        contentType: 'application/json',
      })
      if (tabB) {
        await test.info().attach(`${origin}-observer-search`, {
          body: JSON.stringify(
            await windows
              .read()
              .catch((error: unknown) => ({ unavailable: String(error) })),
            null,
            2,
          ),
          contentType: 'application/json',
        })
        await tabB.close()
      }
    }
  })
}
