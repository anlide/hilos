import {
  createSignal,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { flushPromises, mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosProfileDataPage from './HilosProfileDataPage.vue'

it('draws one catalog heading and the page copy, then releases its group listener', async () => {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_profile_data').data.set('dataExport', {
    state: 'ready',
    requestedAt: 1000,
    finishedAt: 2000,
    expiresAt: 3000,
    sizeBytes: 456,
  })
  const listeners = new Set<(frame: ProjectSignal) => void>()
  const connection = {
    on: (_: string, listener: (frame: ProjectSignal) => void) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  } as unknown as HilosConnection
  const router = {
    pageIdentity: createSignal({
      label: 'Your data',
      lead: 'Download a copy of what your account holds.',
      children: [],
      breadcrumb: [
        { page: 'hilos_profile', label: 'Profile' },
        { page: 'hilos_profile_data', label: 'Your data' },
      ],
    }),
    currentRoute: createSignal({
      page: 'hilos_profile_data',
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile/data'),
    resolvePath: (page: string) =>
      page === 'hilos_profile' ? '/profile' : '/profile/data',
  } as unknown as HilosRouter
  const wrapper = mount(HilosProfileDataPage, {
    props: { context: { connection, scopes, actions: {} as ActionLifecycle } },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  await flushPromises()
  expect(wrapper.findAll('h1')).toHaveLength(1)
  expect(wrapper.get('h1').text()).toBe('Your data')
  expect(wrapper.find('h3').exists()).toBe(false)
  expect(wrapper.get('[data-id="data-export-ready"]').text()).toContain('456 B')
  expect(
    wrapper
      .get('[data-id="hilos-breadcrumb-hilos_profile"]')
      .attributes('href'),
  ).toBe('/profile')
  for (const listener of listeners)
    listener({
      type: 'hilos_data_export_state',
      data: { dataExport: null },
    } as unknown as ProjectSignal)
  await flushPromises()
  expect(wrapper.find('[data-id="data-export-prepare"]').exists()).toBe(true)
  wrapper.unmount()
  expect(listeners.size).toBe(0)
})
