// Vue projection and lifecycle for the shared Hideable scenarios.
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'

import {
  registerHideableCases,
  type HideableInput,
  type HideableView,
} from '../../test-support/hideableCases.js'
import HilosHideable from './HilosHideable.vue'

/** Mount the real component, translating projection into a scoped slot. */
async function mountHideable(input: HideableInput): Promise<HideableView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const renderValue = input.renderValue
    const wrapper = mount(HilosHideable, {
      attachTo: host,
      props: { value: input.value },
      slots: renderValue
        ? {
            default: ({ value }: { value: unknown }) =>
              h('code', { 'data-id': 'shown' }, renderValue(value)),
          }
        : {},
    })
    return {
      root: host,
      async unmount() {
        try {
          wrapper.unmount()
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    host.remove()
    throw error
  }
}

registerHideableCases('Vue', { describe, it, expect }, mountHideable)
