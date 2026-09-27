import { type ActionLifecycle } from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import { agreement, legalContext } from '../../../core/test/legal/fixtures.js'
import HilosProfileAgreementsPage from './HilosProfileAgreementsPage.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

it('shows no acceptance honestly and opens the current text without a request', async () => {
  const dispatch = vi.fn()
  const world = legalContext({ dispatch } as unknown as ActionLifecycle)
  world.page.data.set('legalAgreements', {
    documents: [
      {
        ...agreement,
        held: null,
        accepted: [],
        acceptedAt: null,
        standing: 'none',
      },
    ],
  })
  const view = mount(HilosProfileAgreementsPage, {
    attachTo: document.body,
    props: { context: world.context },
    global: { stubs: { HilosPageHeading: true } },
  })
  await flushPromises()
  expect(view.get('[data-id="legal-agreement-state"]').text()).toContain(
    'No acceptance on record',
  )
  expect(view.find('[data-id="legal-agreement-changes-open"]').exists()).toBe(
    false,
  )
  await view.get('[data-id="legal-agreement-open"]').trigger('click')
  await flushPromises()
  const body = document.querySelector('[data-id="legal-revision-text-modal"]')
  expect(body?.textContent).toContain('First paragraph.')
  expect(document.activeElement).toBe(body?.closest('[role="dialog"]'))
  expect(dispatch).not.toHaveBeenCalled()
  expect(
    view.get('[data-id="profile-agreements-history-open"]').attributes('href'),
  ).toBe('/profile/agreements/history')
})

it('retains coverage after editorial changes and opens the supplied comparison', async () => {
  const world = legalContext({
    dispatch: vi.fn(),
  } as unknown as ActionLifecycle)
  const view = mount(HilosProfileAgreementsPage, {
    attachTo: document.body,
    props: { context: world.context },
    global: { stubs: { HilosPageHeading: true } },
  })
  await flushPromises()
  expect(view.get('[data-id="legal-agreement-notice"]').text()).toContain(
    'your acceptance still covers it',
  )
  await view.get('[data-id="legal-agreement-changes-open"]').trigger('click')
  await flushPromises()
  expect(
    document.querySelector('[data-id="legal-changes-modal"]')?.textContent,
  ).toContain('Old wording')
  view.unmount()
  expect(world.listenerCount()).toBe(0)
})
