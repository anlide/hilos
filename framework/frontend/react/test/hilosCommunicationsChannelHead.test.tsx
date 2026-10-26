import { act } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages, pageIdentity } from '@hilos/core/base'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { useHilosCommunicationsChannelHead } from '../src/admin/communications/hilosCommunicationsChannelHead.js'
import {
  communicationsHarness,
  channelEl,
  channelSummary,
} from './hilosCommunicationsHarness.js'
import type { HilosCommunicationsContext } from '@hilos/core/admin/communications'

function Head({
  context,
  page,
}: {
  context: HilosCommunicationsContext
  page: string
}) {
  const { headProps } = useHilosCommunicationsChannelHead(context)
  return <HilosAdminPage page={page} {...headProps} />
}

describe('channel head binding', () => {
  it.each([
    HilosPages.COMMUNICATIONS_CHANNEL,
    HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
    HilosPages.COMMUNICATIONS_DELIVERIES,
    HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
  ])('binds the permitted first-answer name and block on %s', (page) => {
    const h = communicationsHarness(page)
    const view = h.mount(<Head context={h.context} page={page} />)
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('Email')
    expect(
      channelEl('hilos-breadcrumb')?.querySelector('[aria-current="page"]')
        ?.textContent,
    ).toBe('/Email')
    expect(
      channelEl('channel-head-icon')?.querySelector('.bi-envelope-paper'),
    ).not.toBeNull()
    expect(channelEl('channel-head-enabled')?.textContent).toBe('Enabled')
    expect(channelEl('channel-head-configured')?.textContent).toBe('Configured')
    expect(channelEl('channel-head-description')?.textContent).toBe(
      'Channel email · driver smtp',
    )
    expect(
      channelEl(`hilos-page-tab-${page}`)?.getAttribute('aria-current'),
    ).toBe('page')
    act(() =>
      h.putSummary({
        ...channelSummary,
        channel: 'sms',
        label: 'SMS',
        enabled: false,
        configured: false,
        missingFields: 2,
        driver: null,
      }),
    )
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('SMS')
    expect(channelEl('channel-head-description')?.textContent).toBe(
      'Channel sms · driver —',
    )
    expect(channelEl('channel-head-enabled')?.textContent).toBe('Disabled')
    expect(channelEl('channel-head-configured')?.getAttribute('title')).toBe(
      '2 field(s) not set',
    )
    view.unmount()
    expect(pageIdentity(h.scopes).get()?.pageHeadOwner?.label).toBe('Channel')
  })

  it('clears the old name on scope disposal, another page and a replacement context', () => {
    const first = communicationsHarness()
    const view = first.mount(
      <Head context={first.context} page={HilosPages.COMMUNICATIONS_CHANNEL} />,
    )
    act(() => {
      first.scopes.dropPage()
      first.scopes.openPage(HilosPages.SETTINGS)
      first.answerPage(HilosPages.SETTINGS)
    })
    expect(pageIdentity(first.scopes).get()?.pageHeadOwner?.label).toBe(
      'Channel',
    )
    expect(channelEl('channel-head-enabled')).toBeNull()
    const next = communicationsHarness(HilosPages.COMMUNICATIONS_DELIVERIES)
    next.putSummary({ ...channelSummary, label: 'Replacement' })
    view.rerender(
      next.wrap(
        <Head
          context={next.context}
          page={HilosPages.COMMUNICATIONS_DELIVERIES}
        />,
      ),
    )
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('Replacement')
    act(() => first.putSummary({ ...channelSummary, label: 'Stale' }))
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('Replacement')
  })
})
