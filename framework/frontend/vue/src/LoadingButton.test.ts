import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref, type Ref } from 'vue'

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

  it('locks at once while loading, marked busy, without turning itself off', async () => {
    const wrapper = mount(LoadingButton, { props: { loading: true } })
    const button = wrapper.find('button')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-busy')).toBe('true')
    expect(button.attributes('aria-disabled')).toBe('true')
    await button.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })
})

describe('LoadingButton in flight', () => {
  const attached: VueWrapper[] = []

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    for (const wrapper of attached.splice(0)) {
      wrapper.unmount()
    }
    document.body.replaceChildren()
    vi.useRealTimers()
  })

  /**
   * What the button tells a sighted person and a screen reader right now.
   *
   * @param wrapper The mounted button.
   */
  function stateOf(wrapper: VueWrapper) {
    const button = wrapper.find('button')

    return {
      nativeDisabled: button.attributes('disabled') !== undefined,
      disabledLook: button.classes('disabled'),
      spinner: wrapper.find('[data-id="loading-button-spinner"]').exists(),
      labelHidden: wrapper.find('button > span').classes('invisible'),
      ariaBusy: button.attributes('aria-busy'),
      ariaDisabled: button.attributes('aria-disabled'),
    }
  }

  const resting = {
    nativeDisabled: false,
    disabledLook: false,
    spinner: false,
    labelHidden: false,
    ariaBusy: undefined,
    ariaDisabled: undefined,
  }
  const lockedLookingTheSame = {
    ...resting,
    ariaBusy: 'true',
    ariaDisabled: 'true',
  }
  const spinning = {
    ...lockedLookingTheSame,
    disabledLook: true,
    spinner: true,
    labelHidden: true,
  }

  /**
   * Advance the fake clock and let the view render what the timer decided.
   *
   * @param ms Milliseconds to advance.
   */
  async function wait(ms: number): Promise<void> {
    vi.advanceTimersByTime(ms)
    await nextTick()
  }

  it('keeps its look until the spinner comes at 300 ms, then looks disabled', async () => {
    const wrapper = mount(LoadingButton, { slots: { default: 'Save' } })

    await wrapper.setProps({ loading: true })
    expect(stateOf(wrapper)).toEqual(lockedLookingTheSame)
    await wait(299)
    expect(stateOf(wrapper)).toEqual(lockedLookingTheSame)
    await wait(1)
    expect(stateOf(wrapper)).toEqual(spinning)
    expect(wrapper.text()).toContain('Save')

    await wrapper.setProps({ loading: false })
    expect(stateOf(wrapper)).toEqual(resting)
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('never shows the spinner when the reply comes before 300 ms', async () => {
    const wrapper = mount(LoadingButton)

    await wrapper.setProps({ loading: true })
    await wait(299)
    await wrapper.setProps({ loading: false })
    expect(stateOf(wrapper)).toEqual(resting)
    await wait(1000)
    expect(stateOf(wrapper)).toEqual(resting)
  })

  it('gives a new request a full delay of its own', async () => {
    const wrapper = mount(LoadingButton)

    await wrapper.setProps({ loading: true })
    await wait(200)
    await wrapper.setProps({ loading: false })
    await wrapper.setProps({ loading: true })
    await wait(200)
    expect(stateOf(wrapper)).toEqual(lockedLookingTheSame)
    await wait(100)
    expect(stateOf(wrapper)).toEqual(spinning)
  })

  it('reads loadingDelay when each request starts', async () => {
    const wrapper = mount(LoadingButton, { props: { loadingDelay: 500 } })

    await wrapper.setProps({ loading: true })
    await wait(499)
    expect(stateOf(wrapper)).toEqual(lockedLookingTheSame)
    await wait(1)
    expect(stateOf(wrapper)).toEqual(spinning)

    await wrapper.setProps({ loading: false, loadingDelay: 100 })
    await wrapper.setProps({ loading: true })
    await wait(99)
    expect(stateOf(wrapper)).toEqual(lockedLookingTheSame)
    await wait(1)
    expect(stateOf(wrapper)).toEqual(spinning)
  })

  it('cancels the pending spinner when it unmounts', async () => {
    const wrapper = mount(LoadingButton)

    await wrapper.setProps({ loading: true })
    expect(vi.getTimerCount()).toBe(1)
    wrapper.unmount()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('cancels a blocked press before it submits the form or reaches an ancestor', async () => {
    const form = document.createElement('form')
    document.body.append(form)
    const submitted = vi.fn((event: Event) => event.preventDefault())
    const ancestorClicked = vi.fn()
    form.addEventListener('submit', submitted)
    form.addEventListener('click', ancestorClicked)
    const wrapper = mount(LoadingButton, {
      props: { type: 'submit', loading: true },
      attachTo: form,
    })
    attached.push(wrapper)

    /** Press the button the way a mouse, Enter, or Space activates it. */
    function press(): MouseEvent {
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      wrapper.find('button').element.dispatchEvent(event)

      return event
    }

    expect(press().defaultPrevented).toBe(true)
    await wait(300)
    expect(press().defaultPrevented).toBe(true)
    expect(wrapper.emitted('click')).toBeUndefined()
    expect(ancestorClicked).not.toHaveBeenCalled()
    expect(submitted).not.toHaveBeenCalled()

    await wrapper.setProps({ loading: false })
    expect(press().defaultPrevented).toBe(false)
    expect(wrapper.emitted('click')).toHaveLength(1)
    expect(ancestorClicked).toHaveBeenCalledTimes(1)
    expect(submitted).toHaveBeenCalledTimes(1)
  })

  it('keeps the focus on the button through its own request', async () => {
    const wrapper = mount(LoadingButton, { attachTo: document.body })
    attached.push(wrapper)
    const button = wrapper.find('button').element
    button.focus()

    await wrapper.setProps({ loading: true })
    expect(document.activeElement).toBe(button)
    await wait(300)
    expect(document.activeElement).toBe(button)
    await wrapper.setProps({ loading: false })
    expect(document.activeElement).toBe(button)
  })

  it('turns off at once for a reason that comes while it loads', async () => {
    const wrapper = mount(LoadingButton, { props: { loading: true } })

    await wrapper.setProps({ disabled: true })
    expect(stateOf(wrapper)).toEqual({
      ...lockedLookingTheSame,
      nativeDisabled: true,
    })
    await wrapper.setProps({ loading: false })
    expect(stateOf(wrapper)).toEqual({
      ...resting,
      nativeDisabled: true,
      ariaDisabled: 'true',
    })

    const viewMode = ref(false)
    const inPage = mountInPage(viewMode, {}, { loading: true })
    viewMode.value = true
    await nextTick()
    expect(stateOf(inPage)).toEqual({
      ...lockedLookingTheSame,
      nativeDisabled: true,
    })
    expect(inPage.find('button').attributes('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
  })

  it("keeps the caller's attributes, but not over its own busy lock", async () => {
    const wrapper = mount(LoadingButton, {
      attrs: {
        class: 'btn-primary',
        'data-id': 'profile-save',
        'aria-describedby': 'own-reason',
        'aria-busy': 'false',
        'aria-disabled': 'false',
      },
      props: { loading: true },
    })
    const button = wrapper.find('button')

    expect(button.attributes('aria-busy')).toBe('true')
    expect(button.attributes('aria-disabled')).toBe('true')
    await button.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
    await wait(300)
    expect(button.classes()).toEqual(
      expect.arrayContaining([
        'btn',
        'position-relative',
        'btn-primary',
        'disabled',
      ]),
    )
    expect(button.attributes('data-id')).toBe('profile-save')
    expect(button.attributes('aria-describedby')).toBe('own-reason')
    expect(button.attributes('aria-disabled')).toBe('true')

    await wrapper.setProps({ loading: false })
    expect(button.classes()).not.toContain('disabled')
    expect(button.attributes('aria-busy')).toBe('false')
    expect(button.attributes('aria-disabled')).toBe('false')
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
    expect(loading.find('button').attributes('disabled')).toBeUndefined()
    expect(loading.find('button').attributes('aria-disabled')).toBe('true')
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
