import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  createSignal,
  type HilosStepUpStep as StepController,
} from '@hilos/core'

import HilosStepUpStep from './HilosStepUpStep.vue'

function controller(method: 'second_factor' | 'password'): StepController {
  return {
    opening: createSignal({
      required: true,
      purpose: 'change your name',
      method,
    }),
    code: createSignal(''),
    password: createSignal(''),
    backupCode: createSignal(false),
    busy: createSignal(false),
    refusal: createSignal<string | null>(null),
    open: async () => 'ask',
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
})
