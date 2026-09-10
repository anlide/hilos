import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosAboutPage from './HilosAboutPage.vue'

// The dialog teleports to <body>, so its assertions query the document rather
// than the wrapper — the same as HilosModal's own test.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('HilosAboutPage', () => {
  it('draws the project’s prose and the support block, with no dialog open', () => {
    const wrapper = mount(HilosAboutPage, {
      slots: { default: '<p>This project is a demonstration.</p>' },
    })

    expect(wrapper.text()).toContain('This project is a demonstration.')
    expect(wrapper.find('[data-id="hilos-about-support"]').exists()).toBe(true)
    // The heading is a real h2, never bold text standing in for one.
    expect(wrapper.find('[data-id="hilos-about-support"] h2').text()).toContain(
      'no ads and no data selling',
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('opens the support dialog on the block’s one button', async () => {
    const wrapper = mount(HilosAboutPage)

    await wrapper.find('[data-id="hilos-about-support-open"]').trigger('click')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-about-tier-beer"]'),
    ).not.toBeNull()
  })
})
