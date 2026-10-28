import { TestBed } from '@angular/core/testing'
import {
  formatMoment,
  hilosNotifications,
  type HilosConnection,
  type HilosNotification,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationBell } from '../src/HilosNotificationBell.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

let timeZoneBinding: TimeZoneTestBinding | null = null

afterEach(() => {
  if (timeZoneBinding !== null) {
    timeZoneBinding.receive(null)
    timeZoneBinding.release()
    timeZoneBinding = null
  }
  hilosNotifications.clear()
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

function notification(
  id: number,
  createdAt?: number | null,
): HilosNotification {
  return {
    id,
    userId: 1,
    type: 'system',
    severity: 'info',
    title: `Notification ${id}`,
    body: null,
    data: null,
    readAt: null,
    ...(createdAt === undefined ? {} : { createdAt }),
  }
}

async function mountBell() {
  await TestBed.configureTestingModule({
    imports: [HilosNotificationBell],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosNotificationBell)
  fixture.componentRef.setInput('connection', {
    sendAction: vi.fn(),
  } as unknown as HilosConnection)
  fixture.detectChanges()

  return fixture
}

function openMenu(fixture: Awaited<ReturnType<typeof mountBell>>): HTMLElement {
  const root = fixture.nativeElement as HTMLElement
  root
    .querySelector<HTMLElement>('[data-id="hilos-notification-toggle"]')
    ?.click()
  fixture.detectChanges()

  return root
}

describe('HilosNotificationBell', () => {
  it('formats epoch zero and timestamps while leaving null and invalid moments empty', async () => {
    hilosNotifications.clear()
    const fixture = await mountBell()
    const instant = Date.parse('2026-06-01T12:00:00.000Z')
    hilosNotifications.onCreated(notification(1, instant))
    hilosNotifications.onCreated(notification(2, 0))
    hilosNotifications.onCreated(notification(3, null))
    hilosNotifications.onCreated(notification(4, Number.NaN))

    const root = openMenu(fixture)
    fixture.detectChanges()
    const time = (id: number) =>
      root.querySelector<HTMLElement>(
        `[data-id="hilos-notification-time-${id}"]`,
      )

    expect(time(1)?.textContent?.trim()).toBe(formatMoment(instant))
    expect(time(2)?.textContent?.trim()).toBe(formatMoment(0))
    expect(time(2)?.textContent?.trim()).not.toBe('')
    expect(time(3)?.textContent?.trim()).toBe('')
    expect(time(4)?.textContent?.trim()).toBe('')
  })

  it('reformats a timestamp in the live reader zone without closing the menu', async () => {
    timeZoneBinding = bindTestTimeZone('UTC')
    timeZoneBinding.receive('America/Los_Angeles')
    hilosNotifications.clear()
    const instant = Date.parse('2026-09-27T00:30:00.000Z')
    hilosNotifications.onCreated(notification(17, instant))
    const fixture = await mountBell()
    const root = openMenu(fixture)
    const menu = root.querySelector('[data-id="hilos-notification-menu"]')
    const time = root.querySelector<HTMLElement>(
      '[data-id="hilos-notification-time-17"]',
    )
    const before = time?.textContent?.trim()

    timeZoneBinding.receive('Asia/Tokyo')
    fixture.detectChanges()

    expect(time?.textContent?.trim()).toBe(formatMoment(instant))
    expect(time?.textContent?.trim()).not.toBe(before)
    expect(root.querySelector('[data-id="hilos-notification-menu"]')).toBe(menu)
    expect(
      root
        .querySelector('[data-id="hilos-notification-toggle"]')
        ?.getAttribute('aria-expanded'),
    ).toBe('true')
  })
})
