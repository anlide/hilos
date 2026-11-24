import { randomBytes } from 'node:crypto'

import type { Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import { drawCatalogPicture } from '../helpers/catalogPictures.js'
import { PAGE_CATEGORY } from '../../../frontend/src/pages/keys.js'
import {
  CATEGORY_PRODUCTS_TABLE,
  FILTER_CATEGORY,
} from '../../../frontend/src/views/Category/categoryProducts.js'

import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
} from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  ROWS,
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  expectDocumentUnchanged,
  expectPageReady,
  expectTableTotal,
  goToLastPage,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  shownByTestId,
  tableTotal,
  typeInto,
  type TestCleanup,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves the shop's own category_picture_publish and category_create actions on its Categories page.

// Categories admin e2e: an admin adds a category from the table's main action
// with a picture published on upload, the row lands last in catalog order and
// reaches a second tab of the same admin over the live table, and a name
// already taken is refused inside the dialog, which keeps what was typed and
// the picture. Renaming the category moves both an open storefront page and a
// fresh visit to the former link. The open window keeps its Kind filter.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const CATEGORIES_PATH = '/hilos/categories'

const CATEGORIES_TABLE = 'admin-categories-table'

// EcommerceShopCommandConstants::CATEGORY_TEST_DELETE, request key `name`.
const CATEGORY_DELETE_COMMAND = 'test:ecommerce-shop:category-delete'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.categories-add: an admin adds a category with a picture, a second tab sees it arrive last, and a taken name is refused in the window', async ({
  browser,
  page,
  cleanup,
}) => {
  const suffix = randomBytes(6).toString('hex')
  const name = `e2e category ${suffix}`
  const renamedName = `${name} renamed`
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name }))
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name: renamedName }))
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

  const guestContext = await browser.newContext()
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()
  const delivery = watchDeliveryFrames(guest)
  cleanup.defer(() => delivery.stop())
  const heading = guest.getByRole('heading', { level: 1 })
  const products = guest.getByTestId('shop-category-products')
  const kind = products.getByTestId('hilos-table-filter-kind')
  const kindToggle = kind.getByTestId('hilos-dropdown-toggle')
  const noMatches = shownByTestId(products, 'hilos-table-no-matches')
  const categorySlug = `e2e-category-${suffix}`
  const renamedSlug = `${categorySlug}-renamed`
  const categoryPath = `/category/${categorySlug}`
  const renamedPath = `/category/${renamedSlug}`

  const readFirstCategoryResponse = async (slug: string) => {
    const firstResponse = () => {
      const subscribe = delivery.sent.find(({ wire }) => {
        const frame = deliveryObject(wire)
        return (
          frame.type === SIGNAL_TYPE_PAGE_SUBSCRIBE &&
          frame.page === PAGE_CATEGORY &&
          deliveryObject(frame.params).slug === slug
        )
      })
      if (!subscribe) return

      return delivery.packets
        .filter(
          ({ socket, at }) => socket === subscribe.socket && at >= subscribe.at,
        )
        .flatMap(({ frames }) => frames)
        .find(({ wire }) => {
          const frame = deliveryObject(wire)
          return (
            frame.type === SIGNAL_TYPE_PAGE_RESPONSE &&
            deliveryObject(frame.data).page === PAGE_CATEGORY
          )
        })
    }
    await expect
      .poll(firstResponse, {
        message: `First category page response for ${slug}`,
      })
      .toBeDefined()
    const response = firstResponse()!
    expect(response.failure).toBeUndefined()
    const payload = deliveryObject(
      deliveryObject(deliveryObject(response.wire).data).payload,
    )
    const category = deliveryObject(deliveryObject(payload.data).category)
    expect(category.slug).toBe(slug)
    return {
      categoryId: category.id,
      window: deliveryObject(
        deliveryObject(payload.windows)[CATEGORY_PRODUCTS_TABLE],
      ),
    }
  }

  const categoryId =
    await test.step('a guest follows the category link from the storefront', async () => {
      await gotoPage(expect, guest, '/')
      await guest
        .getByTestId('shop-home-tile')
        .filter({ hasText: name })
        .click()
      await expect(guest).toHaveURL(categoryPath)
      await expectPageReady(expect, guest)
      await expect(heading).toHaveText(name)
      await markDocument(guest)

      const { categoryId, window } =
        await readFirstCategoryResponse(categorySlug)
      expect(Number.isInteger(categoryId)).toBe(true)
      expect(categoryId).toBeGreaterThan(0)
      expect(window.filter).toEqual({ [FILTER_CATEGORY]: categoryId })
      expect(window.rows).toEqual([])
      return categoryId
    })

  await test.step('the guest filters the category by Kind', async () => {
    await expect(shownByTestId(products, 'hilos-table-empty')).toBeVisible()
    await expect(kindToggle).toBeEnabled()
    await expect(kindToggle).toHaveText('Kind: Any')
    await kindToggle.click()
    await kind.getByRole('option', { name: /^Unique piece\b/ }).click()
    await expect(kindToggle).toHaveText('Kind: Unique piece')
    await expect(noMatches).toBeVisible()
  })

  await test.step('renaming the category moves the open guest page without a reload', async () => {
    await dismissToasts(page)
    await rowsOf(page).getByTestId('admin-categories-edit-open').click()
    const edit = page.getByTestId('admin-categories-edit')
    await typeInto(edit.getByTestId('admin-categories-edit-name'), renamedName)
    await clickSubmit(expect, page.getByTestId('admin-categories-edit-submit'))
    await expect(edit).toBeHidden()

    await expect(guest).toHaveURL(renamedPath)
    await expectPageReady(expect, guest)
    await expect(heading).toHaveText(renamedName)
    await expectDocumentUnchanged(expect, guest)

    const moved = await readFirstCategoryResponse(renamedSlug)
    expect(moved.categoryId).toBe(categoryId)
    expect(moved.window.filter).toEqual({
      [FILTER_CATEGORY]: categoryId,
      kind: 'unique',
    })
    expect(moved.window.rows).toEqual([])

    await expect(kindToggle).toHaveText('Kind: Unique piece')
    await expect(noMatches).toBeVisible()
    await expect(products.locator(ROWS)).toHaveCount(0)
    await expect(products.getByRole('listitem')).toHaveCount(0)
  })

  await test.step('opening the former category link in a new tab reaches its current address', async () => {
    const fresh = await guestContext.newPage()
    await gotoPage(expect, fresh, categoryPath, PAGE_READY)
    await expect(fresh).toHaveURL(renamedPath)
    await expect(fresh.getByRole('heading', { level: 1 })).toHaveText(
      renamedName,
    )
  })

  await test.step('one Back returns from the moved category to the storefront', async () => {
    await guest.goBack()
    await expect(guest).toHaveURL('/')
    await expectPageReady(expect, guest)
    await expectDocumentUnchanged(expect, guest)
  })
})
