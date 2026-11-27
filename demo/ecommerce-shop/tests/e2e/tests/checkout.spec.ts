import assert from 'node:assert/strict'
import { inspect } from 'node:util'
import type { Locator, Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import {
  GUEST_CART_SET_QUANTITY_COMMAND,
  setGuestCartQuantity,
} from '../helpers/guestCart.js'
import { createVoucherFixture } from '../helpers/voucherFixtures.js'
import { PAGE_CART, PAGE_PRODUCT } from '../../../frontend/src/pages/keys.js'
import { router } from '../../../frontend/src/pages/routes.js'
import { SIGNAL_TYPE_ACTION_ERROR } from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  PAGE_REFUSED,
  bindTestUser,
  cleanupFixtures,
  createTestUser,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  login,
  logout,
  markDocument,
  sessionToken,
  timeZoneContextOptions,
  trackedActionReplies,
  watchDeliveryFrames,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — the shop's checkout page over the cart.

const TIME_ZONE = 'Pacific/Tahiti'
const GUEST_CART_SET_VOUCHER_COMMAND =
  'test:ecommerce-shop:guest-cart-set-voucher'
const GUEST_SESSION_UNAVAILABLE = 'This guest session is no longer available'
const VOUCHER_IN_CART =
  'test:ecommerce-shop:voucher-fixture-delete failed: This voucher is in a cart and cannot be deleted'
const ADD_TO_CART = 'product_add_to_cart'

interface CartSnapshot {
  readonly empty: boolean
  readonly lines: readonly string[]
  readonly voucher: 'own' | 'empty' | 'other' | 'hidden'
}

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Browser traces can include the guest's session cookie.
test.use({ colorScheme: 'light', trace: 'off', timezoneId: TIME_ZONE })

function safeDiagnostic(
  error: unknown,
  token: string | undefined,
  ancestors = new Set<Error>(),
): Error {
  const redact = (text: string) =>
    token ? text.replaceAll(token, '[redacted]') : text
  if (!(error instanceof Error)) {
    const detail =
      typeof error === 'string'
        ? error
        : inspect(error, { customInspect: false })
    return new Error(redact(detail))
  }
  if (ancestors.has(error)) return new Error('Circular error reference')

  ancestors.add(error)
  const cause =
    error.cause === undefined
      ? undefined
      : safeDiagnostic(error.cause, token, ancestors)
  const message = redact(error.message)
  const copy =
    error instanceof AggregateError
      ? new AggregateError(
          error.errors.map((member: unknown) =>
            safeDiagnostic(member, token, ancestors),
          ),
          message,
          { cause },
        )
      : new Error(message, { cause })
  ancestors.delete(error)

  copy.name = redact(error.name)
  const stack = [redact(error.stack ?? `${error.name}: ${error.message}`)]
  if (cause) stack.push(`Caused by: ${cause.stack ?? cause.message}`)
  if (copy instanceof AggregateError) {
    for (const member of copy.errors as Error[]) {
      stack.push(`Aggregate member: ${member.stack ?? member.message}`)
    }
  }
  copy.stack = stack.join('\n')
  return copy
}

test('checkout resumes after sign-in and follows the cart in another tab', async ({
  browser,
  page,
  cleanup,
}) => {
  const chamomile = {
    id: 4,
    slug: 'small-bunch-of-chamomile',
    name: 'Small bunch of chamomile',
  }
  const meadow = { id: 1, name: 'Morning meadow' }
  const products = [chamomile, meadow]
  const voucherCode = 'ZZE2EVC4EC0A710000000000000001'
  const command = createStandCommandChannel({ timeoutMs: 5_000 })
  const auth = page.getByTestId('auth-surface')
  const checkoutTotal = page.getByTestId('shop-checkout-total')
  const checkoutEmpty = page.getByTestId('shop-checkout-empty')
  const cartPath = router.path(PAGE_CART)
  const recoveryProductPath = router.path(PAGE_PRODUCT, {
    slug: chamomile.slug,
  })
  assert(cartPath && recoveryProductPath, 'Cart cleanup requires both routes')
  const contextOptions = timeZoneContextOptions(
    test.info().project.use,
    TIME_ZONE,
  )
  const remainingProducts = new Set(products.map(({ name }) => name))
  let signInStarted = false
  let guestToken: string | undefined
  let voucherCleared = true
  let voucherSeenOnAccount = false
  let accountVoucherCleared = false

  const confirmAccountVoucherCleared = () => {
    voucherCleared = true
    accountVoucherCleared = true
  }
  const setGuestVoucher = (token: string, applied: boolean) =>
    command(GUEST_CART_SET_VOUCHER_COMMAND, {
      sessionToken: token,
      code: voucherCode,
      applied,
    })
  const cartBadge = (tab: Page) =>
    tab.getByTestId('nav-cart').getByTestId('cart-badge')
  const cartSlot = (tab: Page) => tab.getByTestId('hilos-page-slot')
  const cartLine = (tab: Page, name: string) =>
    cartSlot(tab)
      .getByTestId('shop-cart-line')
      .filter({
        has: tab.getByRole('link', { name, exact: true }),
      })
  const removeCartLine = async (tab: Page, name: string) => {
    const line = cartLine(tab, name)
    await line.getByTestId('shop-cart-remove-line').click()
    await expect(line).toHaveCount(0)
    remainingProducts.delete(name)
  }
  const cartVoucherRow = (tab: Page) =>
    cartSlot(tab).getByTestId('shop-cart-voucher')
  const ownCartVoucher = (tab: Page) =>
    cartVoucherRow(tab).filter({
      has: tab.getByText(voucherCode, { exact: true }),
    })
  const removeOwnVoucher = async (tab: Page) => {
    await expect(ownCartVoucher(tab)).toBeVisible()
    await ownCartVoucher(tab).getByTestId('cart-voucher-remove').click()
    await expect(cartVoucherRow(tab)).toHaveCount(0)
    await expect(tab.getByTestId('cart-voucher-apply-open')).toHaveText(
      'Apply voucher',
    )
    confirmAccountVoucherCleared()
  }
  const openCart = async (tab: Page) => {
    await gotoPage(expect, tab, cartPath, PAGE_READY)
    await expect(tab).toHaveURL(cartPath)
    await expect(
      tab.getByTestId('shop-cart-total').or(tab.getByTestId('shop-cart-empty')),
    ).toBeVisible()
  }
  const clickObservedControl = async (
    control: Locator,
    onClickStart?: () => void,
  ) => {
    // A detached control must not make cleanup wait for a replacement row.
    const [button, another] = await control.elementHandles()
    if (
      !button ||
      another ||
      !(await button.isVisible()) ||
      !(await button.isEnabled())
    ) {
      throw new Error('The observed control is no longer available')
    }
    onClickStart?.()
    await button.click()
  }

  // Read the route, readiness, and visible contents from the same render.
  const readAccountCart = (tab: Page, accountName: string) =>
    tab.evaluate<
      CartSnapshot | null,
      {
        path: string
        accountName: string
        ready: string
        code: string
      }
    >(
      ({ path, accountName, ready, code }) => {
        const find = (root: ParentNode, id: string) =>
          root.querySelector<HTMLElement>(`[data-id="${id}"]`)
        const visible = (element: HTMLElement | null) =>
          element !== null &&
          element.getClientRects().length > 0 &&
          getComputedStyle(element).visibility === 'visible'
        const slot = find(document, 'hilos-page-slot')
        if (
          location.pathname !== path ||
          find(document, 'hilos-page-state')?.dataset.state !== ready ||
          find(document, 'conn-state')?.textContent?.trim() !== 'connected' ||
          find(document, 'nav-profile-name')?.title !== accountName ||
          !slot
        )
          return null

        const empty = visible(find(slot, 'shop-cart-empty'))
        if (empty === visible(find(slot, 'shop-cart-total'))) return null

        const lines = Array.from(
          slot.querySelectorAll<HTMLElement>('[data-id="shop-cart-line"]'),
        )
          .filter(visible)
          .map(
            (line) =>
              find(line, 'shop-cart-product-link')?.textContent?.trim() ?? '',
          )
        const row = find(slot, 'shop-cart-voucher')
        const apply = find(slot, 'cart-voucher-apply-open')
        let voucher: CartSnapshot['voucher'] = 'hidden'
        if (visible(row)) {
          voucher =
            row?.querySelector('dt > span')?.textContent === code
              ? 'own'
              : 'other'
        } else if (visible(apply) && apply?.textContent === 'Apply voucher') {
          voucher = 'empty'
        }

        return { empty, lines, voucher }
      },
      { path: cartPath, accountName, ready: PAGE_READY, code: voucherCode },
    )

  const hasBindings = () => !voucherCleared || remainingProducts.size > 0
  const cleanupErrors: Error[] = []
  const recordError = (operation: string, error: unknown) => {
    cleanupErrors.push(
      safeDiagnostic(new Error(operation, { cause: error }), guestToken),
    )
  }
  const attempt = async (operation: string, run: () => Promise<unknown>) => {
    try {
      await run()
      return true
    } catch (error) {
      recordError(operation, error)
      return false
    }
  }
  const cleanAccountCart = async (
    user: Awaited<ReturnType<typeof createTestUser>>,
  ) => {
    const context = await browser.newContext(contextOptions)
    let recoveryAdd: { readRefusal: () => void; stop: () => void } | undefined
    try {
      const tab = await context.newPage()
      await bindTestUser(expect, tab, user)
      const readCart = async () => {
        try {
          return await readAccountCart(tab, user.name)
        } catch (error) {
          if (tab.isClosed()) throw error
          recordError('Read the bound account Cart', error)
          return null
        }
      }
      const waitForCart = async (
        observation: string,
        matches: (snapshot: CartSnapshot) => boolean,
      ): Promise<CartSnapshot> => {
        let snapshot: CartSnapshot | null = null
        try {
          await expect
            .poll(async () => {
              snapshot = await readCart()
              return snapshot !== null && matches(snapshot)
            })
            .toBe(true)
        } catch (error) {
          throw new Error(
            snapshot ? observation : 'The bound account Cart is unavailable',
            { cause: error },
          )
        }
        assert(snapshot)
        return snapshot
      }

      let canReopen = true
      const currentCart = async () => {
        const ready = await readCart()
        if (ready) return ready
        const needsNavigation = () =>
          tab
            .evaluate(
              ({ path, refused }) => {
                const state = document.querySelector(
                  '[data-id="hilos-page-state"]',
                )
                return (
                  location.pathname !== path ||
                  !state ||
                  state.getAttribute('data-state') === refused
                )
              },
              { path: cartPath, refused: PAGE_REFUSED },
            )
            .catch((error: unknown) => {
              recordError('Read the account Cart navigation state', error)
              return false
            })
        if (canReopen && (await needsNavigation())) {
          canReopen = false
          await attempt('Reopen the account Cart', () => openCart(tab))
        }
        return waitForCart('Wait for the bound account Cart', () => true)
      }
      await attempt('Open the account Cart', () => openCart(tab))
      let snapshot = await currentCart()
      const showsTransfer = (cart: CartSnapshot) =>
        cart.voucher === 'own' ||
        cart.lines.some((name) => remainingProducts.has(name))
      if (!voucherSeenOnAccount) {
        for (
          let observation = 0;
          observation < 2 && !showsTransfer(snapshot);
          observation++
        ) {
          if (
            await attempt(
              'Observe the guest transfer in the account Cart',
              async () => {
                snapshot = await waitForCart(
                  'The guest transfer was not observed',
                  showsTransfer,
                )
              },
            )
          )
            break
          snapshot = await currentCart()
        }
        if (!showsTransfer(snapshot)) return
      }

      let recoveryLinePending = false
      const attemptedLines = new Set<string>()
      const addRecoveryLine = async () => {
        const productTab = await context.newPage()
        const delivery = watchDeliveryFrames(productTab)
        try {
          await gotoPage(expect, productTab, recoveryProductPath, PAGE_READY)
          const add = productTab.getByTestId('shop-product-add-to-cart')
          await expect(add).toBeEnabled()
          await attempt('Click Add in the product tab', () =>
            clickObservedControl(add, () => {
              const since = delivery.sent.length
              let refusalRecorded = false
              recoveryAdd = {
                stop: delivery.stop,
                readRefusal: () => {
                  const [reply] = trackedActionReplies(
                    delivery,
                    ADD_TO_CART,
                    since,
                  )
                  if (
                    refusalRecorded ||
                    reply?.frame.type !== SIGNAL_TYPE_ACTION_ERROR
                  )
                    return
                  refusalRecorded = true
                  recordError(
                    'Add a line to expose the voucher slot',
                    reply.data.reason ?? 'Add was refused',
                  )
                },
              }
              attemptedLines.delete(chamomile.name)
              remainingProducts.add(chamomile.name)
              recoveryLinePending = true
            }),
          )
        } finally {
          if (!recoveryAdd) {
            delivery.stop()
            await attempt('Close the product cleanup tab', () =>
              productTab.close(),
            )
          }
        }

        if (!recoveryAdd) return
        await attempt(
          'Observe the temporary line on the open Cart',
          async () => {
            await waitForCart('The temporary line was not observed', (cart) => {
              recoveryAdd?.readRefusal()
              return cart.lines.includes(chamomile.name)
            })
            recoveryLinePending = false
          },
        )
      }

      const settleVoucher = async (cart: CartSnapshot) => {
        if (voucherCleared) return cart
        if (cart.empty && !recoveryAdd) {
          await attempt(
            'Expose the voucher slot using a second tab',
            addRecoveryLine,
          )
          cart = await currentCart()
        }
        if (cart.empty) return cart
        await attempt('Clear the account voucher', async () => {
          if (cart.voucher === 'own') {
            await clickObservedControl(
              ownCartVoucher(tab).getByTestId('cart-voucher-remove'),
            )
            await waitForCart(
              'The account voucher slot did not become empty',
              (current) => current.voucher === 'empty',
            )
          } else if (cart.voucher !== 'empty') {
            throw new Error(
              cart.voucher === 'other'
                ? 'The account Cart holds another voucher'
                : 'The account voucher slot could not be confirmed',
            )
          }
          confirmAccountVoucherCleared()
        })
        return currentCart()
      }

      const removeAvailableLines = async () => {
        for (const name of remainingProducts) {
          let cart = await currentCart()
          if (
            cart.lines.includes(name) &&
            cart.lines.length === 1 &&
            !voucherCleared
          ) {
            cart = await settleVoucher(cart)
          }
          if (!cart.lines.includes(name)) {
            if (name !== chamomile.name || !recoveryLinePending)
              remainingProducts.delete(name)
            continue
          }
          if (name === chamomile.name) recoveryLinePending = false
          if (attemptedLines.has(name)) continue
          await attempt(`Remove account line: ${name}`, async () => {
            await clickObservedControl(
              cartLine(tab, name).getByTestId('shop-cart-remove-line'),
              () => {
                attemptedLines.add(name)
              },
            )
            await waitForCart(
              `The account line is still present: ${name}`,
              (current) => !current.lines.includes(name),
            )
            remainingProducts.delete(name)
          })
        }
      }

      await settleVoucher(await currentCart())
      await removeAvailableLines()
      if (hasBindings()) {
        // A lost reply can leave a late Add or removal visible on this same Cart.
        await settleVoucher(await currentCart())
        if (recoveryLinePending) {
          const observed = await attempt('Observe a late temporary line', () =>
            waitForCart(
              'The temporary Add outcome is still unknown',
              (cart) => {
                recoveryAdd?.readRefusal()
                return cart.lines.includes(chamomile.name)
              },
            ),
          )
          if (observed) {
            recoveryLinePending = false
            await settleVoucher(await currentCart())
          }
        }
        await removeAvailableLines()
      }
      if (hasBindings()) {
        const remaining = [...remainingProducts]
        if (!voucherCleared) remaining.unshift('voucher slot')
        throw new Error(
          `Unresolved account cart bindings: ${remaining.join(', ')}`,
        )
      }
    } finally {
      recoveryAdd?.readRefusal()
      recoveryAdd?.stop()
      await attempt('Close the account cleanup context', () => context.close())
    }
  }

  const user =
    await test.step('prepare two guest cart lines and a voucher', async () => {
      const user = await createTestUser(expect, page)
      await logout(expect, page)
      await expect(cartBadge(page)).toHaveText('0')

      await createVoucherFixture(
        expect,
        {
          defer: (deleteVoucher) =>
            cleanup.defer(async () => {
              let sourceRefusal: Error | undefined
              let failure: { error: unknown } | undefined
              const deleteOnce = async () => {
                try {
                  await deleteVoucher()
                  return 'deleted'
                } catch (error) {
                  // A receipt may still be releasing the source after the account cleared.
                  if (
                    accountVoucherCleared &&
                    error instanceof Error &&
                    error.message === VOUCHER_IN_CART
                  ) {
                    sourceRefusal = error
                    return 'source still in use'
                  }
                  failure = { error }
                  return 'failed'
                }
              }
              try {
                if ((await deleteOnce()) === 'source still in use') {
                  await expect
                    .poll(
                      async () =>
                        (await deleteOnce()) !== 'source still in use',
                    )
                    .toBe(true)
                }
                if (failure) throw failure.error
              } catch (error) {
                const diagnostic = sourceRefusal
                  ? new AggregateError(
                      [error, sourceRefusal],
                      'Voucher fixture deletion failed',
                    )
                  : error
                throw safeDiagnostic(diagnostic, guestToken)
              }
            }),
        },
        { code: voucherCode, discountPercent: 10, active: true },
      )
      const token = await sessionToken(page)
      guestToken = token
      cleanup.defer(async () => {
        if (!hasBindings()) return

        let guestUnavailable = false
        const removeGuestBinding = async (
          commandName: string,
          operation: string,
          remove: () => Promise<void>,
        ) => {
          try {
            await remove()
          } catch (error) {
            if (
              signInStarted &&
              error instanceof Error &&
              error.message ===
                `${commandName} failed: ${GUEST_SESSION_UNAVAILABLE}`
            ) {
              guestUnavailable = true
            } else {
              recordError(operation, error)
            }
          }
        }

        if (!voucherCleared) {
          await removeGuestBinding(
            GUEST_CART_SET_VOUCHER_COMMAND,
            'Clear the guest voucher',
            async () => {
              assert.deepEqual(await setGuestVoucher(token, false), {
                applied: false,
              })
              voucherCleared = true
            },
          )
        }
        for (const { id, name } of products) {
          if (guestUnavailable) break
          if (!remainingProducts.has(name)) continue
          await removeGuestBinding(
            GUEST_CART_SET_QUANTITY_COMMAND,
            `Clear guest line: ${name}`,
            async () => {
              assert.deepEqual(await setGuestCartQuantity(token, id, 0), {
                quantity: 0,
              })
              remainingProducts.delete(name)
            },
          )
        }

        if (hasBindings() && (voucherSeenOnAccount || guestUnavailable)) {
          await attempt('Clean the same account cart', () =>
            cleanAccountCart(user),
          )
        }

        if (cleanupErrors.length > 0) {
          throw new AggregateError(
            cleanupErrors,
            `Checkout cart cleanup failed:\n${cleanupErrors.map((error) => error.stack ?? error.message).join('\n\n')}`,
            { cause: cleanupErrors[0] },
          )
        }
      })

      try {
        assert.deepEqual(await setGuestCartQuantity(token, chamomile.id, 2), {
          quantity: 2,
        })
        assert.deepEqual(await setGuestCartQuantity(token, meadow.id, 1), {
          quantity: 1,
        })
        voucherCleared = false
        assert.deepEqual(await setGuestVoucher(token, true), { applied: true })
      } catch (error) {
        throw safeDiagnostic(error, token)
      }
      return user
    })

  await test.step('open checkout as a guest and see sign-in in place', async () => {
    await gotoPage(expect, page, '/checkout', PAGE_REFUSED)
    await markDocument(page)
    await expect(auth).toBeVisible()
    await expect(page.getByRole('dialog', { name: 'Sign in' })).toHaveCount(0)
    await expect(page).toHaveURL('/checkout')
  })

  await test.step('sign in and see the order and its disabled Pay button', async () => {
    signInStarted = true
    await login(expect, page, user.email)
    await expect(auth).toBeHidden()

    const lines = page.getByTestId('shop-checkout-line')
    await expect(lines).toHaveCount(2)
    for (const { name, amount } of [
      { name: `${chamomile.name} × 2`, amount: '₴560' },
      { name: meadow.name, amount: '₴1,850' },
    ]) {
      const line = lines.filter({ has: page.getByText(name, { exact: true }) })
      await expect(line.getByText(amount, { exact: true })).toBeVisible()
    }

    const voucher = page.getByTestId('shop-checkout-voucher')
    await expect(voucher.getByText(voucherCode, { exact: true })).toBeVisible()
    voucherSeenOnAccount = true
    await expect(voucher.getByText('−₴241', { exact: true })).toBeVisible()
    await expect(checkoutTotal).toHaveText('₴2,169')
    const pay = page.getByTestId('shop-checkout-pay')
    await expect(pay).toBeDisabled()
    await expect(pay).toHaveAccessibleName('Pay ₴2,169')
    await expectDocumentUnchanged(expect, page)
    await expect(page).toHaveURL('/checkout')
  })

  const cartTab =
    await test.step('increase the cart quantity in another tab and see checkout recalculate', async () => {
      const tab = await page.context().newPage()
      await openCart(tab)
      const line = cartLine(tab, chamomile.name)
      await line.getByRole('button', { name: 'One more', exact: true }).click()
      await expect(line.getByTestId('shop-cart-quantity')).toHaveText(
        'Quantity: 3',
      )
      await expect(checkoutTotal).toHaveText('₴2,421')
      await expectDocumentUnchanged(expect, page)
      return tab
    })

  await test.step('empty the cart in the other tab and go shopping from checkout', async () => {
    await removeOwnVoucher(cartTab)
    await page.getByTestId('shop-checkout-back-to-cart').focus()
    for (const { name } of products) {
      await removeCartLine(cartTab, name)
    }
    await expect(checkoutEmpty).toBeVisible()
    await expectDocumentUnchanged(expect, page)

    const shopping = checkoutEmpty.getByTestId('shop-checkout-go-shopping')
    await expect(shopping).toBeFocused()
    await shopping.click()
    await expect(page).toHaveURL('/')
    await expectPageReady(expect, page)
  })
})
