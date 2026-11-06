// The React entry of HilosActionError's shared cases
// (../../test-support/actionErrorCases.ts): the steps and expectations live
// there, and this file only renders the real component and drives React's
// lifecycle for them.
import type { ActionError } from '@hilos/core/base'
import { act, fireEvent, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerActionErrorCases,
  type ActionErrorInput,
  type ActionErrorView,
} from '../../test-support/actionErrorCases.js'
import { HilosActionError } from '../src/HilosActionError.js'
import type { TrackedAction } from '../src/useTrackedAction.js'

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
): TrackedAction {
  return {
    loading: false,
    busy: false,
    error,
    failure,
    run: async () => false,
    clearError: () => {},
  }
}

/**
 * The component as one render draws it, over a plain action of its own.
 *
 * @param input What the component is drawn with.
 * @returns The element to render.
 */
function draw(input: ActionErrorInput) {
  return (
    <HilosActionError
      action={fakeAction(input.error, input.failure)}
      suppressed={input.suppressed}
      detailsTitle={input.detailsTitle}
    />
  )
}

/**
 * Render the real component into a container of its own on the body; a render
 * that throws takes its container away with it.
 *
 * @param input What the component is rendered with.
 * @returns The mounted view.
 */
async function mountActionError(
  input: ActionErrorInput,
): Promise<ActionErrorView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const { rerender, unmount } = render(draw(current), { container })

    return {
      root: document.body,
      async update(patch) {
        current = { ...current, ...patch }
        // Every update renders again, even with the message it already had: a
        // tracked action is a new plain object on every render of its owner,
        // and the case of a message that stays is about exactly that render.
        rerender(draw(current))
      },
      async click(target) {
        fireEvent.click(target)
      },
      async flush() {
        await act(async () => {})
      },
      async unmount() {
        // The details panel portals to <body>; unmounting the root takes it,
        // and its scroll lock, away with the row.
        unmount()
        container.remove()
      },
    }
  } catch (error) {
    container.remove()
    throw error
  }
}

registerActionErrorCases('React', { describe, it, expect }, mountActionError)
