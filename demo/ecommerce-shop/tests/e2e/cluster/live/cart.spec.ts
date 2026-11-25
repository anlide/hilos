import type { Page } from '@playwright/test'

import { expect, test as base } from '../../test.js'
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
import { activeClusterAgents } from '../../../../../../framework/frontend/e2e/cluster.js'
import {
  PAGE_READY,
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
  logout,
  markDocument,
  newClusterMasterPage,
  openSignIn,
  readClusterBrowserStand,
  sessionToken,
  watchClusterMasterUpgrades,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the shop's open cart follows its session through owner changes.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
const stand = readClusterBrowserStand()
const guestAgent = 'guest_carts_library'
const rtAgent = 'ecommerce_shop'
const cartPath = '/cart'
const productPath = '/product/pine-bark-5-l'
const product = { productId: 16, name: 'Pine bark 5 L' }
const guestProduct = { ...product, quantity: 3 }
const accountProduct = { ...product, quantity: 1 }
const combinedProduct = {
  ...product,
  quantity: accountProduct.quantity + guestProduct.quantity,
}
const addedProduct = {
  ...product,
  quantity: combinedProduct.quantity + 1,
}

// Sign-in traffic and the session cookie must stay out of browser traces.
test.use({ trace: 'off' })

type CartState = 'loading' | 'line' | 'empty'

type CartPacket = {
  socket: number
  message: number
}

type CartObservation = CartPacket & {
  visible: CartState[]
  lines: {
    node: number
    lineId: number
    name: string | null
    quantity: string | null
  }[]
  changed: boolean
  afterMessage: boolean
}

type CartDocument = Window & { cartObservations?: CartObservation[] }

/** Observe cart commits and take a snapshot after every native message. */
async function watchCartObservations(page: Page) {
  await page.addInitScript((socketPath) => {
    const observations: CartObservation[] = []
    Object.assign(window, { cartObservations: observations })
    const lineNodes = new WeakMap<Element, number>()
    let nextLineNode = 0
    let opened = 0
    let cursor: CartPacket = { socket: 0, message: 0 }
    let previous = ''
    const hooks = {
      line: 'shop-cart-line',
      empty: 'shop-cart-empty',
      loading: 'shop-cart-skeleton',
    }
    const states = Object.keys(hooks) as CartState[]
    const record = (packet: CartPacket, afterMessage: boolean) => {
      const elements = (state: CartState) =>
        Array.from(
          document.querySelectorAll(`[data-id="${hooks[state]}"]`),
        ).filter((element) =>
          element.checkVisibility({
            opacityProperty: true,
            visibilityProperty: true,
          }),
        )
      const rows = elements('line')
      const visible = states.filter((state) =>
        state === 'line' ? rows.length > 0 : elements(state).length > 0,
      )
      const lines = rows.map((row) => {
        const node = lineNodes.get(row) ?? ++nextLineNode
        lineNodes.set(row, node)
        return {
          node,
          lineId: Number(row.getAttribute('data-line-id')),
          name:
            row.querySelector('[data-id="shop-cart-product-link"]')
              ?.textContent ?? null,
          quantity:
            row.querySelector('[data-id="shop-cart-quantity"]')?.textContent ??
            null,
        }
      })
      const presentation = JSON.stringify({ visible, lines })
      const changed = presentation !== previous
      previous = presentation
      if (afterMessage || changed) {
        observations.push({ ...packet, visible, lines, changed, afterMessage })
      }
    }
    window.WebSocket = new Proxy(window.WebSocket, {
      construct(target, args, newTarget) {
        const connection: WebSocket = Reflect.construct(target, args, newTarget)
        if (new URL(connection.url).pathname === socketPath) {
          const socket = ++opened
          let message = 0
          connection.addEventListener(
            'message',
            () => {
              cursor = { socket, message: ++message }
            },
            { capture: true },
          )
          // The client registers its listeners when it constructs the socket.
          // Sample after its handler and React's sync external-store commit.
          // All signals in a batch share this checkpoint, even if rotation
          // constructs another socket synchronously during delivery.
          queueMicrotask(() => {
            connection.addEventListener('message', () => {
              const packet = cursor
              queueMicrotask(() => record(packet, true))
            })
          })
        }
        return connection
      },
    })
    const observer = new MutationObserver(() => record(cursor, false))
    observer.observe(document, {
      attributes: true,
      childList: true,
      subtree: true,
    })
  }, '/ws')
  return () =>
    page.evaluate(() => (window as CartDocument).cartObservations ?? [])
}

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
    return [{ socket, message, index, at, cart: cart.data }]
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

type CartEpoch = ReturnType<typeof readCartTraffic>['epochs'][number]
type CartAnswer = CartEpoch['answers'][number]

function cartAnswerAt(answers: CartAnswer[], { socket, message }: CartPacket) {
  // React may commit after delivery, or combine several inners of one packet.
  return answers
    .filter((answer) => answer.socket === socket && answer.message <= message)
    .at(-1)
}

function showsCart({ visible, lines }: CartObservation, cart: CartSnapshot) {
  return (
    visible.length === 1 &&
    visible[0] === (cart.lines.length === 0 ? 'empty' : 'line') &&
    lines.length === cart.lines.length &&
    lines.every(({ lineId, name, quantity }, index) => {
      const expected = cart.lines[index]
      return (
        lineId === expected.lineId &&
        name === expected.name &&
        quantity === `Quantity: ${expected.quantity}`
      )
    })
  )
}

function isCartContent({ visible }: CartObservation) {
  return visible.includes('line') || visible.includes('empty')
}

function confirmationStart(
  observations: CartObservation[],
  { socket, message }: CartPacket,
) {
  const matches = (packet: CartPacket) =>
    packet.socket === socket && packet.message === message
  expect(
    observations.some((sample) => sample.afterMessage && matches(sample)),
    `Cart sampled after identity confirmation on socket ${socket}, message ${message}`,
  ).toBe(true)
  return observations.findIndex(matches)
}

/** Check every cart render from identity confirmation through the fresh reply. */
function expectCartOwnerChange(
  observations: CartObservation[],
  answers: CartAnswer[],
  previous: CartObservation,
) {
  const firstRender = observations.findIndex(isCartContent)
  expect(
    firstRender,
    'The new cart renders after the old cart clears',
  ).toBeGreaterThan(0)
  const [firstAnswer] = answers
  if (!firstAnswer) throw new Error('Missing fresh cart response')

  // The first packet sets the loading deadline even if its cart is never drawn.
  const firstAnswerSample = observations.findIndex(
    ({ socket, message }) =>
      socket === firstAnswer.socket && message === firstAnswer.message,
  )
  expect(
    firstAnswerSample,
    'Cart sampled the first response on the fresh socket',
  ).toBeGreaterThanOrEqual(0)
  expect(
    observations
      .slice(0, Math.min(firstRender, firstAnswerSample))
      .some(({ visible }) => visible.join() === 'loading'),
    'Cart loads after identity confirmation and before the first fresh response',
  ).toBe(true)

  const previousNodes = new Set(previous.lines.map(({ node }) => node))
  for (const [index, sample] of observations.slice(firstRender).entries()) {
    if (index > 0 && !sample.changed) continue
    const answer = cartAnswerAt(answers, sample)
    expect(
      answer?.cart !== undefined && showsCart(sample, answer.cart),
      `Cart render matches the latest whole on socket ${sample.socket}, message ${sample.message}`,
    ).toBe(true)
    expect(
      sample.lines.some(({ node }) => previousNodes.has(node)),
      'The previous owner’s rows stay out of the new cart',
    ).toBe(false)
  }
}

test('an open cart follows sign-in and sign-out across cluster nodes', async ({
  browser,
  cleanup,
}) => {
  const expectProductLine = async (page: Page, quantity: number) => {
    const line = page.getByTestId('shop-cart-line')
    await expect(line).toHaveCount(1)
    await expect(line.getByTestId('shop-cart-product-link')).toHaveText(
      product.name,
    )
    await expect(line.getByTestId('shop-cart-quantity')).toHaveText(
      `Quantity: ${quantity}`,
    )
  }

  const master = stand.follower
  expect(stand.leader).not.toBe(master)
  const setup = await newClusterMasterPage(browser, stand, stand.leader)
  cleanup.defer(() => setup.context().close())
  const setupUpgrades = await watchClusterMasterUpgrades(
    expect,
    setup,
    stand,
    stand.leader,
  )
  cleanup.defer(setupUpgrades.stop)
  const productCartCount = setup
    .getByTestId('product-cart-count')
    .filter({ visible: true })
  const expectProductCartCount = async (text: string) => {
    await expect(productCartCount).toHaveText(text)
    await expect(setup).toHaveURL(productPath)
    await expectDocumentUnchanged(expect, setup)
  }
  const addAccountUnit = async (expectedQuantity: number) => {
    const addButton = setup.getByTestId('shop-product-add-to-cart')
    await addButton.click()
    await expect(
      addButton.and(setup.locator('[aria-busy="true"]')),
    ).toHaveCount(0)
    await expect(
      setup.getByTestId('nav-cart').getByTestId('cart-badge'),
    ).toHaveText(String(expectedQuantity))
  }
  const user = await createTestUser(expect, setup, {
    commandHost: clusterNodeHost(stand, stand.leader),
  })
  await test.step('add one unit to the account cart', async () => {
    await gotoPage(expect, setup, productPath, PAGE_READY)
    await addAccountUnit(accountProduct.quantity)
    await gotoPage(expect, setup, cartPath, PAGE_READY)
    await expectProductLine(setup, accountProduct.quantity)
    await gotoPage(expect, setup, productPath, PAGE_READY)
    await expect(setup.getByRole('heading', { level: 1 })).toHaveText(
      product.name,
    )
    await markDocument(setup)
    await expectProductCartCount('In 1 cart')
    await setupUpgrades.assert()
  })
  const page = await newClusterMasterPage(browser, stand, master)
  cleanup.defer(() => page.context().close())
  await gotoPage(expect, page, '/', PAGE_READY)
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  const command = clusterCommandChannel(stand, stand.leader)
  const setQuantity = async (quantity: number) =>
    command('test:ecommerce-shop:guest-cart-set-quantity', {
      sessionToken: await sessionToken(page),
      productId: product.productId,
      quantity,
    })
  // The same stored session becomes a guest again on logout, on its new token.
  cleanup.defer(async () => {
    if (await page.getByTestId('nav-profile-name').isVisible()) {
      await expectSessionRotation(expect, page, () => logout(expect, page))
    }
    await setQuantity(0)
  })
  await setQuantity(guestProduct.quantity)

  const delivery = watchDeliveryFrames(page)
  cleanup.defer(delivery.stop)
  const upgrades = await watchClusterMasterUpgrades(expect, page, stand, master)
  cleanup.defer(upgrades.stop)
  const readObservations = await watchCartObservations(page)
  const line = page.getByTestId('shop-cart-line')

  const topology: {
    phase: string
    socket: string
    rtSource: string
    agent: string
    serving: string
  }[] = []
  cleanup.defer(async () => {
    await test.info().attach('cart-owner-lifecycle', {
      body: JSON.stringify(
        {
          topology,
          ...readCartTraffic(delivery),
          observations: await readObservations(),
        },
        null,
        2,
      ),
      contentType: 'application/json',
    })
  })
  const recordPlacement = async (phase: string, agent: string) => {
    await setupUpgrades.assert()
    await upgrades.assert()
    await expectClusterSingleAgents(expect, stand, [agent])
    const serving = await clusterPlacementNode(expect, stand, agent)
    expect(stand.slaves).toContain(serving)
    const sources = Object.entries(stand.nodeLogs).flatMap(([node, path]) =>
      activeClusterAgents(path, [rtAgent]).map(() => node),
    )
    expect(
      sources,
      'The RT connections owner is running on the leader',
    ).toEqual([stand.leader])
    topology.push({
      phase,
      socket: master,
      rtSource: sources[0],
      agent,
      serving,
    })
  }

  await test.step('open the saved guest cart', async () => {
    await expectProductCartCount('In 2 carts')
    await gotoPage(expect, page, cartPath, PAGE_READY)
    await expectProductLine(page, guestProduct.quantity)
    await markDocument(page)
    await recordPlacement('guest', guestAgent)
  })

  const guestToken = await sessionToken(page)
  await test.step('sign in and see the transferred line in the account cart', async () => {
    await openSignIn(expect, page)
    await expectSessionRotation(expect, page, () =>
      login(expect, page, user.email),
    )
    expect(
      (await sessionToken(page)) !== guestToken,
      'Sign-in rotated the cookie',
    ).toBe(true)
    await expect
      .poll(
        async () => {
          const { epochs, subscriptions } = readCartTraffic(delivery)
          const account = epochs[2]
          const subscription = subscriptions[1]
          if (
            account?.socket !== 2 ||
            account.userId !== user.userId ||
            subscription?.socket !== account.socket ||
            subscription.at < account.at
          )
            return false

          return (await readObservations()).some((sample) => {
            const answer = cartAnswerAt(account.answers, sample)
            const cart = answer?.cart
            return (
              answer !== undefined &&
              answer.at > subscription.at &&
              cart?.userId === user.userId &&
              cart.guestCartKey === null &&
              cart.lines.length === 1 &&
              cart.lines[0].productId === product.productId &&
              cart.lines[0].quantity === combinedProduct.quantity &&
              showsCart(sample, cart)
            )
          })
        },
        {
          message:
            'The transferred account line rendered from the fresh subscription',
        },
      )
      .toBe(true)
    await expectProductLine(page, combinedProduct.quantity)
    await expectDocumentUnchanged(expect, page)
    expect(new URL(page.url()).pathname).toBe(cartPath)
    await expectProductCartCount('In 1 cart')
    await recordPlacement('sign-in', `hilos_user:${user.userId}`)
  })

  const beforeAddTraffic = readCartTraffic(delivery)
  const beforeAddAccount = beforeAddTraffic.epochs[2]
  const accountSocket = beforeAddAccount?.socket
  const transferAnswers = beforeAddAccount?.answers ?? []
  const transferAnswerCount = transferAnswers.length
  const beforeAddObservationCount = (await readObservations()).length
  expect(accountSocket).toBe(2)
  expect(beforeAddTraffic.subscriptions.map(({ socket }) => socket)).toEqual([
    1, 2,
  ])
  expect(transferAnswerCount).toBeGreaterThan(0)
  expect(transferAnswers.at(-1)?.cart?.lines).toMatchObject([combinedProduct])

  await test.step('add another unit on the leader and update the open follower cart', async () => {
    await addAccountUnit(addedProduct.quantity)
    await expect
      .poll(
        async () => {
          const { epochs, subscriptions } = readCartTraffic(delivery)
          const account = epochs[2]
          if (
            account?.socket !== accountSocket ||
            account.userId !== user.userId ||
            subscriptions.length !== 2
          )
            return false

          const postAddAnswers = account.answers.slice(transferAnswerCount)
          if (postAddAnswers.length === 0) return false

          return (await readObservations())
            .slice(beforeAddObservationCount)
            .some((sample) => {
              const answer = cartAnswerAt(postAddAnswers, sample)
              const cart = answer?.cart
              return (
                answer !== undefined &&
                answer.socket === accountSocket &&
                cart?.userId === user.userId &&
                cart.guestCartKey === null &&
                cart.lines.length === 1 &&
                cart.lines[0].productId === product.productId &&
                cart.lines[0].quantity === addedProduct.quantity &&
                showsCart(sample, cart)
              )
            })
        },
        {
          message:
            'The cross-master addition rendered on the existing account socket',
        },
      )
      .toBe(true)
    await expectProductLine(page, addedProduct.quantity)
    await expectDocumentUnchanged(expect, page)
    expect(new URL(page.url()).pathname).toBe(cartPath)
    await expectProductCartCount('In 1 cart')
    await recordPlacement('add', `hilos_user:${user.userId}`)
  })

  await test.step('sign out and see a new empty guest cart on the same page', async () => {
    const before = await sessionToken(page)
    await expectSessionRotation(expect, page, () => logout(expect, page))
    const after = await sessionToken(page)
    expect(
      after !== before && after !== guestToken,
      'Sign-out rotated onto a fresh guest cookie',
    ).toBe(true)
    await expect(page.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(line).toHaveCount(0)
    await expectDocumentUnchanged(expect, page)
    expect(new URL(page.url()).pathname).toBe(cartPath)
    await recordPlacement('sign-out', guestAgent)
  })

  const { epochs, subscriptions, wholes } = readCartTraffic(delivery)
  const observations = await readObservations()
  expect(
    topology.slice(1).some(({ socket, serving }) => socket !== serving),
  ).toBe(true)
  expect(subscriptions.map(({ socket }) => socket)).toEqual([1, 2, 3])
  expect(epochs.map(({ socket, userId }) => ({ socket, userId }))).toEqual([
    { socket: 1, userId: null },
    { socket: 1, userId: user.userId },
    { socket: 2, userId: user.userId },
    { socket: 2, userId: null },
    { socket: 3, userId: null },
  ])
  expect(epochs.flatMap(({ answers }) => answers)).toEqual(wholes)
  const [initial, signingIn, signedIn, signingOut, signedOut] = epochs
  const initialCart = initial.answers[0]?.cart
  expect(initialCart).toMatchObject({ userId: null, lines: [guestProduct] })
  const initialGuestCartKey = initialCart?.guestCartKey
  expect(initialGuestCartKey).toBeTruthy()

  for (const [position, epoch] of epochs.entries()) {
    const subscription = subscriptions[epoch.socket - 1]
    const fresh = epoch.socket !== epochs[position - 1]?.socket
    if (fresh) {
      expect(subscription.at).toBeGreaterThanOrEqual(epoch.at)
      expect(epoch.answers.length).toBeGreaterThan(0)
    } else {
      // Reassessment can finish on the retiring socket before it closes.
      expect(epoch.answers.length).toBeLessThanOrEqual(1)
    }
    if (fresh && epoch.userId === null) expect(epoch.answers).toHaveLength(1)

    for (const [index, answer] of epoch.answers.entries()) {
      expect(answer.at).toBeGreaterThan(subscription.at)
      expect(answer.cart?.userId).toBe(epoch.userId)
      if (epoch.userId !== null) {
        expect(answer.cart?.guestCartKey).toBeNull()
        const afterAdd = epoch === signedIn && index >= transferAnswerCount
        // The first whole can still show the account's original quantity.
        const pending =
          index === 0 &&
          answer.cart?.lines[0]?.quantity === accountProduct.quantity
        expect(answer.cart?.lines).toMatchObject([
          afterAdd ? addedProduct : pending ? accountProduct : combinedProduct,
        ])
      } else if (epoch !== initial) {
        expect(answer.cart?.guestCartKey).toBeTruthy()
        expect(answer.cart?.guestCartKey).not.toBe(initialGuestCartKey)
        expect(answer.cart?.lines).toEqual([])
      }
    }
  }

  const preAddAnswers = signedIn.answers.slice(0, transferAnswerCount)
  const postAddAnswers = signedIn.answers.slice(transferAnswerCount)
  expect(preAddAnswers).toEqual(transferAnswers)
  expect(postAddAnswers.length).toBeGreaterThan(0)
  expect(postAddAnswers.every(({ socket }) => socket === accountSocket)).toBe(
    true,
  )

  const signInStart = confirmationStart(observations, signingIn)
  const signOutStart = confirmationStart(observations, signingOut)
  const initialPaint = observations.slice(0, signInStart).find(isCartContent)
  expect(
    initialPaint,
    'The saved guest cart rendered before sign-in',
  ).toBeDefined()
  if (!initialPaint || !initialCart)
    throw new Error('Missing initial guest cart')
  expect(cartAnswerAt(initial.answers, initialPaint)?.cart).toEqual(initialCart)
  expect(showsCart(initialPaint, initialCart)).toBe(true)

  const transferObservations = observations.slice(
    signInStart,
    beforeAddObservationCount,
  )
  expectCartOwnerChange(transferObservations, preAddAnswers, initialPaint)
  const transferredPaint = transferObservations.at(-1)
  const transferred = preAddAnswers.at(-1)?.cart
  expect(transferred?.lines).toMatchObject([combinedProduct])
  if (!transferredPaint || !transferred)
    throw new Error('Missing transferred cart')
  expect(showsCart(transferredPaint, transferred)).toBe(true)

  const accountObservations = observations.slice(signInStart, signOutStart)
  expectCartOwnerChange(accountObservations, signedIn.answers, initialPaint)
  const accountPaint = accountObservations.at(-1)
  const added = postAddAnswers.at(-1)?.cart
  expect(added?.lines).toMatchObject([addedProduct])
  if (!accountPaint || !added) throw new Error('Missing added cart')
  expect(showsCart(accountPaint, added)).toBe(true)
  expectCartOwnerChange(
    observations.slice(signOutStart),
    signedOut.answers,
    accountPaint,
  )
})
