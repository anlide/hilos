// React projection and lifecycle for the shared Hideable scenarios.
import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerHideableCases,
  type HideableInput,
  type HideableView,
} from '../../test-support/hideableCases.js'
import { HilosHideable } from '../src/HilosHideable.js'

/** Mount the real component, translating projection into its render prop. */
async function mountHideable(input: HideableInput): Promise<HideableView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    const renderValue = input.renderValue
    const { unmount } = render(
      <HilosHideable value={input.value}>
        {renderValue
          ? (value) => <code data-id="shown">{renderValue(value)}</code>
          : undefined}
      </HilosHideable>,
      { container },
    )
    return {
      root: container,
      async unmount() {
        try {
          unmount()
        } finally {
          container.remove()
        }
      },
    }
  } catch (error) {
    container.remove()
    throw error
  }
}

registerHideableCases('React', { describe, it, expect }, mountHideable)
