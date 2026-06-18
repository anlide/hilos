import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { ConflictHeader } from '../src/ConflictHeader.js'

describe('ConflictHeader', () => {
  afterEach(cleanup)

  it('shows the title without a badge', () => {
    const { container } = render(<ConflictHeader title="Edit user" />)
    expect(container.textContent).toContain('Edit user')
    expect(container.querySelector('[data-id="conflict-badge"]')).toBeNull()
  })

  it('flags an unresolved conflict with a badge', () => {
    const { container } = render(<ConflictHeader title="Edit user" conflict />)
    expect(container.querySelector('[data-id="conflict-badge"]')).not.toBeNull()
  })
})
