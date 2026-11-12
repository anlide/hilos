// React input, event and lifecycle delivery for the shared avatar cases.
import { cleanup, fireEvent, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerAvatarCases,
  type WithAvatar,
} from '../../test-support/avatarCases.js'
import { HilosAvatar } from '../src/HilosAvatar.js'

/** Clean up React's roots even when rendering or updating throws. */
const withAvatar: WithAvatar = async (input, steps) => {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const { rerender } = render(<HilosAvatar {...current} />, { container })
    await steps({
      root: container,
      async update(patch) {
        current = { ...current, ...patch }
        rerender(<HilosAvatar {...current} />)
      },
      async imageError(image) {
        fireEvent.error(image)
      },
    })
  } finally {
    try {
      cleanup()
    } finally {
      container.remove()
    }
  }
}

registerAvatarCases('React', { describe, it, expect }, withAvatar)
