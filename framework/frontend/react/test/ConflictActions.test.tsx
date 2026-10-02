import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { ConflictActions } from '../src/ConflictActions.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'

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
    expect(container.querySelector('[data-id="conflict-merge"]')).toBeNull()
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

  it('shows merge only when the surface asks for it (mergeable: true)', () => {
    const { container, rerender } = render(<ConflictActions conflict />)
    expect(
      container.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="conflict-merge"]')).toBeNull()

    rerender(<ConflictActions conflict mergeable />)
    expect(container.querySelector('[data-id="conflict-merge"]')).not.toBeNull()
  })

  it('shapes the root as a button group and renders resolution buttons without btn-sm', () => {
    const { container } = render(<ConflictActions conflict mergeable />)
    const root = container.firstElementChild as HTMLElement
    expect(root.classList.contains('hilos-button-group')).toBe(true)
    expect(root.classList.contains('d-md-flex')).toBe(true)

    const buttons = [
      container.querySelector('[data-id="conflict-accept-mine"]'),
      container.querySelector('[data-id="conflict-accept-theirs"]'),
      container.querySelector('[data-id="conflict-merge"]'),
    ]
    for (const btn of buttons) {
      expect(btn?.classList.contains('btn-sm')).toBe(false)
      expect(btn?.classList.contains('btn')).toBe(true)
    }
  })

  it('stands the choices, then the handed Cancel, then Save', () => {
    const { container } = render(
      <ConflictActions
        conflict
        cancelButton={
          <button type="button" data-id="host-cancel">
            Cancel
          </button>
        }
      />,
    )
    const ids = [...container.querySelectorAll('[data-id]')].map((el) =>
      el.getAttribute('data-id'),
    )
    expect(ids).toEqual([
      'conflict-choices',
      'conflict-accept-mine',
      'conflict-accept-theirs',
      'host-cancel',
      'conflict-save',
    ])
  })

  it("holds the choices' room with an idle twin while no conflict stands", () => {
    const { container } = render(
      <ConflictActions
        mergeable
        cancelButton={
          <button type="button" data-id="host-cancel">
            Cancel
          </button>
        }
      />,
    )
    const twin = container.querySelector(
      '[data-id="conflict-choices-idle"]',
    ) as HTMLElement
    expect(twin.classList.contains('hilos-conflict-choices')).toBe(true)
    expect(twin.classList.contains('invisible')).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.querySelectorAll('button')).toHaveLength(0)
    expect(
      [...twin.querySelectorAll('span')].map((span) => span.textContent),
    ).toEqual(['Keep mine', 'Take theirs', 'Merge'])
    expect(container.querySelector('[data-id="conflict-choices"]')).toBeNull()
    const ids = [...container.querySelectorAll('[data-id]')].map((el) =>
      el.getAttribute('data-id'),
    )
    expect(ids).toEqual([
      'conflict-choices-idle',
      'host-cancel',
      'conflict-save',
    ])
  })

  it('swaps the twin for the choices with the same classes and labels', () => {
    const read = (root: ParentNode, id: string) =>
      [...root.querySelector(`[data-id="${id}"]`)!.children].map((node) => ({
        className: (node as HTMLElement).className,
        text: (node.textContent ?? '').trim(),
      }))
    const { container, rerender } = render(<ConflictActions mergeable />)
    const twin = read(container, 'conflict-choices-idle')

    rerender(<ConflictActions conflict mergeable />)
    expect(
      container.querySelector('[data-id="conflict-choices-idle"]'),
    ).toBeNull()
    expect(read(container, 'conflict-choices')).toEqual(twin)

    rerender(<ConflictActions mergeable />)
    expect(container.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(read(container, 'conflict-choices-idle')).toEqual(twin)
  })
})

describe('ConflictActions in the admin view mode', () => {
  afterEach(cleanup)

  it('disables the default save and points it at the strip', () => {
    let saves = 0
    const { container } = render(
      <HilosAdminViewModeContext.Provider value>
        <ConflictActions
          onSave={() => {
            saves += 1
          }}
        />
      </HilosAdminViewModeContext.Provider>,
    )
    const save = container.querySelector(
      '[data-id="conflict-save"]',
    ) as HTMLButtonElement

    expect(save.disabled).toBe(true)
    expect(save.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    fireEvent.click(save)
    expect(saves).toBe(0)
  })

  it('hands the slotted save a disabled state', () => {
    const seen: boolean[] = []
    render(
      <HilosAdminViewModeContext.Provider value>
        <ConflictActions
          saveButton={({ disabled }) => {
            seen.push(disabled)

            return 'Save'
          }}
        />
      </HilosAdminViewModeContext.Provider>,
    )

    expect(seen).toEqual([true])
  })

  it('keeps the conflict choices, which edit only the draft', () => {
    const calls: Record<string, number> = {
      'accept-mine': 0,
      'accept-theirs': 0,
      merge: 0,
    }
    const { container } = render(
      <HilosAdminViewModeContext.Provider value>
        <ConflictActions
          conflict
          mergeable
          onAcceptMine={() => {
            calls['accept-mine'] += 1
          }}
          onAcceptTheirs={() => {
            calls['accept-theirs'] += 1
          }}
          onMerge={() => {
            calls['merge'] += 1
          }}
        />
      </HilosAdminViewModeContext.Provider>,
    )

    for (const choice of ['accept-mine', 'accept-theirs', 'merge']) {
      const button = container.querySelector(
        `[data-id="conflict-${choice}"]`,
      ) as HTMLButtonElement
      expect(button.disabled).toBe(false)
      fireEvent.click(button)
      expect(calls[choice]).toBe(1)
    }
  })

  it('leaves the default save untouched outside the mode', () => {
    const { container } = render(<ConflictActions />)
    const save = container.querySelector(
      '[data-id="conflict-save"]',
    ) as HTMLButtonElement

    expect(save.disabled).toBe(false)
    expect(save.getAttribute('aria-describedby')).toBeNull()
  })
})
