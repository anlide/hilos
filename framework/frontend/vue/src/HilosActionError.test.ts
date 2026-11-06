// The Vue entry of HilosActionError's shared cases
// (../../test-support/actionErrorCases.ts): the steps and expectations live
// there, and this file only mounts the real component and drives Vue's
// lifecycle for them.
import type { ActionError } from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick, ref, type Ref } from 'vue'

import {
  registerActionErrorCases,
  type ActionErrorInput,
  type ActionErrorView,
} from '../../test-support/actionErrorCases.js'
import HilosActionError from './HilosActionError.vue'
import type { TrackedAction } from './useTrackedAction.js'

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
 * @returns The fake action.
 */
function fakeAction(
  error: string | null,
  failure: ActionError | null,
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

/**
 * Mount the component into a host already on the body, and hand back its view.
 *
 * The action's outcome is the action's refs, the rest are props: an update
 * writes each where the component reads it from.
 *
 * @param host The element the component is attached to.
 * @param input What the component is mounted with.
 * @returns The mounted view.
 */
function attach(host: HTMLElement, input: ActionErrorInput): ActionErrorView {
  const action = fakeAction(input.error, input.failure)
  const wrapper = mount(HilosActionError, {
    attachTo: host,
    props: {
      action,
      suppressed: input.suppressed,
      detailsTitle: input.detailsTitle,
    },
  })

  return {
    root: document.body,
    async update(patch) {
      if (patch.error !== undefined) {
        action.error.value = patch.error
      }
      if (patch.failure !== undefined) {
        action.failure.value = patch.failure
      }
      const props: { suppressed?: boolean; detailsTitle?: string } = {}
      if (patch.suppressed !== undefined) {
        props.suppressed = patch.suppressed
      }
      if (patch.detailsTitle !== undefined) {
        props.detailsTitle = patch.detailsTitle
      }
      await wrapper.setProps(props)
    },
    async click(target) {
      target.click()
      await nextTick()
    },
    async flush() {
      await flushPromises()
    },
    async unmount() {
      // The details panel teleports to <body>; unmounting the app takes it,
      // and its scroll lock, away with the row.
      wrapper.unmount()
      host.remove()
    },
  }
}

/**
 * Mount the real component on a host of its own; a mount that throws takes
 * its host away with it.
 *
 * @param input What the component is mounted with.
 * @returns The mounted view.
 */
async function mountActionError(
  input: ActionErrorInput,
): Promise<ActionErrorView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    return attach(host, input)
  } catch (error) {
    host.remove()
    throw error
  }
}

registerActionErrorCases('Vue', { describe, it, expect }, mountActionError)
