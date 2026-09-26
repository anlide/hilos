import {
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import HilosPageHeading from './HilosPageHeading.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

function setup(identity?: HilosPageIdentity) {
  const pageIdentity = createSignal(identity)
  const router = {
    pageIdentity,
    currentRoute: createSignal({
      page: 'hilos_profile_sign_in',
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile/sign-in'),
    resolvePath: (page: string) =>
      page === 'hilos_profile' ? undefined : '/profile/sign-in',
  } as unknown as HilosRouter
  return {
    pageIdentity,
    wrapper: mount(HilosPageHeading, {
      props: { dataId: 'test-heading' },
      global: { provide: { [hilosRouterKey as symbol]: router } },
    }),
  }
}
const identity: HilosPageIdentity = {
  label: 'Ways to sign in',
  lead: 'Every way into this account.',
  children: [],
  breadcrumb: [
    { page: 'hilos_profile', label: 'Profile' },
    { page: 'hilos_profile_sign_in', label: 'Ways to sign in' },
  ],
}
describe('HilosPageHeading', () => {
  it('waits for catalog identity without printing the raw page key', async () => {
    const { wrapper, pageIdentity } = setup()
    expect(wrapper.find('[data-id="test-heading-skeleton"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('hilos_profile')
    pageIdentity.set(identity)
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('Ways to sign in')
    expect(wrapper.get('p').text()).toBe(identity.lead)
    expect(wrapper.get('[aria-label="breadcrumb"]').text()).toContain('Profile')
    expect(
      wrapper.find('[data-id="hilos-breadcrumb-hilos_profile"]').exists(),
    ).toBe(false)
  })
  it('keeps the profile root free of breadcrumbs', () => {
    const { wrapper } = setup({
      ...identity,
      label: 'Profile',
      breadcrumb: [{ page: 'hilos_profile', label: 'Profile' }],
    })
    expect(wrapper.find('[aria-label="breadcrumb"]').exists()).toBe(false)
  })
})
