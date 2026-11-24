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

// spec-owner: demo — proves creation, editing and refusals on the shop's Vouchers page.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const VOUCHERS_PATH = '/hilos/vouchers'
const VOUCHER_GET_COMMAND = 'test:ecommerce-shop:voucher-fixture-get'
const VOUCHER_DELETE_COMMAND = 'test:ecommerce-shop:voucher-fixture-delete'
const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.vouchers-add: an admin corrects a refused discount, adds and edits a voucher live and gets a duplicate-code refusal', async ({
  page,
  cleanup,
}) => {
  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const renamedCode = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const enteredCode = code.toLowerCase()
  for (const ownedCode of [code, renamedCode]) {
    expect(await command(VOUCHER_GET_COMMAND, { code: ownedCode })).toEqual({
      voucher: null,
    })
    cleanup.defer(async () => {
      expect(await command(VOUCHER_GET_COMMAND, { code: ownedCode })).toEqual({
        voucher: null,
      })
    })
  }
  // LIFO deletes the original first, so a queued Save cannot leave a renamed row.
  for (const ownedCode of [renamedCode, code]) {
    cleanup.defer(() => command(VOUCHER_DELETE_COMMAND, { code: ownedCode }))
  }

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
  const rows = page.getByTestId('admin-vouchers-table').locator(ROWS)
  const addedRow = rows.filter({ hasText: code })
  const editedRow = rows.filter({ hasText: renamedCode })

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
    await expect(addedRow).toHaveCount(1)
    await expect(addedRow.getByText(code, { exact: true })).toBeVisible()
    await expect(addedRow.getByText('20%', { exact: true })).toBeVisible()
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('editing the voucher replaces its row without reloading', async () => {
    await dismissToasts(page)
    await addedRow.getByTestId('admin-vouchers-edit-open').click()
    const edit = page.getByTestId('admin-vouchers-edit')
    const editCode = edit.getByTestId('admin-vouchers-edit-code')
    const editDiscount = edit.getByTestId(
      'admin-vouchers-edit-discount-percent',
    )
    const editActive = edit.getByTestId('admin-vouchers-edit-active')

    await expect(editCode).toHaveValue(code)
    await expect(editDiscount).toHaveValue('20')
    await expect(editActive).not.toBeChecked()
    await typeInto(editCode, renamedCode)
    await typeInto(editDiscount, '35')
    await editActive.check()
    await clickSubmit(expect, page.getByTestId('admin-vouchers-edit-submit'))

    await expect(edit).toBeHidden()
    await expect(editedRow).toHaveCount(1)
    await expect(
      editedRow.getByText(renamedCode, { exact: true }),
    ).toBeVisible()
    await expect(editedRow.getByText('35%', { exact: true })).toBeVisible()
    await expect(editedRow.getByText('Active', { exact: true })).toBeVisible()
    await expect(addedRow).toHaveCount(0)
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('the server refuses the same code in another case and keeps the draft', async () => {
    await dismissToasts(page)
    await open.click()
    const duplicateCode = renamedCode.toLowerCase()
    await typeInto(codeField, duplicateCode)
    await typeInto(discount, '25')
    await active.uncheck()
    await clickSubmit(expect, submit)

    await expect(error).toContainText('A voucher with this code already exists')
    await expect(dialog).toBeVisible()
    await expect(codeField).toHaveValue(duplicateCode)
    await expect(discount).toHaveValue('25')
    await expect(active).not.toBeChecked()
    await expect(editedRow).toHaveCount(1)
    await expect(editedRow.getByText('35%', { exact: true })).toBeVisible()
  })
})
