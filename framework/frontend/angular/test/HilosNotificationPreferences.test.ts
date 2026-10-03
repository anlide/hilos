// Covers the Angular notification preference rows' no-address hint (HIL-1166):
// plain text without a section, a link into the section the page names.
import { TestBed } from '@angular/core/testing'
import { hilosNotificationPreferences, type HilosConnection } from '@hilos/core'
import { afterEach, expect, it } from 'vitest'
import { HilosNotificationPreferences } from '../src/HilosNotificationPreferences.js'

// The section reads its store once, at construction, before any input is set:
// the shared store is the one it renders.
afterEach(() => hilosNotificationPreferences.clear())

function setup(addressTo?: string) {
  hilosNotificationPreferences.applySection({
    channels: [
      { channel: 'email', label: 'Email', allowed: true, hasAddress: false },
    ],
    mandatoryNote: false,
  })
  const fixture = TestBed.createComponent(HilosNotificationPreferences)
  fixture.componentRef.setInput('connection', {
    sendAction: () => true,
  } as unknown as HilosConnection)
  if (addressTo !== undefined) {
    fixture.componentRef.setInput('addressSection', {
      page: 'hilos_profile_sign_in',
      label: 'Ways to sign in',
    })
    fixture.componentRef.setInput('addressTo', addressTo)
  }
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  return (id: string) => root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

it('keeps the no-address hint plain text when the page names no section', () => {
  const node = setup()

  expect(
    node('hilos-notification-preference-hint-email')?.textContent?.trim(),
  ).toBe('Add an address in your profile to enable this channel.')
  expect(node('hilos-notification-preference-address-email')).toBeNull()
})

it('links the no-address hint to the section the page names', () => {
  const node = setup('/profile/sign-in')

  expect(
    node('hilos-notification-preference-hint-email')
      ?.textContent?.replace(/\s+/g, ' ')
      .trim(),
  ).toBe('Add an address in Ways to sign in to enable this channel.')
  const link = node('hilos-notification-preference-address-email')
  expect(link?.getAttribute('href')).toBe('/profile/sign-in')
  expect(link?.textContent).toBe('Ways to sign in')
})
