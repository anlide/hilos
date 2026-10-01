import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref, type Ref } from 'vue'

import LoadingButton from './LoadingButton.vue'
import {
  hilosAdminViewModeKey,
  hilosTakeoverViewOnlyKey,
} from './hilosAdminViewMode.js'

/**
 * Mount the button inside a page that says whether a viewer of the admin view
 * mode stands there, the way the admin page shell provides it.
 *
 * @param viewMode The provided view mode.
 * @param attrs The attributes the caller passes to the button.
 */
function mountInPage(
  viewMode: Ref<boolean>,
  attrs: Record<string, unknown> = {},
  props: Record<string, unknown> = {},
) {
  return mount(LoadingButton, {
    attrs,
    props,
    slots: { default: 'Save' },
    global: { provide: { [hilosAdminViewModeKey as symbol]: viewMode } },
  })
}

describe('LoadingButton', () => {
  it('renders its slot content', () => {
    const wrapper = mount(LoadingButton, { slots: { default: 'Save' } })
    expect(wrapper.text()).toContain('Save')
  })

  it('emits click when enabled', async () => {
    const wrapper = mount(LoadingButton)
    expect(wrapper.find('button').attributes('aria-busy')).toBeUndefined()
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('disables, marks itself busy, and swallows clicks while loading', async () => {
    const wrapper = mount(LoadingButton, { props: { loading: true } })
    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('button').attributes('aria-busy')).toBe('true')
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('shows the spinner only after the delay', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mount(LoadingButton, { props: { loadingDelay: 300 } })
      await wrapper.setProps({ loading: true })
      expect(wrapper.find('[data-id="loading-button-spinner"]').exists()).toBe(
        false,
      )
      vi.advanceTimersByTime(300)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="loading-button-spinner"]').exists()).toBe(
        true,
      )
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('LoadingButton in the admin view mode', () => {
  it('stands disabled, swallows clicks, and points at the strip', async () => {
    const wrapper = mountInPage(ref(true))
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    expect(wrapper.text()).toBe('Save')
    await button.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it("keeps the caller's own description beside the strip's", () => {
    const wrapper = mountInPage(ref(true), {
      'aria-describedby': 'own-reason',
      class: 'btn-primary',
      'data-id': 'person-block-open',
    })
    const button = wrapper.find('button')

    expect(button.attributes('aria-describedby')).toBe(
      'own-reason hilos-view-mode-strip-text',
    )
    expect(button.classes()).toEqual(
      expect.arrayContaining(['btn', 'position-relative', 'btn-primary']),
    )
    expect(button.attributes('data-id')).toBe('person-block-open')
  })

  it('is untouched outside the mode and outside an admin page', async () => {
    for (const wrapper of [
      mountInPage(ref(false), { 'aria-describedby': 'own-reason' }),
      mount(LoadingButton, { attrs: { 'aria-describedby': 'own-reason' } }),
    ]) {
      const button = wrapper.find('button')
      expect(button.attributes('disabled')).toBeUndefined()
      expect(button.attributes('aria-describedby')).toBe('own-reason')
      await button.trigger('click')
      expect(wrapper.emitted('click')).toHaveLength(1)
    }
    expect(
      mountInPage(ref(false)).find('button').attributes('aria-describedby'),
    ).toBeUndefined()
  })

  it('comes alive the moment the viewer is given the rights', async () => {
    const viewMode = ref(true)
    const wrapper = mountInPage(viewMode)

    viewMode.value = false
    await wrapper.vm.$nextTick()

    const button = wrapper.find('button')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-describedby')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('stays live when marked as opening a window in the view mode', async () => {
    const wrapper = mountInPage(ref(true), {}, { opensWindow: true })
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-describedby')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)

    const withOwnDesc = mountInPage(
      ref(true),
      { 'aria-describedby': 'own-reason' },
      { opensWindow: true },
    )
    const buttonWithOwn = withOwnDesc.find('button')
    expect(buttonWithOwn.attributes('disabled')).toBeUndefined()
    expect(buttonWithOwn.attributes('aria-describedby')).toBe('own-reason')
  })

  it('honors disabled and loading on an opensWindow button in the view mode', async () => {
    const disabled = mountInPage(
      ref(true),
      {},
      { opensWindow: true, disabled: true },
    )
    expect(disabled.find('button').attributes('disabled')).toBeDefined()
    expect(
      disabled.find('button').attributes('aria-describedby'),
    ).toBeUndefined()
    await disabled.find('button').trigger('click')
    expect(disabled.emitted('click')).toBeUndefined()

    const loading = mountInPage(
      ref(true),
      {},
      { opensWindow: true, loading: true },
    )
    expect(loading.find('button').attributes('disabled')).toBeDefined()
    expect(
      loading.find('button').attributes('aria-describedby'),
    ).toBeUndefined()
    await loading.find('button').trigger('click')
    expect(loading.emitted('click')).toBeUndefined()
  })

  it('leaves an opensWindow button untouched outside the view mode', async () => {
    const wrapper = mountInPage(ref(false), {}, { opensWindow: true })
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-describedby')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })
})

describe('LoadingButton in a takeover that only looks (HIL-1170)', () => {
  /**
   * Mount the button inside the page's area of a takeover, the way the shell's
   * HilosTakeoverScope provides it, and inside an admin page as well when asked.
   *
   * @param viewOnly The provided takeover flag.
   * @param viewMode The admin page's view mode, when the button stands on one.
   * @param props The props the caller passes to the button.
   */
  function mountInTakeover(
    viewOnly: Ref<boolean>,
    viewMode?: Ref<boolean>,
    props: Record<string, unknown> = {},
  ) {
    return mount(LoadingButton, {
      props,
      slots: { default: 'Send' },
      global: {
        provide: {
          [hilosTakeoverViewOnlyKey as symbol]: viewOnly,
          ...(viewMode === undefined
            ? {}
            : { [hilosAdminViewModeKey as symbol]: viewMode }),
        },
      },
    })
  }

  it('stands disabled, swallows clicks, and points at the impersonation strip', async () => {
    const wrapper = mountInTakeover(ref(true))
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-describedby')).toBe(
      'hilos-impersonation-strip-text',
    )
    expect(wrapper.text()).toBe('Send')
    await button.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('names both strips when the page is also seen in the admin view mode', () => {
    const button = mountInTakeover(ref(true), ref(true)).find('button')

    expect(button.attributes('aria-describedby')).toBe(
      'hilos-view-mode-strip-text hilos-impersonation-strip-text',
    )
  })

  it('is live while the takeover may act, and comes alive when the policy moves', async () => {
    const viewOnly = ref(true)
    const wrapper = mountInTakeover(viewOnly)

    viewOnly.value = false
    await wrapper.vm.$nextTick()

    const button = wrapper.find('button')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-describedby')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('stays live when it only opens a window', async () => {
    const wrapper = mountInTakeover(ref(true), undefined, {
      opensWindow: true,
    })
    const button = wrapper.find('button')

    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })
})
