import type { Expect, Page } from '@playwright/test'

import {
  sessionToken,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET.
const VOUCHER_FIXTURE_GET_COMMAND = 'test:ecommerce-shop:voucher-fixture-get'

// EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE.
const VOUCHER_FIXTURE_CREATE_COMMAND =
  'test:ecommerce-shop:voucher-fixture-create'

// EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE.
const VOUCHER_FIXTURE_DELETE_COMMAND =
  'test:ecommerce-shop:voucher-fixture-delete'

// EcommerceShopCommandConstants::GUEST_CART_SET_VOUCHER.
const GUEST_CART_SET_VOUCHER_COMMAND =
  'test:ecommerce-shop:guest-cart-set-voucher'

const COMMAND_TIMEOUT_MS = 5_000

/**
 * The command channel reports the server refusal without the session token.
 */
const DIFFERENT_VOUCHER_FAILURE =
  GUEST_CART_SET_VOUCHER_COMMAND +
  ' failed: This guest cart holds a different voucher'

const command = createStandCommandChannel({ timeoutMs: COMMAND_TIMEOUT_MS })

/** A voucher this test creates through the fixture command. */
export interface VoucherFixture {
  code: string
  discountPercent: number
  active: boolean
}

/**
 * Confirm a code is free before the test writes a voucher under it.
 *
 * @param expect The calling spec's Playwright expect.
 * @param code The voucher code that must not exist yet.
 */
async function expectVoucherAbsent(
  expect: Expect,
  code: string,
): Promise<void> {
  expect(await command(VOUCHER_FIXTURE_GET_COMMAND, { code })).toEqual({
    voucher: null,
  })
}

/**
 * Clear one test-owned voucher from a guest cart.
 *
 * @param token The guest session token that owns the cart. Not logged.
 * @param code The voucher code this test created.
 */
function clearGuestVoucher(
  token: string,
  code: string,
): Promise<Record<string, unknown>> {
  return command(GUEST_CART_SET_VOUCHER_COMMAND, {
    sessionToken: token,
    code,
    applied: false,
  })
}

/**
 * True when a clear failed because the cart holds a different voucher.
 *
 * @param error The rejection from the clear command.
 */
function holdsDifferentVoucher(error: unknown): boolean {
  return error instanceof Error && error.message === DIFFERENT_VOUCHER_FAILURE
}

/**
 * Register removal of test-owned guest vouchers. The session token is not
 * written into logs, error text, or traces.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param token The guest session token that owns the cart. Not logged.
 * @param code The own code cleared first.
 * @param otherCode Another own code, cleared only after the known mismatch.
 */
function registerGuestVoucherClear(
  expect: Expect,
  cleanup: TestCleanup,
  token: string,
  code: string,
  otherCode?: string,
): void {
  cleanup.defer(async () => {
    const reply = await clearGuestVoucher(token, code).catch(
      (error: unknown) => {
        if (otherCode === undefined || !holdsDifferentVoucher(error)) {
          throw error
        }
        return clearGuestVoucher(token, otherCode)
      },
    )
    expect(reply).toEqual({ applied: false })
  })
}

/**
 * Confirm both codes are free, then register LIFO teardown for an admin
 * create-and-rename. Teardown deletes the original code first, then the name
 * a queued Save may have written, then confirms both codes are empty.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param code The code the admin dialog will create.
 * @param renamedCode The code a later edit may save over that row.
 */
export async function reserveVoucherCodes(
  expect: Expect,
  cleanup: TestCleanup,
  code: string,
  renamedCode: string,
): Promise<void> {
  for (const ownedCode of [code, renamedCode]) {
    await expectVoucherAbsent(expect, ownedCode)
    cleanup.defer(() => expectVoucherAbsent(expect, ownedCode))
  }
  // LIFO deletes the original first, so a queued Save cannot leave a renamed row.
  for (const ownedCode of [renamedCode, code]) {
    cleanup.defer(() =>
      command(VOUCHER_FIXTURE_DELETE_COMMAND, { code: ownedCode }),
    )
  }
}

/**
 * Create one test-owned voucher after confirming its code is free, and
 * register deletion of that code.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param voucher The code, percent, and active flag to create.
 */
export async function createVoucherFixture(
  expect: Expect,
  cleanup: TestCleanup,
  voucher: VoucherFixture,
): Promise<void> {
  await expectVoucherAbsent(expect, voucher.code)
  cleanup.defer(() =>
    command(VOUCHER_FIXTURE_DELETE_COMMAND, { code: voucher.code }),
  )
  expect(
    await command(VOUCHER_FIXTURE_CREATE_COMMAND, {
      code: voucher.code,
      discountPercent: voucher.discountPercent,
      active: voucher.active,
    }),
  ).toMatchObject({ voucher })
}

/**
 * Register removal of guest-cart vouchers this test created, before the cart
 * is mutated. Teardown clears `code`. When that clear fails because the cart
 * holds another voucher this test created, and `otherCode` was given, teardown
 * clears `otherCode` instead. Any other error is rethrown.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param page The guest page whose session token owns the cart.
 * @param code The own code cleared first.
 * @param otherCode Another own code, cleared only after the known mismatch.
 */
export async function deferGuestCartVoucherClear(
  expect: Expect,
  cleanup: TestCleanup,
  page: Page,
  code: string,
  otherCode?: string,
): Promise<void> {
  const token = await sessionToken(page)
  registerGuestVoucherClear(expect, cleanup, token, code, otherCode)
}

/**
 * Apply one test-owned voucher to a guest cart. Removal of that code is
 * registered before the apply. The session token is not written into logs,
 * error text, or traces.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param page The guest page whose session token owns the cart.
 * @param code The voucher code this test created.
 */
export async function applyGuestCartVoucher(
  expect: Expect,
  cleanup: TestCleanup,
  page: Page,
  code: string,
): Promise<void> {
  const token = await sessionToken(page)
  registerGuestVoucherClear(expect, cleanup, token, code)
  expect(
    await command(GUEST_CART_SET_VOUCHER_COMMAND, {
      sessionToken: token,
      code,
      applied: true,
    }),
  ).toEqual({ applied: true })
}
