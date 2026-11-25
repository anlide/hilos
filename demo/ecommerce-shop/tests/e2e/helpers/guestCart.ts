import type { Expect, Page } from '@playwright/test'

import {
  sessionToken,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// EcommerceShopCommandConstants::GUEST_CART_TEST_SET_QUANTITY.
export const GUEST_CART_SET_QUANTITY_COMMAND =
  'test:ecommerce-shop:guest-cart-set-quantity'

const COMMAND_TIMEOUT_MS = 5_000

const command = createStandCommandChannel({ timeoutMs: COMMAND_TIMEOUT_MS })

/**
 * Set a guest cart item's quantity through the synchronous stand test command.
 *
 * @param token The guest session token that owns the cart.
 * @param productId The catalog product ID to set or clear.
 * @param quantity The target quantity, or 0 to remove the line.
 */
export async function setGuestCartQuantity(
  token: string,
  productId: number,
  quantity: number,
): Promise<Record<string, unknown>> {
  return command(GUEST_CART_SET_QUANTITY_COMMAND, {
    sessionToken: token,
    productId,
    quantity,
  })
}

/**
 * Bind a guest cart item to its context's session token, register teardown
 * removal (`quantity: 0`), and return a synchronous quantity setter.
 *
 * @param cleanup The test-scoped cleanup registrar.
 * @param page The guest page whose session token owns the cart item.
 * @param productId The catalog product ID to update and clear on teardown.
 */
export async function bindGuestCartItem(
  cleanup: TestCleanup,
  page: Page,
  productId: number,
): Promise<(quantity: number) => Promise<void>> {
  const token = await sessionToken(page)
  const setQuantity = async (quantity: number): Promise<void> => {
    await setGuestCartQuantity(token, productId, quantity)
  }
  cleanup.defer(() => setQuantity(0))
  return setQuantity
}

/**
 * Register guest-cart item removal that only runs while the page still holds
 * the original guest session token (before sign-in transfers and rotates it).
 *
 * @param cleanup The test-scoped cleanup registrar.
 * @param page The page that starts as a guest and may later sign in.
 * @param productIds The catalog product IDs added under the guest token.
 */
export async function deferGuestCartProductsCleanup(
  cleanup: TestCleanup,
  page: Page,
  productIds: readonly number[],
): Promise<string> {
  const guestToken = await sessionToken(page)
  // The transfer owns these bindings after sign-in rotates the guest token.
  for (const productId of productIds) {
    cleanup.defer(async () => {
      if ((await sessionToken(page)) !== guestToken) return
      await setGuestCartQuantity(guestToken, productId, 0)
    })
  }
  return guestToken
}

/**
 * Arm guest-cart removal before a tracked browser Add click and return a
 * wrapper that marks the click as attempted before invoking it.
 *
 * If the scenario fails before reaching the returned click wrapper, teardown
 * returns immediately without polling for the Add outcome. Once the click is
 * attempted, teardown waits for the action reply (success or refusal) or the
 * caller's visible cart state before resetting the product quantity to zero,
 * and still runs the fallback removal if the Add outcome remained unconfirmed.
 *
 * @param expect The calling spec's Playwright expect.
 * @param cleanup The test-scoped cleanup registrar.
 * @param page The guest page whose session token owns the cart item.
 * @param productId The catalog product ID to clear on teardown.
 * @param addFinished Predicate resolving to true once the Add reply or visible cart state arrives.
 */
export async function deferGuestCartAddCleanup(
  expect: Expect,
  cleanup: TestCleanup,
  page: Page,
  productId: number,
  addFinished: () => boolean | Promise<boolean>,
): Promise<(click: () => Promise<void>) => Promise<void>> {
  const token = await sessionToken(page)
  let clickAttempted = false

  cleanup.defer(async () => {
    if (!clickAttempted) return

    // The browser can send the add before the observer receives framesent.
    let unconfirmed: Error | undefined
    try {
      await expect
        .poll(addFinished, {
          message: 'Guest cart cleanup waits for the add reply or cart line',
        })
        .toBe(true)
    } catch (error) {
      unconfirmed = new Error(
        'Guest cart cleanup could not confirm that the add finished',
        { cause: error },
      )
    }

    // An error can arrive after the cart write commits, so remove after either reply.
    try {
      await setGuestCartQuantity(token, productId, 0)
    } catch (error) {
      if (unconfirmed === undefined) throw error
      throw new AggregateError(
        [unconfirmed, error],
        'Guest cart add was unconfirmed and the fallback removal failed',
        { cause: error },
      )
    }
    if (unconfirmed !== undefined) throw unconfirmed
  })

  return async (click: () => Promise<void>): Promise<void> => {
    clickAttempted = true
    await click()
  }
}
