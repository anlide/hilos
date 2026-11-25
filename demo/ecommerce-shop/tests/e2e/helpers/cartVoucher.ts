import type { Expect, Page } from '@playwright/test'

import {
  clickSubmit,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

/**
 * Type a code into the already open cart voucher form and submit it.
 *
 * The caller opens the form and waits for it to close or for the refusal.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose open voucher form receives the code.
 * @param code The code to type and submit.
 */
export async function submitCartVoucher(
  expect: Expect,
  page: Page,
  code: string,
): Promise<void> {
  const form = page.getByTestId('cart-voucher-apply')
  await typeInto(form.getByTestId('cart-voucher-apply-code'), code)
  await clickSubmit(expect, page.getByTestId('cart-voucher-apply-submit'))
}
