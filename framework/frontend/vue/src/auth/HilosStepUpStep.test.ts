import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import {
  CODE_SEND_STATE_SENT,
  createSignal,
  type CodeSendProgress,
  type HilosStepUpMethod,
  type HilosStepUpStep as StepController,
} from '@hilos/core'

import HilosStepUpStep from './HilosStepUpStep.vue'

/** A code the transport took, with no resend moment of its own. */
const SENT: CodeSendProgress = {
  state: CODE_SEND_STATE_SENT,
  channel: 'sms',
  purpose: null,
  detail: null,
  ticket: 'ticket-1',
  reason: null,
  resendAt: null,
  expiresAt: null,
}

function controller(method: HilosStepUpMethod): StepController {
  return {
    opening: createSignal({
      required: true,
      purpose: 'change your name',
      method,
      destination: 'me@example.test',
    }),
    code: createSignal(''),
    password: createSignal(''),
    backupCode: createSignal(false),
    busy: createSignal(false),
    refusal: createSignal<string | null>(null),
    sendProgress: createSignal<CodeSendProgress | null>(null),
    resendAt: createSignal<number | null>(null),
    open: async () => 'ask',
    sendAgain: async () => {},
    confirm: async () => true,
  }
}

describe('HilosStepUpStep', () => {
  it('renders and writes the password proof', async () => {
    const step = controller('password')
    const wrapper = mount(HilosStepUpStep, { props: { controller: step } })

    await wrapper.get('[data-id="step-up-password"]').setValue('secret')
    expect(step.password.get()).toBe('secret')
    expect(wrapper.get('[data-id="step-up-text"]').text()).toContain(
      'change your name',
    )
  })

  it('switches between app and backup codes without clearing the code', async () => {
    const step = controller('second_factor')
    step.code.set('123456')
    const wrapper = mount(HilosStepUpStep, { props: { controller: step } })

    await wrapper.get('[data-id="step-up-backup-toggle"]').trigger('click')
    expect(step.backupCode.get()).toBe(true)
    expect(step.code.get()).toBe('123456')
    expect(wrapper.get('[data-id="step-up-backup-toggle"]').text()).toContain(
      'Use the app code',
    )
  })

  it.each([
    ['email_code', null, Date.now() - 60_000],
    ['sms_code', SENT, null],
  ] as const)(
    'draws the send block for %s and asks the controller to send again',
    async (method, progress, resendAt) => {
      const sendAgain = vi.fn(async () => {})
      const step: StepController = {
        ...controller(method),
        sendProgress: createSignal<CodeSendProgress | null>(progress),
        resendAt: createSignal<number | null>(resendAt),
        sendAgain,
      }
      const wrapper = mount(HilosStepUpStep, { props: { controller: step } })

      expect(wrapper.find('[data-id="step-up-send"]').exists()).toBe(true)
      await wrapper.get('[data-id="step-up-send-again"]').trigger('click')
      expect(sendAgain).toHaveBeenCalledOnce()
    },
  )
})
