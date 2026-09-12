import { ActionError } from '@hilos/core'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { ref, type Ref } from 'vue'

import HilosActionError from './HilosActionError.vue'
import type { TrackedAction } from './useTrackedAction.js'

// The detail modal teleports to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/** The state of a tracked action, with the two fields a test drives by hand. */
interface FakeAction extends TrackedAction {
  readonly error: Ref<string | null>
  readonly failure: Ref<ActionError | null>
}

/**
 * A tracked action holding one outcome: what the driver would have left behind.
 *
 * @param error The failure message, or null for an action that has not failed.
 * @param failure The failure itself, or null when what was thrown was not one.
 */
function fakeAction(
  error: string | null,
  failure: ActionError | null = null,
): FakeAction {
  return {
    loading: ref(false),
    busy: ref(false),
    error: ref(error),
    failure: ref(failure),
    run: async () => false,
    clearError: () => {},
  }
}

describe('HilosActionError', () => {
  it('draws the slot and no plate while nothing has failed', () => {
    // The regression this file exists to hold: the message is a ref sitting on
    // a plain object, and a template expression does not unwrap it — so the
    // plate was drawn, red and empty, before any action had failed.
    const wrapper = mount(HilosActionError, {
      props: { action: fakeAction(null) },
    })
    const slot = wrapper.find('[data-id="hilos-action-error-slot"]')
    expect(slot.exists()).toBe(true)
    expect(slot.attributes('role')).toBe('alert')
    expect(slot.attributes('aria-live')).toBe('assertive')
    expect(wrapper.find('[data-id="hilos-action-error"]').exists()).toBe(false)
  })

  it('holds the room with an invisible twin while nothing has failed', () => {
    const wrapper = mount(HilosActionError, {
      props: { action: fakeAction(null) },
    })
    const twin = wrapper.find(
      '[data-id="hilos-action-error-slot"] [data-id="hilos-action-error-idle"]',
    )
    expect(twin.exists()).toBe(true)
    // Hidden from the eye and from the reader both: the room is all it is for.
    expect(twin.classes()).toContain('invisible')
    expect(twin.attributes('aria-hidden')).toBe('true')
  })

  it('draws the refusal on one line, and says it only once', () => {
    const wrapper = mount(HilosActionError, {
      props: { action: fakeAction('Value must be an integer of 0 or more') },
    })
    const plate = wrapper.find('[data-id="hilos-action-error"]')
    expect(plate.exists()).toBe(true)
    expect(plate.text()).toContain('Value must be an integer of 0 or more')
    // The slot is the live region; a role here too and the reader says it twice.
    expect(plate.attributes('role')).toBeUndefined()
    expect(plate.find('span.flex-grow-1').classes()).toContain('text-truncate')
  })

  it('keeps the room and drops the voice when suppressed', () => {
    const wrapper = mount(HilosActionError, {
      props: { action: fakeAction('Not applied'), suppressed: true },
    })
    expect(wrapper.find('[data-id="hilos-action-error-slot"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-action-error-idle"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-action-error"]').exists()).toBe(false)
  })

  it('offers the details button on every refusal, named for a reader', () => {
    // Nothing was held back here — no type, no original text — and the button
    // is still the way to the whole of a truncated sentence.
    const plain = mount(HilosActionError, {
      props: { action: fakeAction('Something went wrong') },
    })
    const button = plain.find('[data-id="hilos-action-error-details"]')
    expect(button.exists()).toBe(true)
    expect(button.attributes('aria-label')).toBe('Show error details')
    expect(plain.find('[data-id="hilos-action-error-type"]').exists()).toBe(
      false,
    )

    const withType = mount(HilosActionError, {
      props: {
        action: fakeAction(
          'Something went wrong',
          new ActionError(
            'settings::apply',
            'fail',
            'Something went wrong',
            'RuntimeException',
            'SQLSTATE[HY000]: lock wait timeout',
          ),
        ),
      },
    })
    const type = withType.find(
      '[data-id="hilos-action-error-details"] [data-id="hilos-action-error-type"]',
    )
    expect(type.exists()).toBe(true)
    expect(type.text()).toBe('RuntimeException')
  })

  it('opens the panel on the message and closes it when the message goes', async () => {
    const action = fakeAction('The connection dropped before it answered')
    const wrapper = mount(HilosActionError, { props: { action } })

    await wrapper
      .find('[data-id="hilos-action-error-details"]')
      .trigger('click')
    expect(byId('hilos-action-error-message')?.textContent).toContain(
      'The connection dropped before it answered',
    )

    // Nothing was thrown as an ActionError, so the failure is null — the old
    // guard watched that and would have closed the panel in the same frame.
    await flushPromises()
    expect(byId('hilos-action-error-message')).not.toBeNull()

    action.error.value = null
    await flushPromises()
    expect(byId('hilos-action-error-message')).toBeNull()
  })
})
