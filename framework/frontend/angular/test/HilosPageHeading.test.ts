import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
} from '@hilos/core'
import { expect, it } from 'vitest'
import { HilosPageHeading } from '../src/HilosPageHeading.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

it('waits for catalog identity and keeps an unmounted parent as text', () => {
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(undefined)
  const router = {
    pageIdentity,
    currentRoute: createSignal({
      page: 'hilos_profile_sign_in',
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile/sign-in'),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosPageHeading)
  fixture.componentRef.setInput('dataId', 'test-heading')
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  expect(root.querySelector('[data-id="test-heading-skeleton"]')).not.toBeNull()
  expect(root.querySelector('h1')).toBeNull()
  const identity: HilosPageIdentity = {
    label: 'Ways to sign in',
    lead: 'Every way into this account.',
    children: [],
    breadcrumb: [
      { page: 'hilos_profile', label: 'Profile' },
      { page: 'hilos_profile_sign_in', label: 'Ways to sign in' },
    ],
  }
  pageIdentity.set(identity)
  fixture.detectChanges()
  expect(root.querySelector('h1')?.textContent?.trim()).toBe('Ways to sign in')
  expect(
    root.querySelector('[aria-label="breadcrumb"]')?.textContent,
  ).toContain('Profile')
  expect(root.querySelector('a')).toBeNull()
  pageIdentity.set({
    ...identity,
    breadcrumb: [{ page: 'hilos_profile', label: 'Profile' }],
  })
  fixture.detectChanges()
  expect(root.querySelector('[aria-label="breadcrumb"]')).toBeNull()
})
