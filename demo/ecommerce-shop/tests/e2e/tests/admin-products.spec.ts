import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import {
  SIGNAL_TYPE_ACTION_ERROR,
  SIGNAL_TYPE_ACTION_SUCCESS,
} from '../../../../../framework/frontend/core/src/protocol/constants.js'
import { UPLOAD_ACTION_INIT } from '../../../../../framework/frontend/core/src/uploads/uploadProtocol.js'
import {
  ROWS,
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  drawPhotoFixture,
  dropFiles,
  expectDocumentUnchanged,
  gotoPage,
  grantAdminToSelf,
  holdSocketAction,
  markDocument,
  nextClientPaint,
  showAnnouncedRows,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { isApplicationSocket } from '../../../../../framework/frontend/e2e/socket.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves creation and live delivery on the shop's Products page.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const PRODUCTS_PATH = '/hilos/app/products'
const PRODUCTS_TABLE = 'admin-products-table'

// EcommerceShopCommandConstants::PRODUCT_TEST_DELETE, request key `name`.
const PRODUCT_DELETE_COMMAND = 'test:ecommerce-shop:product-delete'
const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.products-add: both product kinds can be added with pictures and a second tab can show the new row', async ({
  page,
  cleanup,
}) => {
  const uniqueName = `e2e product ${randomBytes(6).toString('hex')}`
  const stockName = `e2e product ${randomBytes(6).toString('hex')}`
  cleanup.defer(() => command(PRODUCT_DELETE_COMMAND, { name: uniqueName }))
  cleanup.defer(() => command(PRODUCT_DELETE_COMMAND, { name: stockName }))

  const gate = await holdSocketAction(page)
  await grantAdminToSelf(expect, page)
  const second = await page.context().newPage()
  const products = page.getByTestId(PRODUCTS_TABLE)
  const secondProducts = second.getByTestId(PRODUCTS_TABLE)
  const dialog = page.getByRole('dialog', { name: 'Add product' })
  const nameField = dialog.getByRole('textbox', { name: 'Name' })
  const category = dialog.getByRole('combobox', { name: 'Category' })
  const uniqueKind = dialog.getByRole('radio', { name: 'Unique piece' })
  const stockKind = dialog.getByRole('radio', { name: 'Stock item' })
  const price = dialog.getByRole('textbox', { name: moneyFieldLabel('Price') })
  const description = dialog.getByRole('textbox', { name: 'Description' })
  const upload = dialog.getByTestId('admin-products-add-picture')
  const preview = dialog.getByTestId('admin-products-add-preview')
  const add = dialog.getByTestId('admin-products-add-submit')
  const uniqueCategory = 'Bouquets'
  const stockCategory = 'Pots & planters'
  const uniqueRow = products.locator(ROWS).filter({ hasText: uniqueName })
  const stockRow = products.locator(ROWS).filter({ hasText: stockName })
  const secondRow = secondProducts.locator(ROWS).filter({ hasText: uniqueName })

  const openAdd = async () => {
    await products.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    await expect(upload).toHaveAccessibleName('Upload')
    await expect(category).toHaveValue('')
    await expect(uniqueKind).toBeChecked()
  }

  const publishPicture = async (extension: 'png' | 'jpeg') => {
    const mimeType = `image/${extension}` as const
    const [chooser] = await Promise.all([
      page.waitForEvent('filechooser'),
      upload.click(),
    ])
    await chooser.setFiles({
      name: `product.${extension}`,
      mimeType,
      buffer: await drawPhotoFixture(page, {
        width: 320,
        height: 240,
        mimeType,
        rectangles: [
          { color: '#bc3024', x: 0, y: 0, width: 160, height: 240 },
          { color: '#246ac4', x: 160, y: 0, width: 160, height: 240 },
        ],
      }),
    })
    await expect(preview).toBeVisible()
    await expect
      .poll(() => preview.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0)
  }

  await test.step('both tabs subscribe to the first page of recently changed products', async () => {
    await gotoPage(expect, page, PRODUCTS_PATH)
    await gotoPage(expect, second, PRODUCTS_PATH)
    for (const table of [products, secondProducts]) {
      await expect(table.getByTestId('hilos-table-count')).toHaveText(
        /^1 – \d+ of \d+$/,
      )
      await expect(
        table.getByRole('columnheader', { name: 'Changed' }),
      ).toHaveAttribute('aria-sort', 'descending')
    }
    await expect(
      secondProducts.getByTestId('hilos-table-announce'),
    ).toBeHidden()
    await markDocument(second)
  })

  await test.step('the server refuses an invalid price and keeps the unique product draft', async () => {
    const invalidPrice = 'two thousand'
    const draftDescription = 'A bouquet made once for this scenario.'
    await openAdd()
    await typeInto(nameField, uniqueName)
    await category.selectOption({ label: uniqueCategory })
    await typeInto(price, invalidPrice)
    await typeInto(description, draftDescription)
    await publishPicture('png')
    const pictureUrl = await preview.evaluate(
      (img: HTMLImageElement) => img.src,
    )

    gate.hold('product_create')
    await clickSubmit(expect, add)
    await expect.poll(() => gate.captured.length).toBe(1)
    await expect(upload).toBeDisabled()
    const drop = dialog.getByTestId('admin-products-add-drop')
    const input = dialog.getByTestId('admin-products-add-picture-input')
    const uploadFiles = dialog.getByTestId('admin-products-add-upload')
    const replacement = {
      name: 'replacement.png',
      mimeType: 'image/png',
      buffer: Buffer.from('replacement picture'),
    }
    try {
      // A chooser that reports back after submit still reaches the field.
      await input.setInputFiles(replacement)
      await expect(uploadFiles).toHaveCount(0)
      await dropFiles(drop, [replacement])
      await expect(uploadFiles).toHaveCount(0)
      await drop.evaluate((target) => {
        const clipboardData = new DataTransfer()
        clipboardData.items.add(new File(['replacement'], 'pasted.png'))
        target.dispatchEvent(
          new ClipboardEvent('paste', { bubbles: true, clipboardData }),
        )
      })
      await expect(uploadFiles).toHaveCount(0)
      await expect(preview).toHaveJSProperty('src', pictureUrl)
    } finally {
      gate.release()
    }
    await expect(
      dialog
        .getByTestId('admin-products-add-error')
        .getByText('Write the amount in hryvnias, like 410 or 2 100.50', {
          exact: true,
        }),
    ).toBeVisible()
    await expect(dialog).toBeVisible()
    await expect(upload).toBeEnabled()
    await expect(nameField).toHaveValue(uniqueName)
    await expect(category.locator('option:checked')).toHaveText(uniqueCategory)
    await expect(uniqueKind).toBeChecked()
    await expect(price).toHaveValue(invalidPrice)
    await expect(description).toHaveValue(draftDescription)
    await expect(preview).toBeVisible()
    await expect(preview).toHaveJSProperty('src', pictureUrl)
  })

  await test.step('correcting the price creates a unique piece without a warehouse', async () => {
    await typeInto(price, '2 100.50')
    await clickSubmit(expect, add)
    await expect(dialog).toBeHidden()
    await expect(
      uniqueRow.getByRole('cell', { name: uniqueCategory, exact: true }),
    ).toBeVisible()
    await expect(
      uniqueRow.getByRole('cell', { name: 'Unique piece', exact: true }),
    ).toBeVisible()
    await expect(
      uniqueRow.getByRole('cell', { name: '₴2,100.50', exact: true }),
    ).toBeVisible()
    await expect(
      uniqueRow.getByRole('cell', {
        name: 'Not in a warehouse yet',
        exact: true,
      }),
    ).toBeVisible()
  })

  await test.step('Show brings the announced product into the already open second tab', async () => {
    await expect(
      secondProducts.getByTestId('hilos-table-announce'),
    ).toContainText('1 new row')
    await expect(secondRow).toHaveCount(0)
    await showAnnouncedRows(expect, second)
    await expect(
      secondRow.getByRole('cell', { name: uniqueName, exact: true }),
    ).toBeVisible()
    await expectDocumentUnchanged(expect, second)
  })

  await test.step('a stock item starts with zero units and is out of stock', async () => {
    await openAdd()
    await typeInto(nameField, stockName)
    await category.selectOption({ label: stockCategory })
    await uniqueKind.press('ArrowRight')
    await expect(stockKind).toBeChecked()
    await typeInto(price, '410')
    await typeInto(description, 'A ceramic planter carried in stock.')
    await publishPicture('jpeg')

    await clickSubmit(expect, add)
    await expect(dialog).toBeHidden()
    await expect(
      stockRow.getByRole('cell', { name: stockCategory, exact: true }),
    ).toBeVisible()
    await expect(
      stockRow.getByRole('cell', { name: 'Stock item', exact: true }),
    ).toBeVisible()
    await expect(
      stockRow.getByRole('cell', { name: '₴410', exact: true }),
    ).toBeVisible()
    await expect(
      stockRow.getByRole('cell', { name: '0', exact: true }),
    ).toBeVisible()
    await expect(
      stockRow.getByRole('cell', { name: 'Out of stock', exact: true }),
    ).toBeVisible()
  })
})

test('shop.products-add-picture: publication replies belong to the selected picture and dialog', async ({
  page,
}) => {
  type PictureOutcome = { fileId: number; picture: string } | { reason: string }
  const publications: Array<(outcome: PictureOutcome) => void> = []
  const refusedFile = 'unsupported.txt'
  let releaseFile: (() => void) | undefined
  await page.routeWebSocket(isApplicationSocket, (browser) => {
    const daemon = browser.connectToServer()
    browser.onMessage((message) => {
      const frame = deliveryObject(
        typeof message === 'string' && message.startsWith('{')
          ? JSON.parse(message)
          : null,
      )
      const { action, requestId } = frame
      if (
        action === 'product_picture_publish' &&
        typeof requestId === 'string'
      ) {
        // Give each publication a controlled reply. The file upload itself
        // still passes through the daemon and can be refused there.
        publications.push((outcome) => {
          const failed = 'reason' in outcome
          browser.send(
            JSON.stringify({
              requestId,
              type: failed
                ? SIGNAL_TYPE_ACTION_ERROR
                : SIGNAL_TYPE_ACTION_SUCCESS,
              outcome: failed ? 'fail' : 'success',
              data: failed
                ? { action, ...outcome }
                : { action, reply: outcome },
            }),
          )
        })
        return
      }
      if (
        action === UPLOAD_ACTION_INIT &&
        deliveryObject(frame.data).filename === refusedFile
      ) {
        releaseFile = () => daemon.send(message)
        return
      }
      daemon.send(message)
    })
  })

  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, PRODUCTS_PATH)
  const open = page
    .getByTestId(PRODUCTS_TABLE)
    .getByTestId('hilos-table-main-action')
  const dialog = page.getByRole('dialog', { name: 'Add product' })
  const upload = dialog.getByTestId('admin-products-add-picture')
  const input = dialog.getByTestId('admin-products-add-picture-input')
  const preview = dialog.getByTestId('admin-products-add-preview')
  const error = dialog.getByTestId('admin-products-add-error')
  const add = dialog.getByTestId('admin-products-add-submit')
  const reason = 'The picture could not be published'
  const image = {
    mimeType: 'image/png' as const,
    buffer: await drawPhotoFixture(page, {
      width: 320,
      height: 240,
      mimeType: 'image/png',
      rectangles: [{ color: '#246ac4', x: 0, y: 0, width: 320, height: 240 }],
    }),
  }

  await open.click()
  await typeInto(
    dialog.getByRole('textbox', { name: 'Name' }),
    'Picture reply draft',
  )
  await dialog
    .getByRole('combobox', { name: 'Category' })
    .selectOption({ label: 'Bouquets' })
  await typeInto(
    dialog.getByRole('textbox', { name: moneyFieldLabel('Price') }),
    '410',
  )
  await typeInto(
    dialog.getByRole('textbox', { name: 'Description' }),
    'A draft with a replacement picture.',
  )

  await test.step('a late success leaves Add waiting for the replacement', async () => {
    await input.setInputFiles({ ...image, name: 'old.png' })
    await expect.poll(() => publications.length).toBe(1)
    await expect(upload).toBeEnabled()
    await input.setInputFiles({ ...image, name: 'new.png' })
    await expect(dialog.getByText('new.png', { exact: true })).toBeVisible()
    publications[0]!({ fileId: 1, picture: '/old.png' })
    await expect.poll(() => publications.length).toBe(2)
    await expect(preview).toHaveCount(0)
    await expect(add).toBeDisabled()
    publications[1]!({ fileId: 2, picture: '/new.png' })
    await expect(preview).toHaveAttribute('src', '/new.png')
    await expect(add).toBeEnabled()
  })

  await test.step('the current publication refusal is shown', async () => {
    await input.setInputFiles({ ...image, name: 'current.png' })
    await expect.poll(() => publications.length).toBe(3)
    publications[2]!({ reason })
    await expect(error.getByText(reason, { exact: true })).toBeVisible()
    await expect(preview).toHaveCount(0)
    await expect(add).toBeDisabled()
  })

  await test.step('a late refusal stays hidden during the replacement upload and after its file refusal', async () => {
    await input.setInputFiles({ ...image, name: 'superseded.png' })
    await expect.poll(() => publications.length).toBe(4)
    await input.setInputFiles({
      name: refusedFile,
      mimeType: 'text/plain',
      buffer: Buffer.from('not an image'),
    })
    await expect.poll(() => releaseFile !== undefined).toBe(true)
    await expect(dialog.getByText(refusedFile, { exact: true })).toBeVisible()
    publications[3]!({ reason })
    await nextClientPaint(page)
    await expect(error.getByText(reason, { exact: true })).toBeHidden()
    releaseFile!()
    await expect(
      dialog
        .getByTestId('admin-products-add-upload-error')
        .getByText('This file type is not allowed', { exact: true }),
    ).toBeVisible()
    await expect(error.getByText(reason, { exact: true })).toBeHidden()
    await expect(preview).toHaveCount(0)
    await expect(add).toBeDisabled()
    expect(publications).toHaveLength(4)
  })

  await test.step('the next opening starts empty before the dismissed dialog receives its reply', async () => {
    await input.setInputFiles({ ...image, name: 'dismissed.png' })
    await expect.poll(() => publications.length).toBe(5)
    await dialog.getByTestId('admin-products-add-cancel').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(dialog).toBeHidden()
    await open.click()
    publications[4]!({ fileId: 3, picture: '/dismissed.png' })
    await nextClientPaint(page)
    await expect(preview).toHaveCount(0)
    await expect(error.getByText(reason, { exact: true })).toBeHidden()
    await expect(add).toBeDisabled()
  })
})
