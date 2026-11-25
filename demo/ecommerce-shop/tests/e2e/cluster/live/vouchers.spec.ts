import { randomBytes } from 'node:crypto'
import type { Page } from '@playwright/test'

import { expect, test as base } from '../../test.js'
import { submitCartVoucher } from '../../helpers/cartVoucher.js'
import { PAGE_CART } from '../../../../frontend/src/pages/keys.js'
import {
  cartSnapshotSchema,
  type CartSnapshot,
} from '../../../../frontend/src/views/Cart/types/Cart.js'
import {
  FIELD_PAGE,
  FIELD_TYPE,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  pageResponseSchema,
  scopePayloadSchema,
} from '../../../../../../framework/frontend/core/src/base/index.js'
import {
  PAGE_READY,
  ROWS,
  cleanupFixtures,
  clusterCommandChannel,
  clusterNodeHost,
  clusterPlacementNode,
  createTestUser,
  deliveryObject,
  expectClusterSingleAgents,
  expectDocumentUnchanged,
  expectSessionRotation,
  gotoPage,
  login,
  markDocument,
  newClusterMasterPage,
  openSignIn,
  readClusterBrowserStand,
  sessionToken,
  setClusterMaster,
  type DeliveryFrameLog,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  markCatalogWindow,
  openCatalogAdmin,
  watchCatalogPage,
} from '../catalog.js'

// spec-owner: demo — the shop's voucher discounts and transfer across cluster nodes.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
const stand = readClusterBrowserStand()
const vouchersAgent = 'vouchers_library'
const guestAgent = 'guest_carts_library'
const vouchersPath = '/hilos/vouchers'
const cartPath = '/cart'
const product = { productId: 16, name: 'Pine bark 5 L', quantity: 2 }
const discountPercent = 20
const itemsTotalKopecks = '29000'
const discountKopecks = '5800'
const discountedTotalKopecks = '23200'
const itemsAmount = '₴290'
const deduction = '−₴58'
const discountedTotal = '₴232'

// Sign-in traffic and the session cookie must stay out of browser traces.
test.use({ trace: 'off' })

/** Keep only the cart payload and confirmed identity from the physical frames. */
function readCartTraffic(delivery: DeliveryFrameLog) {
  const frames = delivery.packets.flatMap(({ frames, at }) =>
    frames.map((frame) => ({ ...frame, at })),
  )
  const identities = frames.flatMap(({ wire, socket, message, at }, index) => {
    const frame = deliveryObject(wire)
    if (frame.type !== SIGNAL_HANDSHAKE_RESPONSE) return []
    const payload = scopePayloadSchema.safeParse(frame.data)
    const user = payload.data?.entities?.currentUser
    const userId = user === null ? null : deliveryObject(user).id
    return [{ socket, message, index, at, userId }]
  })
  const wholes = frames.flatMap(({ wire, socket, message, at }, index) => {
    const frame = deliveryObject(wire)
    if (frame.type !== SIGNAL_TYPE_PAGE_RESPONSE) return []
    if (deliveryObject(frame.data).page !== PAGE_CART) return []
    const response = pageResponseSchema.safeParse(frame.data)
    const cart = cartSnapshotSchema.safeParse(
      response.data?.payload?.data?.cart,
    )
    return cart.success ? [{ socket, message, index, at, cart: cart.data }] : []
  })
  const subscriptions = delivery.sent.flatMap(({ wire, socket, at }) => {
    const request = deliveryObject(wire)
    return request[FIELD_TYPE] === SIGNAL_TYPE_PAGE_SUBSCRIBE &&
      request[FIELD_PAGE] === PAGE_CART
      ? [{ socket, at }]
      : []
  })
  const epochs = identities.map((identity, position) => ({
    ...identity,
    answers: wholes.filter(
      (answer) =>
        answer.socket === identity.socket &&
        identity.index < answer.index &&
        answer.index < (identities[position + 1]?.index ?? Infinity),
    ),
  }))
  return { epochs, subscriptions, wholes }
}

function latestCart(delivery: DeliveryFrameLog): CartSnapshot | undefined {
  return readCartTraffic(delivery).wholes.at(-1)?.cart
}

function cartSummaryLocators(page: Page) {
  const items = page
    .getByText('Items', { exact: true })
    .locator('..')
    .locator('dd')
  const voucherRow = page.getByTestId('shop-cart-voucher')
  const voucherLabel = voucherRow.locator('dt')
  const voucherAmount = voucherRow.locator('dd')
  const total = page.getByTestId('shop-cart-total')
  return { items, voucherRow, voucherLabel, voucherAmount, total }
}

test('a voucher applied in a guest cart follows admin toggles and sign-in transfer across cluster masters', async ({
  browser,
  cleanup,
}) => {
  const leader = stand.leader
  const follower = stand.follower
  expect(leader).not.toBe(follower)

  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const command = clusterCommandChannel(stand, leader)

  const placements: { agent: string; node: string }[] = []
  const recordAgentPlacement = async (agent: string) => {
    await expectClusterSingleAgents(expect, stand, [agent])
    const node = await clusterPlacementNode(expect, stand, agent)
    expect(stand.slaves).toContain(node)
    placements.push({ agent, node })
    return node
  }

  await test.step('prove the single live vouchers library placement on a worker', async () => {
    await recordAgentPlacement(vouchersAgent)
    await recordAgentPlacement(guestAgent)
  })

  // cleanupFixtures runs LIFO: open browser contexts first so they close last.
  const admin = await openCatalogAdmin(browser, stand, leader, cleanup)

  const accountPage = await newClusterMasterPage(browser, stand, follower)
  cleanup.defer(() => accountPage.context().close())
  const account = await watchCatalogPage(accountPage, stand, follower, cleanup)
  const user = await createTestUser(expect, accountPage, {
    commandHost: clusterNodeHost(stand, leader),
  })
  await gotoPage(expect, accountPage, cartPath, PAGE_READY)
  await expect(accountPage.getByTestId('shop-cart-empty')).toBeVisible()
  await account.upgrades.assert()

  const guestPage = await newClusterMasterPage(browser, stand, follower)
  cleanup.defer(() => guestPage.context().close())
  const guest = await watchCatalogPage(guestPage, stand, follower, cleanup)
  await gotoPage(expect, guestPage, cartPath, PAGE_READY)
  await expect(guestPage.getByTestId('shop-cart-empty')).toBeVisible()
  await guest.upgrades.assert()
  const guestToken = await sessionToken(guestPage)

  cleanup.defer(async () => {
    await test.info().attach('voucher-cluster-lifecycle', {
      body: JSON.stringify(
        {
          leader,
          follower,
          placements,
          guestTraffic: readCartTraffic(guest.delivery),
          accountTraffic: readCartTraffic(account.delivery),
        },
        null,
        2,
      ),
      contentType: 'application/json',
    })
  })

  // Register voucher fixture deletion before cart cleanup so LIFO clears bindings first.
  const expectVoucherAbsent = async () => {
    expect(
      await command('test:ecommerce-shop:voucher-fixture-get', { code }),
    ).toEqual({ voucher: null })
  }
  await expectVoucherAbsent()
  cleanup.defer(expectVoucherAbsent)
  cleanup.defer(() =>
    command('test:ecommerce-shop:voucher-fixture-delete', { code }),
  )
  expect(
    await command('test:ecommerce-shop:voucher-fixture-create', {
      code,
      discountPercent,
      active: true,
    }),
  ).toMatchObject({
    voucher: { code, discountPercent, active: true },
  })

  // Register cart binding and item cleanup so it runs first in LIFO teardown.
  cleanup.defer(async () => {
    if ((await sessionToken(guestPage)) === guestToken) {
      expect(
        await command('test:ecommerce-shop:guest-cart-set-voucher', {
          sessionToken: guestToken,
          code,
          applied: false,
        }),
      ).toEqual({ applied: false })
      expect(
        await command('test:ecommerce-shop:guest-cart-set-quantity', {
          sessionToken: guestToken,
          productId: product.productId,
          quantity: 0,
        }),
      ).toEqual({ quantity: 0 })
      return
    }

    await expect
      .poll(() => {
        const cart = latestCart(account.delivery)
        return cart?.userId === user.userId && cart.guestCartKey === null
          ? cart
          : null
      })
      .not.toBeNull()

    const removeVoucher = accountPage.getByTestId('cart-voucher-remove')
    if (await removeVoucher.isVisible()) {
      await removeVoucher.click()
      await expect(accountPage.getByTestId('shop-cart-voucher')).toHaveCount(0)
      await expect.poll(() => latestCart(account.delivery)?.voucher).toBeNull()
    }

    const removeLine = accountPage.getByTestId('shop-cart-remove-line')
    if (await removeLine.isVisible()) {
      await removeLine.click()
      await expect(accountPage.getByTestId('shop-cart-empty')).toBeVisible()
      await expect
        .poll(() => latestCart(account.delivery)?.lines.length)
        .toBe(0)
    }
  })

  await gotoPage(expect, admin.page, vouchersPath, PAGE_READY)
  const adminUnchanged = await markCatalogWindow(admin)
  const table = admin.page.getByTestId('admin-vouchers-table')
  const row = table
    .locator(ROWS)
    .filter({ has: admin.page.getByText(code, { exact: true }) })
  const activeToggle = row.getByTestId('admin-vouchers-active-toggle')
  await expect(row).toBeVisible()
  await expect(activeToggle).toBeChecked()

  const guestSummary = cartSummaryLocators(guestPage)
  const openVoucherForm = guestPage.getByTestId('cart-voucher-apply-open')
  const voucherForm = guestPage.getByTestId('cart-voucher-apply')
  const voucherCode = voucherForm.getByTestId('cart-voucher-apply-code')
  const voucherError = voucherForm.getByTestId('cart-voucher-apply-error')
  const guestUnchanged = await markCatalogWindow(guest)

  await test.step('apply the voucher in the open guest cart on the follower master', async () => {
    expect(
      await command('test:ecommerce-shop:guest-cart-set-quantity', {
        sessionToken: guestToken,
        productId: product.productId,
        quantity: product.quantity,
      }),
    ).toEqual({ quantity: product.quantity })

    const line = guestPage.getByTestId('shop-cart-line')
    await expect(line).toHaveCount(1)
    await expect(line.getByTestId('shop-cart-product-link')).toHaveText(
      product.name,
    )
    await expect(line.getByTestId('shop-cart-quantity')).toHaveText(
      `Quantity: ${product.quantity}`,
    )

    await openVoucherForm.click()
    await submitCartVoucher(expect, guestPage, code)
    await expect(voucherForm).toBeHidden()

    await expect
      .poll(() => latestCart(guest.delivery))
      .toMatchObject({
        userId: null,
        itemsTotal: itemsTotalKopecks,
        voucher: {
          code,
          active: true,
          discount: discountKopecks,
        },
        total: discountedTotalKopecks,
      })
    await expect(guestSummary.voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(guestSummary.voucherAmount).toHaveText(deduction)
    await expect(guestSummary.items).toHaveText(itemsAmount)
    await expect(guestSummary.total).toHaveText(discountedTotal)
    await guestUnchanged()
  })

  const initialGuestCartKey = latestCart(guest.delivery)?.guestCartKey
  expect(initialGuestCartKey).toBeTruthy()

  await test.step('switch off the voucher on the leader, refuse re-apply on the follower, and switch it back on', async () => {
    await activeToggle.click()
    await expect(activeToggle).not.toHaveAttribute('aria-busy', 'true')
    await expect(activeToggle).toBeEnabled()
    await expect(activeToggle).not.toBeChecked()
    await expect(row).toBeVisible()
    await adminUnchanged()

    await expect
      .poll(() => latestCart(guest.delivery))
      .toMatchObject({
        userId: null,
        guestCartKey: initialGuestCartKey,
        itemsTotal: itemsTotalKopecks,
        voucher: {
          code,
          active: false,
          discount: '0',
        },
        total: itemsTotalKopecks,
      })
    await expect(guestSummary.voucherLabel).toHaveText(
      `Voucher ${code} · Inactive`,
    )
    await expect(guestSummary.voucherAmount).toHaveText('—')
    await expect(guestSummary.items).toHaveText(itemsAmount)
    await expect(guestSummary.total).toHaveText(itemsAmount)
    await guestUnchanged()

    await openVoucherForm.click()
    await submitCartVoucher(expect, guestPage, code)
    await expect(
      voucherError.getByText('This voucher is inactive', { exact: true }),
    ).toBeVisible()
    await expect(voucherCode).toHaveValue(code)
    await expect(guestSummary.voucherLabel).toHaveText(
      `Voucher ${code} · Inactive`,
    )
    await expect(guestSummary.voucherAmount).toHaveText('—')
    await expect(guestSummary.items).toHaveText(itemsAmount)
    await expect(guestSummary.total).toHaveText(itemsAmount)

    await guestPage.getByTestId('modal-close').click()
    await guestPage.getByTestId('modal-confirm-discard').click()
    await expect(voucherForm).toBeHidden()
    await guestUnchanged()

    await activeToggle.click()
    await expect(activeToggle).not.toHaveAttribute('aria-busy', 'true')
    await expect(activeToggle).toBeEnabled()
    await expect(activeToggle).toBeChecked()
    await expect(row).toBeVisible()
    await adminUnchanged()

    await expect
      .poll(() => latestCart(guest.delivery))
      .toMatchObject({
        userId: null,
        guestCartKey: initialGuestCartKey,
        itemsTotal: itemsTotalKopecks,
        voucher: {
          code,
          active: true,
          discount: discountKopecks,
        },
        total: discountedTotalKopecks,
      })
    await expect(guestSummary.voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(guestSummary.voucherAmount).toHaveText(deduction)
    await expect(guestSummary.items).toHaveText(itemsAmount)
    await expect(guestSummary.total).toHaveText(discountedTotal)
    await guestUnchanged()
  })

  await test.step('sign in from a second tab on the leader and transfer the voucher to the open follower account cart', async () => {
    await expect(accountPage.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(accountPage.getByTestId('shop-cart-voucher')).toHaveCount(0)
    expect(latestCart(account.delivery)).toMatchObject({
      userId: user.userId,
      guestCartKey: null,
      lines: [],
      itemsTotal: '0',
      voucher: null,
      total: '0',
    })
    const accountBeforeSignIn = readCartTraffic(account.delivery)
    expect(accountBeforeSignIn.subscriptions).toHaveLength(1)
    const accountSocket = accountBeforeSignIn.subscriptions[0]?.socket
    expect(accountSocket).toBeDefined()
    const accountAnswersBeforeSignIn = accountBeforeSignIn.wholes.length
    const accountUnchanged = await markCatalogWindow(account)

    await setClusterMaster(guestPage, stand, leader)
    const signInPage = await guestPage.context().newPage()
    const signInTab = await watchCatalogPage(signInPage, stand, leader, cleanup)
    await gotoPage(expect, signInPage, cartPath, PAGE_READY)
    await signInTab.upgrades.assert()
    expect(await sessionToken(signInPage)).toBe(guestToken)

    const signInSummary = cartSummaryLocators(signInPage)
    await expect(signInSummary.voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(signInSummary.voucherAmount).toHaveText(deduction)
    await expect(signInSummary.items).toHaveText(itemsAmount)
    await expect(signInSummary.total).toHaveText(discountedTotal)
    expect(latestCart(signInTab.delivery)).toMatchObject({
      userId: null,
      guestCartKey: initialGuestCartKey,
      itemsTotal: itemsTotalKopecks,
      voucher: {
        code,
        active: true,
        discount: discountKopecks,
      },
      total: discountedTotalKopecks,
    })
    await markDocument(signInPage)

    await openSignIn(expect, signInPage)
    await expectSessionRotation(expect, signInPage, () =>
      login(expect, signInPage, user.email),
    )
    expect(
      (await sessionToken(signInPage)) !== guestToken,
      'Sign-in rotated the cookie',
    ).toBe(true)
    await signInTab.upgrades.assert()

    await expect
      .poll(
        () => {
          const { epochs, subscriptions } = readCartTraffic(signInTab.delivery)
          const accountEpoch = epochs.find(
            (epoch) => epoch.socket === 2 && epoch.userId === user.userId,
          )
          const subscription = subscriptions[1]
          if (
            accountEpoch === undefined ||
            subscription?.socket !== accountEpoch.socket ||
            subscription.at < accountEpoch.at
          ) {
            return false
          }

          return accountEpoch.answers.some(({ at, cart }) => {
            return (
              at > subscription.at &&
              cart.userId === user.userId &&
              cart.guestCartKey === null &&
              cart.lines.length === 1 &&
              cart.lines[0].productId === product.productId &&
              cart.lines[0].quantity === product.quantity &&
              cart.itemsTotal === itemsTotalKopecks &&
              cart.voucher?.code === code &&
              cart.voucher.active === true &&
              cart.voucher.discount === discountKopecks &&
              cart.total === discountedTotalKopecks
            )
          })
        },
        {
          message:
            'The transferred account voucher and discount arrived on the rotated leader socket',
        },
      )
      .toBe(true)

    await expect(signInSummary.voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(signInSummary.voucherAmount).toHaveText(deduction)
    await expect(signInSummary.items).toHaveText(itemsAmount)
    await expect(signInSummary.total).toHaveText(discountedTotal)
    await expectDocumentUnchanged(expect, signInPage)
    expect(new URL(signInPage.url()).pathname).toBe(cartPath)

    await expect
      .poll(
        () => {
          const { subscriptions, wholes } = readCartTraffic(account.delivery)
          if (
            subscriptions.length !== 1 ||
            subscriptions[0]?.socket !== accountSocket
          ) {
            return false
          }
          return wholes
            .slice(accountAnswersBeforeSignIn)
            .some(({ socket, cart }) => {
              return (
                socket === accountSocket &&
                cart.userId === user.userId &&
                cart.guestCartKey === null &&
                cart.lines.length === 1 &&
                cart.lines[0].productId === product.productId &&
                cart.lines[0].quantity === product.quantity &&
                cart.itemsTotal === itemsTotalKopecks &&
                cart.voucher?.code === code &&
                cart.voucher.active === true &&
                cart.voucher.discount === discountKopecks &&
                cart.total === discountedTotalKopecks
              )
            })
        },
        {
          message:
            'The open follower account cart transitioned from empty to the transferred voucher on its existing socket',
        },
      )
      .toBe(true)

    const accountSummary = cartSummaryLocators(accountPage)
    await expect(accountPage.getByTestId('shop-cart-empty')).toHaveCount(0)
    await expect(accountPage.getByTestId('shop-cart-product-link')).toHaveText(
      product.name,
    )
    await expect(accountSummary.voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(accountSummary.voucherAmount).toHaveText(deduction)
    await expect(accountSummary.items).toHaveText(itemsAmount)
    await expect(accountSummary.total).toHaveText(discountedTotal)
    await accountUnchanged()

    await recordAgentPlacement(`hilos_user:${user.userId}`)
  })
})
