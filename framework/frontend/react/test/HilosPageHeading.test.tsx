import { act, cleanup, render } from '@testing-library/react'
import { afterEach, expect, it } from 'vitest'
import {
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
} from '@hilos/core'
import { HilosPageHeading } from '../src/HilosPageHeading.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)
const identity: HilosPageIdentity = {
  label: 'Ways to sign in',
  lead: 'Every way into this account.',
  children: [],
  breadcrumb: [
    { page: 'hilos_profile', label: 'Profile' },
    { page: 'hilos_profile_sign_in', label: 'Ways to sign in' },
  ],
}
it('waits for the catalog and keeps an unmounted parent as text', () => {
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
  const { container } = render(
    <HilosRouterContext.Provider value={router}>
      <HilosPageHeading dataId="test-heading" />
    </HilosRouterContext.Provider>,
  )
  expect(
    container.querySelector('[data-id="test-heading-skeleton"]'),
  ).not.toBeNull()
  expect(container.querySelector('h1')).toBeNull()
  act(() => pageIdentity.set(identity))
  expect(container.querySelector('h1')?.textContent).toBe('Ways to sign in')
  expect(
    container.querySelector('[aria-label="breadcrumb"]')?.textContent,
  ).toContain('Profile')
  expect(container.querySelector('a')).toBeNull()
  act(() =>
    pageIdentity.set({
      ...identity,
      breadcrumb: [{ page: 'hilos_profile', label: 'Profile' }],
    }),
  )
  expect(container.querySelector('[aria-label="breadcrumb"]')).toBeNull()
})
