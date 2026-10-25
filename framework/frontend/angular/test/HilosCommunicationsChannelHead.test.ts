import { TestBed } from '@angular/core/testing'
import type { HilosChannelSummary } from '@hilos/core/admin/communications'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosCommunicationsChannelHead } from '../src/admin/communications/HilosCommunicationsChannelHead.js'

const SUMMARY: HilosChannelSummary = {
  channel: 'email',
  label: 'Email',
  enabled: true,
  configured: false,
  driver: 'smtp',
  missingFields: 2,
  fieldCount: 6,
  secretFieldCount: 1,
  overriddenFieldCount: 2,
}

afterEach(() => TestBed.resetTestingModule())

function mount(part: 'visual' | 'badges' | 'description', summary = SUMMARY) {
  const fixture = TestBed.createComponent(HilosCommunicationsChannelHead)
  fixture.componentRef.setInput('summary', summary)
  fixture.componentRef.setInput('part', part)
  fixture.detectChanges()

  return fixture
}

describe('HilosCommunicationsChannelHead', () => {
  it('draws the Communications icon on a real element', () => {
    const fixture = mount('visual')
    const icon = fixture.nativeElement.querySelector(
      '[data-id="channel-head-icon"]',
    ) as HTMLElement | null

    expect(icon).not.toBeNull()
    expect(icon?.getAttribute('aria-hidden')).toBe('true')
    expect(icon?.querySelector('.bi-envelope-paper')).not.toBeNull()
  })

  it('draws enabled and configured states with their counts', () => {
    const fixture = mount('badges')
    const root = fixture.nativeElement as HTMLElement

    expect(
      root.querySelector('[data-id="channel-head-enabled"]')?.textContent,
    ).toContain('Enabled')
    expect(
      root.querySelector('[data-id="channel-head-configured"]')?.textContent,
    ).toContain('2 missing')
    expect(
      root
        .querySelector('[data-id="channel-head-configured"]')
        ?.getAttribute('title'),
    ).toBe('2 field(s) not set')
  })

  it('keeps a hidden enabled value distinct from Disabled', () => {
    const fixture = mount('badges', {
      ...SUMMARY,
      enabled: { _hidden: true },
    })
    const enabled = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="channel-head-enabled"]',
    )

    expect(enabled?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(enabled?.textContent).not.toContain('Disabled')
  })

  it('draws the channel key and driver in its description part', () => {
    const fixture = mount('description')
    const description = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="channel-head-description"]',
    )

    expect(description?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Channel email · driver smtp',
    )
  })
})
