// Vue's reactive connection prop must move the shell's subscriptions together.
import { expect, it } from 'vitest'
import {
  HILOS_CONNECTION_STATUS_TEXT_ID,
  PROTECTED_MODE_INACTIVE,
  RECONNECT_DRAGGING_COPY,
  RT_STALENESS_FRESH,
  rtStalenessLabel,
} from '@hilos/core/base'
import { layoutChromeFeed } from '../../test-support/layoutChromeFixtures.js'
import { FROZEN } from '../../test-support/maintenanceFixtures.js'
import { mountLayoutConnection } from '../test/layoutConnectionMount.js'

it('moves Vue shell state to the replacement connection and releases both sources', async () => {
  const previous = layoutChromeFeed()
  const current = layoutChromeFeed()
  const view = await mountLayoutConnection({ connection: previous.connection })
  const maintenance = () => view.root.querySelector('[data-id="maintenance"]')
  const appRoot = () => view.root.querySelector('[data-id="app-root"]')
  const bootState = () =>
    view.root
      .querySelector('[data-id="hilos-boot-state"]')
      ?.getAttribute('data-state')
  const statusText = () =>
    view.root.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`)?.textContent

  try {
    await view.update({ connection: current.connection })
    expect(previous.listenerCounts().every((count) => count === 0)).toBe(true)

    await view.flush(() => previous.protectedMode(FROZEN))
    expect(maintenance()).toBeNull()
    await view.flush(() => current.protectedMode(FROZEN))
    expect(maintenance()).not.toBeNull()
    await view.flush(() => previous.protectedMode(PROTECTED_MODE_INACTIVE))
    expect(maintenance()).not.toBeNull()
    await view.flush(() => current.protectedMode(PROTECTED_MODE_INACTIVE))
    expect(maintenance()).toBeNull()

    await view.flush(() => previous.injectFirstFrameHold(true))
    expect(bootState()).toBe('ready')
    expect(appRoot()).not.toBeNull()
    expect(maintenance()).toBeNull()

    await view.flush(() => current.injectFirstFrameHold(true))
    expect(bootState()).toBe('held')
    expect(appRoot()).toBeNull()
    expect(maintenance()).toBeNull()
    await view.flush(() => previous.injectFirstFrameHold(false))
    expect(bootState()).toBe('held')
    expect(appRoot()).toBeNull()
    await view.flush(() => current.injectFirstFrameHold(false))
    expect(bootState()).toBe('ready')
    expect(appRoot()).not.toBeNull()

    const stale = { stale: true, since: undefined }
    await view.flush(() => previous.rtStaleness(stale))
    expect(statusText()?.trim()).toBe('connected')
    await view.flush(() => current.rtStaleness(stale))
    expect(statusText()).toContain(rtStalenessLabel(stale))
    await view.flush(() => previous.rtStaleness(RT_STALENESS_FRESH))
    expect(statusText()).toContain(rtStalenessLabel(stale))

    await view.flush(() => {
      previous.state('reconnecting')
      previous.reconnectDragging(true)
    })
    expect(statusText()).toContain(rtStalenessLabel(stale))
    await view.flush(() => {
      current.state('reconnecting')
      current.reconnectDragging(true)
    })
    expect(statusText()).toContain(RECONNECT_DRAGGING_COPY.dragging)
    await view.flush(() => {
      previous.state('connected')
      previous.reconnectDragging(false)
    })
    expect(statusText()).toContain(RECONNECT_DRAGGING_COPY.dragging)
  } finally {
    await view.unmount()
  }
  expect(current.listenerCounts().every((count) => count === 0)).toBe(true)
})
