import type { Page } from '@playwright/test'

import { expect, test as base } from '../../test.js'
import { PAGE_CART } from '../../../../frontend/src/pages/keys.js'
import { cartSnapshotSchema } from '../../../../frontend/src/views/Cart/types/Cart.js'
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
const product = { productId: 16, name: 'Pine bark 5 L', quantity: 3 }

// Sign-in traffic and the session cookie must stay out of browser traces.
test.use({ trace: 'off' })

type CartState = 'loading' | 'line' | 'empty'

type CartPacket = {
  socket: number
  message: number
}

type CartObservation = CartPacket & {
  visible: CartState[]
  afterMessage: boolean
}

type CartDocument = Window & { cartObservations?: CartObservation[] }

/** Observe cart commits and take a snapshot after every native message. */
async function watchCartObservations(page: Page) {
  await page.addInitScript((socketPath) => {
    const observations: CartObservation[] = []
    Object.assign(window, { cartObservations: observations })
    let opened = 0
    let cursor: CartPacket = { socket: 0, message: 0 }
    const hooks = {
      line: 'shop-cart-line',
      empty: 'shop-cart-empty',
      loading: 'shop-cart-skeleton',
    }
    const states = Object.keys(hooks) as CartState[]
    const record = (packet: CartPacket, afterMessage: boolean) => {
      const visible = states.filter((state) =>
        Array.from(
          document.querySelectorAll(`[data-id="${hooks[state]}"]`),
        ).some((element) =>
          element.checkVisibility({
            opacityProperty: true,
            visibilityProperty: true,
          }),
        ),
      )
      if (
        afterMessage ||
        observations.at(-1)?.visible.join() !== visible.join()
      ) {
        observations.push({ ...packet, visible, afterMessage })
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

/** Check continuity from the retiring socket's confirmation to the new cart. */
function expectCartOwnerChange(
  observations: CartObservation[],
  confirmation: CartPacket,
  whole: CartPacket,
  previous: CartState,
  next: CartState,
) {
  const start = observations.findIndex(
    ({ afterMessage, socket, message }) =>
      afterMessage &&
      socket === confirmation.socket &&
      message === confirmation.message,
  )
  const boundary = `identity confirmation on socket ${confirmation.socket}, message ${confirmation.message}`
  expect(start, `Cart sampled after ${boundary}`).toBeGreaterThanOrEqual(0)
  const loaded = observations.findIndex(
    ({ socket, message, visible }, index) =>
      index > start &&
      socket === whole.socket &&
      message >= whole.message &&
      visible.includes(next),
  )
  expect(loaded, 'A fresh whole rendered on the new socket').toBeGreaterThan(
    start,
  )
  expect(
    observations
      .slice(start, loaded + 1)
      .filter(({ visible }) => visible.includes(previous)),
    `The previous cart is absent from ${boundary} through the new whole`,
  ).toEqual([])
}

/** Keep only the cart payload and confirmed identity from the physical frames. */
function readCartTraffic(delivery: DeliveryFrameLog) {
  const frames = delivery.packets.flatMap(({ frames, at }) =>
    frames.map((frame) => ({ ...frame, at })),
  )
  const identities = frames.flatMap(({ wire, socket, message }, index) => {
    const frame = deliveryObject(wire)
    if (frame.type !== SIGNAL_HANDSHAKE_RESPONSE) return []
    const payload = scopePayloadSchema.safeParse(frame.data)
    const user = payload.data?.entities?.currentUser
    const userId = user === null ? null : deliveryObject(user).id
    return [{ socket, message, index, userId }]
  })
  const wholes = frames.flatMap(({ wire, socket, message, at }, index) => {
    const frame = deliveryObject(wire)
    if (frame.type !== SIGNAL_TYPE_PAGE_RESPONSE) return []
    const response = pageResponseSchema.safeParse(frame.data)
    if (response.data?.page !== PAGE_CART) return []
    const cart = cartSnapshotSchema.safeParse(response.data.payload?.data?.cart)
    return [{ socket, message, index, at, cart: cart.data }]
  })
  const subscriptions = delivery.sent.flatMap(({ wire, socket, at }) => {
    const request = deliveryObject(wire)
    return request[FIELD_TYPE] === SIGNAL_TYPE_PAGE_SUBSCRIBE &&
      request[FIELD_PAGE] === PAGE_CART
      ? [{ socket, at }]
      : []
  })
  return { identities, subscriptions, wholes }
}

test('an open cart follows sign-in and sign-out across cluster nodes', async ({
  browser,
  cleanup,
}) => {
  const master = stand.follower
  const setup = await newClusterMasterPage(browser, stand, stand.leader)
  cleanup.defer(() => setup.context().close())
  const user = await createTestUser(expect, setup, {
    commandHost: clusterNodeHost(stand, stand.leader),
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
  await setQuantity(product.quantity)

  const delivery = watchDeliveryFrames(page)
  cleanup.defer(delivery.stop)
  const upgrades = await watchClusterMasterUpgrades(expect, page, stand, master)
  cleanup.defer(upgrades.stop)
  const readObservations = await watchCartObservations(page)
  const line = page.getByTestId('shop-cart-line')
  const expectGuestLine = async () => {
    await expect(line).toHaveCount(1)
    await expect(line.getByTestId('shop-cart-product-link')).toHaveText(
      product.name,
    )
    await expect(
      line.getByText(`Quantity: ${product.quantity}`, { exact: true }),
    ).toBeVisible()
  }

  const topology: {
    phase: string
    socket: string
    rtSource: string
    agent: string
    serving: string
  }[] = []
  const recordPlacement = async (phase: string, agent: string) => {
    await upgrades.assert()
    await expectClusterSingleAgents(expect, stand, [agent])
    const serving = await clusterPlacementNode(expect, stand, agent)
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
    await gotoPage(expect, page, cartPath, PAGE_READY)
    await expectGuestLine()
    await markDocument(page)
    await recordPlacement('guest', guestAgent)
  })

  const guestToken = await sessionToken(page)
  await test.step('sign in and get the account’s empty cart on the same page', async () => {
    await openSignIn(expect, page)
    await expectSessionRotation(expect, page, () =>
      login(expect, page, user.email),
    )
    expect(
      (await sessionToken(page)) !== guestToken,
      'Sign-in rotated the cookie',
    ).toBe(true)
    await expect(page.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(line).toHaveCount(0)
    await expectDocumentUnchanged(expect, page)
    expect(new URL(page.url()).pathname).toBe(cartPath)
    await recordPlacement('sign-in', `hilos_user:${user.userId}`)
  })

  await test.step('sign out and recover the saved guest cart on the same page', async () => {
    const before = await sessionToken(page)
    await expectSessionRotation(expect, page, () => logout(expect, page))
    const after = await sessionToken(page)
    expect(
      after !== before && after !== guestToken,
      'Sign-out rotated onto a fresh guest cookie',
    ).toBe(true)
    await expectGuestLine()
    await expectDocumentUnchanged(expect, page)
    expect(new URL(page.url()).pathname).toBe(cartPath)
    await recordPlacement('sign-out', guestAgent)
  })

  const traffic = readCartTraffic(delivery)
  const observations = await readObservations()
  await test.info().attach('cart-owner-lifecycle', {
    body: JSON.stringify({ topology, ...traffic, observations }, null, 2),
    contentType: 'application/json',
  })
  expect(
    topology.slice(1).some(({ socket, serving }) => socket !== serving),
  ).toBe(true)
  expect(traffic.subscriptions.map(({ socket }) => socket)).toEqual([1, 2, 3])
  const epochs = traffic.identities.map(
    ({ socket, userId, index }, position) => {
      const end = traffic.identities[position + 1]?.index ?? Infinity
      const answers = traffic.wholes.filter(
        (answer) =>
          answer.socket === socket &&
          index < answer.index &&
          answer.index < end,
      )
      return { socket, userId, answers }
    },
  )
  expect(epochs.map(({ socket, userId }) => ({ socket, userId }))).toEqual([
    { socket: 1, userId: null },
    { socket: 1, userId: user.userId },
    { socket: 2, userId: user.userId },
    { socket: 2, userId: null },
    { socket: 3, userId: null },
  ])
  expect(epochs.flatMap(({ answers }) => answers)).toEqual(traffic.wholes)
  for (const [position, epoch] of epochs.entries()) {
    // The retiring socket's reassessment can finish before rotation closes it.
    // On every new socket the browser makes one request and must get one whole.
    if (epoch.socket !== epochs[position - 1]?.socket)
      expect(epoch.answers).toHaveLength(1)
    else expect(epoch.answers.length).toBeLessThanOrEqual(1)
    for (const answer of epoch.answers) {
      expect(answer.at).toBeGreaterThan(
        traffic.subscriptions[epoch.socket - 1].at,
      )
      expect(answer.cart).toMatchObject({
        userId: epoch.userId,
        lines: epoch.userId === null ? [product] : [],
      })
    }
  }
  const [initial, signedIn, signedOut] = [epochs[0], epochs[2], epochs[4]].map(
    ({ answers }) => answers[0],
  )
  if (!initial.cart || !signedOut.cart) {
    throw new Error('Expected full guest cart snapshots')
  }
  const { guestCartKey: initialGuestCartKey, ...initialCart } = initial.cart
  const { guestCartKey: signedOutGuestCartKey, ...signedOutCart } =
    signedOut.cart

  // Session token rotation changes the guest key even when the cart is restored.
  expect(initialGuestCartKey).toBeTruthy()
  expect(signedOutGuestCartKey).toBeTruthy()
  expect(signedOutGuestCartKey).not.toBe(initialGuestCartKey)
  expect(signedIn.cart?.guestCartKey).toBeNull()
  expect(signedOutCart).toStrictEqual(initialCart)

  expectCartOwnerChange(
    observations,
    traffic.identities[1],
    signedIn,
    'line',
    'empty',
  )
  expectCartOwnerChange(
    observations,
    traffic.identities[3],
    signedOut,
    'empty',
    'line',
  )

  // A fast cold reply can land before the first DOM commit. Both rotations
  // expose the loading state, and their fresh answers control the next render.
  const changes = observations.filter(
    ({ visible }, index) =>
      visible.length > 0 &&
      visible.join() !== observations[index - 1]?.visible.join(),
  )
  const lifecycle =
    changes[0]?.visible[0] === 'loading' ? changes.slice(1) : changes
  expect(lifecycle.map(({ visible }) => visible)).toEqual([
    ['line'],
    ['loading'],
    ['empty'],
    ['loading'],
    ['line'],
  ])
  const loaded = lifecycle.filter(({ visible }) => !visible.includes('loading'))
  for (const [index, answer] of [initial, signedIn, signedOut].entries()) {
    const paint = loaded[index]
    expect(paint.socket).toBe(answer.socket)
    expect(paint.message).toBeGreaterThanOrEqual(answer.message)
  }
  for (const [loading, answer] of [
    [lifecycle[1], signedIn],
    [lifecycle[3], signedOut],
  ] as const) {
    expect(
      loading.socket < answer.socket ||
        (loading.socket === answer.socket && loading.message < answer.message),
      `Cart is loading before the whole on socket ${answer.socket}`,
    ).toBe(true)
  }
})
