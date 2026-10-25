import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'
import { HilosCommunicationsPage } from '../src/admin/communications/HilosCommunicationsPage.js'
import {
  communicationsHarness,
  channelEl,
} from './hilosCommunicationsHarness.js'

describe('Communications section', () => {
  it('uses the section head without a tab row and links Configure to Configuration', () => {
    const h = communicationsHarness(HilosPages.COMMUNICATIONS)
    h.mount(<HilosCommunicationsPage context={h.context} />)
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(channelEl('hilos-page-head-title')?.textContent).toBe(
      'Communications',
    )
    expect(channelEl('hilos-page-tabs')).toBeNull()
    expect(
      channelEl('hilos-channel-configure-email')?.getAttribute('href'),
    ).toBe('/hilos/communications/email/configuration')
    expect(channelEl('hilos-viewport-table')).not.toBeNull()
    expect(h.sent).toEqual([])
  })
})
