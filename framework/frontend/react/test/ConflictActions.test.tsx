import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { ConflictActions } from '../src/ConflictActions.js'

describe('ConflictActions', () => {
  afterEach(cleanup)

  it('shows only save without a conflict', () => {
    const { container } = render(<ConflictActions />)
    expect(container.querySelector('[data-id="conflict-save"]')).not.toBeNull()
    expect(container.querySelector('[data-id="conflict-merge"]')).toBeNull()
  })

  it('disables save and shows the resolutions on a conflict', () => {
    const { container } = render(<ConflictActions conflict />)
    const save = container.querySelector(
      '[data-id="conflict-save"]',
    ) as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(
      container.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="conflict-accept-theirs"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="conflict-merge"]')).not.toBeNull()
  })

  it('calls the chosen resolution handler', () => {
    let theirs = 0
    const { container } = render(
      <ConflictActions
        conflict
        onAcceptTheirs={() => {
          theirs += 1
        }}
      />,
    )
    fireEvent.click(
      container.querySelector('[data-id="conflict-accept-theirs"]') as Element,
    )
    expect(theirs).toBe(1)
  })

  it('calls onSave from the default button', () => {
    let saves = 0
    const { container } = render(
      <ConflictActions
        onSave={() => {
          saves += 1
        }}
      />,
    )
    fireEvent.click(
      container.querySelector('[data-id="conflict-save"]') as Element,
    )
    expect(saves).toBe(1)
  })

  it('renders a custom save button with the computed disabled state', () => {
    const { container } = render(
      <ConflictActions
        conflict
        saveButton={({ disabled, onSave }) => (
          <button data-id="custom-save" disabled={disabled} onClick={onSave}>
            Go
          </button>
        )}
      />,
    )
    const custom = container.querySelector(
      '[data-id="custom-save"]',
    ) as HTMLButtonElement
    expect(custom).not.toBeNull()
    expect(custom.disabled).toBe(true)
    expect(container.querySelector('[data-id="conflict-save"]')).toBeNull()
  })
})
