// @vitest-environment node
import { expect, it } from 'vitest'

import * as base from '../../src/base/index.js'
import * as direct from '../../src/connection/interactionBlock.js'
import * as dom from '../../src/dom/interactionBlock.js'

// This isolated file evaluates the real entrypoints cold in Node. Loading their
// graph belongs to module collection, outside a behavior test's execution cap.
it('imports without a DOM and shares one implementation through base and its owners', () => {
  expect(typeof window).toBe('undefined')
  expect(typeof document).toBe('undefined')
  expect(base.bindInteractionBlock).toBe(dom.bindInteractionBlock)
  expect(base.createConnectionInteractionState).toBe(
    direct.createConnectionInteractionState,
  )
  expect(base.CONNECTION_INTERACTION_COPY).toBe(
    direct.CONNECTION_INTERACTION_COPY,
  )
  for (const entry of [base]) {
    expect(entry.HILOS_CONNECTION_STATUS_TEXT_ID).toBe(
      'hilos-connection-status-text',
    )
    expect(entry.HILOS_INTERACTION_BLOCKED_ATTR).toBe(
      'data-hilos-interaction-blocked',
    )
    expect(entry.HILOS_INTERACTION_REGION_ATTR).toBe(
      'data-hilos-interaction-region',
    )
  }
})
