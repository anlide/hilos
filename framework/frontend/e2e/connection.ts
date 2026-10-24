import type { ElementHandle, Expect, Locator, Page } from '@playwright/test'
import { CONNECTION_INTERACTION_COPY } from '../core/src/base/index.js'
import {
  createSocketFrameReader,
  watchSocketFrames,
  type SocketFrame,
} from './socket.js'

// What a client names on connecting rides the socket address, not a frame: the
// server reads it on the 101 and answers with its welcome. A spec about it needs
// two facts of every socket — the address the browser really opened it on, and
// that the server welcomed that very socket. The frame watchers keep neither the
// address nor which socket a welcome came on, so this log keeps both.

/** One socket the page opened, kept in the order the page opened them. */
export interface OpenedSocket {
  /** The address the browser opened the socket on, query included. */
  readonly url: URL
  /** Whether the server's handshake welcome has arrived on this socket. */
  readonly welcomed: boolean
}

/**
 * Predicate indicating whether a frame is a well-formed handshake welcome.
 *
 * @param frame One inner frame read through the core decoder.
 */
function isWelcome(frame: SocketFrame): boolean {
  const wire = frame.wire
  return (
    frame.failure === undefined &&
    wire !== null &&
    typeof wire === 'object' &&
    'type' in wire &&
    wire.type === 'handshake'
  )
}

/**
 * Keep every socket the page opens, with its address and whether the server has
 * welcomed it. Arm before navigation: a socket opened earlier is not seen.
 *
 * @param page The page whose sockets are kept, across reconnects and reloads.
 * @returns The sockets opened so far; the log grows as the page opens more.
 */
export function watchOpenedSockets(page: Page): readonly OpenedSocket[] {
  const sockets: { url: URL; welcomed: boolean }[] = []
  page.on('websocket', (socket) => {
    const opened = { url: new URL(socket.url()), welcomed: false }
    sockets.push(opened)
    const read = createSocketFrameReader(sockets.length)
    socket.on('framereceived', ({ payload }) => {
      if (read(payload).some(isWelcome)) {
        opened.welcomed = true
      }
    })
  })

  return sockets
}

/**
 * Wait until the page has opened exactly `count` sockets and the server has
 * welcomed every one of them. A socket more than the spec expects keeps the
 * wait from passing, so an unplanned reconnect is reported rather than missed.
 *
 * @param expect The test runner's assertion function.
 * @param sockets The log {@link watchOpenedSockets} keeps.
 * @param count How many sockets the page has opened by now.
 */
export async function expectWelcomedSockets(
  expect: Expect,
  sockets: readonly OpenedSocket[],
  count: number,
): Promise<void> {
  await expect
    .poll(() => sockets.map(({ welcomed }) => welcomed))
    .toEqual(Array.from({ length: count }, () => true))
}

/**
 * Run an action that rotates this session and wait for a validated welcome on
 * its new socket. Identity on the old socket and an old connected label cannot
 * complete this wait. Observe before the action, including a fast rotation.
 *
 * @param expect The spec's assertion function.
 * @param page The page whose live session will rotate.
 * @param action The triggering gesture and its identity/action settlement.
 */
export async function expectSessionRotation(
  expect: Expect,
  page: Page,
  action: () => Promise<void>,
): Promise<void> {
  let welcomedSocket = 0
  const frames = watchSocketFrames(page, (frame) => {
    if (isWelcome(frame)) welcomedSocket = frame.socket
  })
  try {
    await action()
    await expect
      .poll(() => welcomedSocket > 0 && welcomedSocket === frames.sockets())
      .toBe(true)
  } finally {
    frames.stop()
  }
}

/** Scenario-owned controls; the helper never chooses a profile flow or draft. */
export interface DisconnectedInteractionControls {
  /** A non-native action link inside the main page interaction scope. */
  readonly actionLink: Locator
  /** Omit while checking the main page before opening or after closing a modal. */
  readonly modal?: {
    readonly field: Locator
    readonly submit: Locator
    readonly cancel: Locator
  }
}

/**
 * Check the common shell/modal lock while reconnect is held by the scenario.
 * The page and draft remain readable, and focus stays in the dialog. This check
 * sends no actions and performs no close gestures; the spec owns their order,
 * frame-count baseline, draft/node identity, release and dirty-confirm checks.
 *
 * @param expect The spec's Playwright assertions.
 * @param page The page whose real connection is currently held disconnected.
 * @param controls Stable data-id locators selected by the scenario.
 * @returns Completion after the common disconnected assertions pass.
 */
export async function expectDisconnectedInteraction(
  expect: Expect,
  page: Page,
  controls: DisconnectedInteractionControls,
): Promise<void> {
  const state = page.getByTestId('conn-state')
  await expect(state).toBeVisible()
  await expect(state).toHaveAttribute(
    'title',
    /^(connecting|reconnecting|disconnected)\b/,
  )
  const scope = page.getByTestId('page-interaction-scope')
  await expect(scope).toBeVisible()
  // Playwright's ARIA disabled matcher excludes fieldsets themselves. Read the
  // native flag; the scenario also checks inherited :disabled on real controls.
  await expect(scope).toHaveJSProperty('disabled', true)
  await expect(scope).not.toHaveAttribute('inert')
  await expect(scope).not.toHaveAttribute('aria-hidden', 'true')
  await expect(controls.actionLink).toBeVisible()
  await expect(controls.actionLink).toHaveAttribute('aria-disabled', 'true')
  await expect(controls.actionLink).toHaveAttribute('tabindex', '-1')
  await expect(controls.actionLink).toHaveClass(/\bdisabled\b/)

  if (!controls.modal) return
  const dialog = page.getByTestId('modal')
  const modalScope = dialog.getByTestId('modal-interaction-scope')
  await expect(dialog).toBeVisible()
  await expect(dialog).not.toHaveAttribute('inert')
  await expect(dialog).not.toHaveAttribute('aria-hidden', 'true')
  await expect(modalScope).toHaveJSProperty('disabled', true)
  for (const control of [
    controls.modal.field,
    controls.modal.submit,
    controls.modal.cancel,
    dialog.getByTestId('modal-close'),
  ]) {
    await expect(control).toBeVisible()
    await expect(control).toBeDisabled()
  }
  const description = dialog.getByTestId('modal-connection-description')
  await expect(description).toHaveText(CONNECTION_INTERACTION_COPY.unavailable)
  await expect
    .poll(() => dialog.ariaSnapshot())
    .toContain(CONNECTION_INTERACTION_COPY.unavailable)
  const descriptionId = await description.getAttribute('id')
  expect(descriptionId).toBeTruthy()
  expect(
    (await dialog.getAttribute('aria-describedby'))?.split(/\s+/),
  ).toContain(descriptionId)
  await expect(page.getByTestId('modal-confirm')).toBeHidden()
  await expect
    .poll(() =>
      dialog.evaluate((root) => root.contains(document.activeElement)),
    )
    .toBe(true)
}

/**
 * Check that the connection's lock has released without taking a user action.
 *
 * @param expect The spec's Playwright assertions.
 * @param page The page after the real server welcome.
 * @param controls Controls whose own validation allowed them before the drop.
 */
export async function expectConnectedInteraction(
  expect: Expect,
  page: Page,
  controls: DisconnectedInteractionControls,
): Promise<void> {
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('page-interaction-scope')).toHaveJSProperty(
    'disabled',
    false,
  )
  await expect(controls.actionLink).not.toHaveAttribute('aria-disabled', 'true')
  await expect(controls.actionLink).not.toHaveAttribute('tabindex', '-1')
  await expect(controls.actionLink).not.toHaveClass(/\bdisabled\b/)
  if (!controls.modal) return
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeVisible()
  await expect(dialog.getByTestId('modal-interaction-scope')).toHaveJSProperty(
    'disabled',
    false,
  )
  for (const control of [
    controls.modal.field,
    controls.modal.submit,
    controls.modal.cancel,
    dialog.getByTestId('modal-close'),
  ]) {
    await expect(control).toBeEnabled()
  }
  await expect
    .poll(() => dialog.ariaSnapshot())
    .not.toContain(CONNECTION_INTERACTION_COPY.unavailable)
  await expect(page.getByTestId('modal-confirm')).toBeHidden()
}

/**
 * Click through Chromium's pointer path even when a control is unavailable.
 * Locator.click waits for enabled, which would hide the forbidden gesture.
 *
 * @param page The page receiving the real mouse gesture.
 * @param control A visible control selected by its stable data-id.
 */
export async function clickUnavailableControl(
  page: Page,
  control: Locator,
): Promise<void> {
  const box = await control.boundingBox()
  if (!box) throw new Error('The unavailable control has no visible box.')
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
}

/**
 * Bookmark actual nodes and their reading positions, rather than matching ids
 * again after a possible remount. Draft values stay scenario-owned assertions.
 *
 * @param expect The spec's Playwright assertions.
 * @param page The page whose document scroll position must survive.
 * @param targets Stable locators for the page content, dialog and draft nodes.
 * @returns Identity/scroll checks and cleanup of every retained DOM handle.
 */
export async function watchInteractionNodes(
  expect: Expect,
  page: Page,
  targets: readonly Locator[],
) {
  const nodes: {
    target: Locator
    node: ElementHandle<HTMLElement | SVGElement>
  }[] = []
  try {
    for (const target of targets) {
      const node = await target.elementHandle()
      if (!node) throw new Error('The interaction node is not mounted.')
      nodes.push({ target, node })
    }
    const readScroll = () =>
      page.evaluate(() =>
        Array.from(document.querySelectorAll('*'))
          .filter((node) => node.scrollTop !== 0 || node.scrollLeft !== 0)
          .map((node) => [node.scrollTop, node.scrollLeft]),
      )
    const scroll = await readScroll()
    return {
      async unchanged(): Promise<void> {
        for (const { target, node } of nodes) {
          expect(
            await target.evaluate(
              (current, original) =>
                current === original && original.isConnected,
              node,
            ),
          ).toBe(true)
        }
        expect(await readScroll()).toEqual(scroll)
      },
      async dispose(): Promise<void> {
        await Promise.all(nodes.map(({ node }) => node.dispose()))
      },
    }
  } catch (error) {
    await Promise.all(nodes.map(({ node }) => node.dispose()))
    throw error
  }
}
