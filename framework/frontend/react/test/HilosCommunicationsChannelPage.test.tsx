import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  USER_ENTITY_TYPE,
} from '@hilos/core/base'
import { HilosCommunicationsChannelPage } from '../src/admin/communications/HilosCommunicationsChannelPage.js'
import {
  communicationsHarness,
  channelEl,
  channelSummary,
} from './hilosCommunicationsHarness.js'

describe('channel Overview', () => {
  it('draws live aggregates, one entity head and real tab links without a field table', () => {
    const h = communicationsHarness()
    h.mount(<HilosCommunicationsChannelPage context={h.context} />)
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('Email')
    expect(
      channelEl(
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION}`,
      )?.getAttribute('href'),
    ).toBe('/hilos/communications/email/configuration')
    expect(
      channelEl(
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_DELIVERIES}`,
      )?.getAttribute('href'),
    ).toBe('/hilos/communications/email/deliveries')
    expect(channelEl('hilos-page-tabs')?.getAttribute('aria-label')).toBe(
      'Channel pages',
    )
    expect(channelEl('hilos-viewport-table')).toBeNull()
    expect(h.viewport).not.toHaveBeenCalled()
    expect(channelEl('channel-summary-fields')?.textContent).toContain('6')
    expect(channelEl('channel-summary-fields')?.textContent).toContain(
      '1 secret, set in env only',
    )
    expect(channelEl('channel-summary-overridden')?.textContent).toContain('2')
    act(() =>
      h.putSummary({
        ...channelSummary,
        label: 'Work mail',
        configured: false,
        missingFields: 3,
        fieldCount: 8,
        overriddenFieldCount: 4,
      }),
    )
    expect(channelEl('hilos-page-head-title')?.textContent).toBe('Work mail')
    expect(channelEl('channel-summary-configuration')?.textContent).toContain(
      '3 missing',
    )
    expect(channelEl('channel-summary-fields')?.textContent).toContain('8')
    expect(channelEl('channel-summary-overridden')?.textContent).toContain('4')
    expect(h.sent).toEqual([])
  })

  it('an admin on a node in the mode sends a test and settles before teardown', async () => {
    const h = communicationsHarness()
    h.scopes.session.data.set('adminViewMode', true)
    const user = { type: USER_ENTITY_TYPE, id: 1 }
    h.scopes.session.entities.upsert(user, { admin: true })
    h.scopes.session.data.set('currentUser', user)
    h.mount(<HilosCommunicationsChannelPage context={h.context} />)
    const test = channelEl<HTMLButtonElement>('hilos-channel-test')!
    expect(test.disabled).toBe(false)
    expect(test.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(test)
    expect(h.sent[0]).toMatchObject({
      action: 'communications_channel_test',
      payload: { channel: 'email' },
    })
    expect(test.getAttribute('aria-busy')).toBe('true')
    await h.settle()
    expect(test.getAttribute('aria-busy')).toBeNull()
    expect(test.disabled).toBe(false)
  })

  it('keeps the committed enablement on refusal and sends once during a pending toggle or test', async () => {
    const h = communicationsHarness()
    h.mount(<HilosCommunicationsChannelPage context={h.context} />)
    const toggle = channelEl<HTMLInputElement>('hilos-channel-enabled')!
    fireEvent.click(toggle)
    fireEvent.click(toggle)
    expect(h.sent).toHaveLength(1)
    expect(h.sent[0]).toMatchObject({
      action: 'communications_channel_set',
      payload: { channel: 'email', field: 'enabled', value: false },
    })
    expect(toggle.checked).toBe(true)
    await h.settle(false)
    expect(toggle.checked).toBe(true)
    fireEvent.click(channelEl('hilos-channel-test')!)
    fireEvent.click(channelEl('hilos-channel-test')!)
    expect(h.sent).toHaveLength(2)
    expect(h.sent[1]).toMatchObject({
      action: 'communications_channel_test',
      payload: { channel: 'email' },
    })
    await h.settle()
    act(() => h.putSummary({ ...channelSummary, enabled: false }))
    expect(toggle.checked).toBe(false)
  })

  it('shows hidden enablement as hidden and refuses a viewer test without dispatch', () => {
    const h = communicationsHarness(HilosPages.COMMUNICATIONS_CHANNEL, true)
    h.putSummary({ ...channelSummary, enabled: { _hidden: true } })
    h.mount(<HilosCommunicationsChannelPage context={h.context} />)
    expect(
      channelEl('channel-head-enabled')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(channelEl('channel-head-enabled')?.textContent).not.toContain(
      'Disabled',
    )
    expect(channelEl('hilos-channel-enabled')).toBeNull()
    const test = channelEl<HTMLButtonElement>('hilos-channel-test')!
    expect(test.disabled).toBe(true)
    expect(test.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    fireEvent.click(test)
    expect(h.sent).toEqual([])
  })
})
