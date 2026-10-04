import { type CodeSendProgress } from '@hilos/core'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosSendProgress from './HilosSendProgress.vue'

const base: CodeSendProgress = {
  state: 'sent',
  channel: 'email',
  purpose: 'change_password',
  detail: null,
  ticket: 'a1b2c3d4e5f60718',
  reason: null,
  resendAt: null,
  expiresAt: null,
}

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function mountLine(
  progress: CodeSendProgress | null = null,
  resendAt: number | null = null,
) {
  return mount(HilosSendProgress, {
    props: {
      progress,
      resendAt,
      to: 'person@example.test',
      busy: false,
      dataId: 'send',
    },
  })
}

describe('HilosSendProgress', () => {
  it('holds the line and repeat row before the first send without a live region', () => {
    const wrapper = mountLine()
    expect(
      wrapper.find('[data-id="send-idle"]').attributes('aria-hidden'),
    ).toBe('true')
    expect(wrapper.find('[data-id="send-line"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="send-again"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="send"]').attributes('aria-live'),
    ).toBeUndefined()
  })

  it.each([
    ['queued', 'Queued for sending'],
    ['sending', 'Sending to person@example.test'],
    ['sent', 'Sent to person@example.test'],
    ['failed', 'Could not send'],
    ['not_sent', 'Not really sent to person@example.test'],
    ['held', 'already used'],
  ])('draws %s with shared copy', (state, copy) => {
    const wrapper = mountLine({ ...base, state })
    expect(wrapper.find('[data-id="send-line"]').text()).toContain(copy)
  })

  it('names an earlier live code on the cooldown', () => {
    const wrapper = mountLine({ ...base, reason: 'code_rate_limited' })
    expect(wrapper.find('[data-id="send-line"]').text()).toContain(
      'that code still works',
    )
  })

  it('opens the full sentence behind details', async () => {
    const wrapper = mountLine({
      ...base,
      state: 'failed',
      detail: 'mailbox unavailable',
    })
    await wrapper.find('[data-id="send-details"]').trigger('click')
    expect(
      document.querySelector('[data-id="send-full"]')?.textContent,
    ).toContain('mailbox unavailable')
  })

  it('turns the countdown into an enabled button on its own clock', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(1_900_000_000_000)
    const wrapper = mountLine(base, Date.now() + 42_000)
    expect(wrapper.find('[data-id="send-again-in"]').text()).toBe(
      'Send again in 0:42',
    )
    await vi.advanceTimersByTimeAsync(42_000)
    await nextTick()
    expect(wrapper.find('[data-id="send-again-in"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="send-again"]').attributes('disabled'),
    ).toBeUndefined()
    await wrapper.find('[data-id="send-again"]').trigger('click')
    expect(wrapper.emitted('send-again')).toHaveLength(1)
  })

  it('locks repeat while the transport or action is busy', async () => {
    const wrapper = mountLine({ ...base, state: 'queued' })
    expect(
      wrapper.find('[data-id="send-again"]').attributes('disabled'),
    ).toBeDefined()
    await wrapper.setProps({ progress: { ...base, state: 'sent' }, busy: true })
    expect(
      wrapper.find('[data-id="send-again"]').attributes('disabled'),
    ).toBeDefined()
  })
})
