import { randomBytes } from 'node:crypto'

import type { Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import { drawCatalogPicture } from '../helpers/catalogPictures.js'

import {
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  expectTableTotal,
  goToLastPage,
  gotoPage,
  grantAdminToSelf,
  tableTotal,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves the shop's own category_picture_publish and category_create actions on its Categories page.

// Categories admin e2e: an admin adds a category from the table's main action
// with a picture published on upload, the row lands last in catalog order and
// reaches a second tab of the same admin over the live table, and a name
// already taken is refused inside the dialog, which keeps what was typed and
// the picture. The test-only command removes the reserved category and its
// picture whatever the outcome.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const CATEGORIES_PATH = '/hilos/app/categories'

const CATEGORIES_TABLE = 'admin-categories-table'

// EcommerceShopCommandConstants::CATEGORY_TEST_DELETE, request key `name`.
const CATEGORY_DELETE_COMMAND = 'test:ecommerce-shop:category-delete'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.categories-add: an admin adds a category with a picture, a second tab sees it arrive last, and a taken name is refused in the window', async ({
  page,
  cleanup,
}) => {
  const name = `e2e category ${randomBytes(6).toString('hex')}`
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name }))
  await grantAdminToSelf(expect, page)
  const second = await page.context().newPage()
  const rowsOf = (tab: Page) =>
    tab.getByTestId(CATEGORIES_TABLE).locator(ROWS).filter({ hasText: name })
  // The table's window is as tall as the screen allows, so "last" is read on
  // the last page of the catalog order rather than in the first window.
  const expectLastRow = async (tab: Page) => {
    await goToLastPage(expect, tab, CATEGORIES_TABLE)
    await expect(
      tab.getByTestId(CATEGORIES_TABLE).locator(ROWS).last(),
    ).toContainText(name)
  }
  const dialog = page.getByTestId('admin-categories-add')
  const preview = dialog.getByTestId('admin-categories-add-preview')
  const choosePicture = async (mimeType: 'image/jpeg' | 'image/png') => {
    await dialog
      .getByTestId('admin-categories-add-picture-input')
      .setInputFiles({
        name: mimeType === 'image/png' ? 'picture.png' : 'picture.jpg',
        mimeType,
        buffer: await drawCatalogPicture(page, mimeType),
      })
  }
  let total = 0

  await test.step('both tabs open the categories page', async () => {
    await gotoPage(expect, page, CATEGORIES_PATH)
    await gotoPage(expect, second, CATEGORIES_PATH)
    await expect(second.getByTestId(CATEGORIES_TABLE)).toBeVisible()
    total = await tableTotal(expect, page)
  })

  await test.step('the picture is published on upload and its copy shows', async () => {
    await page.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    await typeInto(dialog.getByTestId('admin-categories-add-name'), name)
    await typeInto(
      dialog.getByTestId('admin-categories-add-description'),
      'Added by the e2e scenario',
    )
    await choosePicture('image/png')
    await expect(preview).toBeVisible()
    await expect
      .poll(() => preview.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0)
  })

  await test.step('Add closes the dialog and the row lands last', async () => {
    await clickSubmit(expect, page.getByTestId('admin-categories-add-submit'))
    await expect(dialog).toBeHidden()
    await expectTableTotal(expect, page, total + 1)
    await expectLastRow(page)
  })

  await test.step('the second tab sees the row arrive without a reload', async () => {
    await expectTableTotal(expect, second, total + 1)
    await expectLastRow(second)
  })

  await test.step('the same name again is refused inside the dialog', async () => {
    await dismissToasts(page)
    await page.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    const nameField = dialog.getByTestId('admin-categories-add-name')
    await typeInto(nameField, name)
    await typeInto(
      dialog.getByTestId('admin-categories-add-description'),
      'Added twice by the e2e scenario',
    )
    await choosePicture('image/jpeg')
    await expect(preview).toBeVisible()
    await clickSubmit(expect, page.getByTestId('admin-categories-add-submit'))
    await expect(
      dialog.getByTestId('admin-categories-add-error'),
    ).toContainText('A category with this name already exists')
    await expect(dialog).toBeVisible()
    await expect(nameField).toHaveValue(name)
    await expect(preview).toBeVisible()
  })

  await test.step('Cancel asks to discard the typing and closes the dialog', async () => {
    await page.getByTestId('admin-categories-add-cancel').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(dialog).toBeHidden()
    await expect(rowsOf(page)).toHaveCount(1)
  })
})
