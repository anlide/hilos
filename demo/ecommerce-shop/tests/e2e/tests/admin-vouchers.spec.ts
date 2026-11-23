import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'

import {
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  expectDocumentUnchanged,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves creation and refusals on the shop's Vouchers page.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const VOUCHERS_PATH = '/hilos/app/vouchers'
const VOUCHER_GET_COMMAND = 'test:ecommerce-shop:voucher-fixture-get'
const VOUCHER_DELETE_COMMAND = 'test:ecommerce-shop:voucher-fixture-delete'
const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.vouchers-add: an admin corrects a refused discount, adds a voucher live and gets a refusal for a duplicate code', async ({
  page,
  cleanup,
}) => {
  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const enteredCode = code.toLowerCase()
  expect(await command(VOUCHER_GET_COMMAND, { code })).toEqual({
    voucher: null,
  })
  cleanup.defer(() => command(VOUCHER_DELETE_COMMAND, { code }))

  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, VOUCHERS_PATH)
  await markDocument(page)

  const open = page.getByTestId('hilos-table-main-action')
  const dialog = page.getByTestId('admin-vouchers-add')
  const codeField = dialog.getByTestId('admin-vouchers-add-code')
  const discount = dialog.getByTestId('admin-vouchers-add-discount-percent')
  const active = dialog.getByTestId('admin-vouchers-add-active')
  const error = dialog.getByTestId('admin-vouchers-add-error')
  const submit = page.getByTestId('admin-vouchers-add-submit')
  const rows = page
    .getByTestId('admin-vouchers-table')
    .locator(ROWS)
    .filter({ hasText: code })

  await test.step('the server refuses 101 percent and the dialog keeps the draft', async () => {
    await open.click()
    await expect(active).toBeChecked()
    await typeInto(codeField, enteredCode)
    await typeInto(discount, '101')
    await active.uncheck()
    await clickSubmit(expect, submit)

    await expect(error).toContainText(
      'Discount must be a whole percent from 1 to 100',
    )
    await expect(dialog).toBeVisible()
    await expect(codeField).toHaveValue(enteredCode)
    await expect(discount).toHaveValue('101')
    await expect(active).not.toBeChecked()
  })

  await test.step('correcting the discount adds one normalized row without reloading', async () => {
    await typeInto(discount, '20')
    await clickSubmit(expect, submit)

    await expect(dialog).toBeHidden()
    await expect(rows).toHaveCount(1)
    await expect(rows.getByText(code, { exact: true })).toBeVisible()
    await expect(rows.getByText('20%', { exact: true })).toBeVisible()
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('the server refuses the same code in another case and keeps the draft', async () => {
    await dismissToasts(page)
    await open.click()
    await typeInto(codeField, code)
    await typeInto(discount, '25')
    await active.uncheck()
    await clickSubmit(expect, submit)

    await expect(error).toContainText('A voucher with this code already exists')
    await expect(dialog).toBeVisible()
    await expect(codeField).toHaveValue(code)
    await expect(discount).toHaveValue('25')
    await expect(active).not.toBeChecked()
    await expect(rows).toHaveCount(1)
    await expect(rows.getByText('20%', { exact: true })).toBeVisible()
  })
})
