import { ActionError } from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { HilosActionError } from '../src/HilosActionError.js'
import type { TrackedAction } from '../src/useTrackedAction.js'

// The detail modal portals to <body>, so assertions query the document.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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

describe('HilosActionError', () => {
  it('draws the slot and no plate while nothing has failed', () => {
    render(<HilosActionError action={fakeAction(null)} />)
    const slot = byId('hilos-action-error-slot')
    expect(slot).not.toBeNull()
    expect(slot?.getAttribute('role')).toBe('alert')
    expect(slot?.getAttribute('aria-live')).toBe('assertive')
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('holds the room with an invisible twin while nothing has failed', () => {
    render(<HilosActionError action={fakeAction(null)} />)
    const twin = document.querySelector(
      '[data-id="hilos-action-error-slot"] [data-id="hilos-action-error-idle"]',
    )
    expect(twin).not.toBeNull()
    // Hidden from the eye and from the reader both: the room is all it is for.
    expect(twin?.classList.contains('invisible')).toBe(true)
    expect(twin?.getAttribute('aria-hidden')).toBe('true')
  })

  it('draws the refusal on one line, and says it only once', () => {
    render(
      <HilosActionError
        action={fakeAction('Value must be an integer of 0 or more')}
      />,
    )
    const plate = byId('hilos-action-error')
    expect(plate).not.toBeNull()
    expect(plate?.textContent).toContain(
      'Value must be an integer of 0 or more',
    )
    // The slot is the live region; a role here too and the reader says it twice.
    expect(plate?.getAttribute('role')).toBeNull()
    const sentence = plate?.querySelector('span.flex-grow-1')
    expect(sentence?.classList.contains('text-truncate')).toBe(true)
  })

  it('keeps the room and drops the voice when suppressed', () => {
    render(<HilosActionError action={fakeAction('Not applied')} suppressed />)
    expect(byId('hilos-action-error-slot')).not.toBeNull()
    expect(byId('hilos-action-error-idle')).not.toBeNull()
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('offers the details button on every refusal, named for a reader', () => {
    // Nothing was held back here — no type, no original text — and the button
    // is still the way to the whole of a truncated sentence.
    const { unmount } = render(
      <HilosActionError action={fakeAction('Something went wrong')} />,
    )
    const button = byId('hilos-action-error-details')
    expect(button).not.toBeNull()
    expect(button?.getAttribute('aria-label')).toBe('Show error details')
    expect(byId('hilos-action-error-type')).toBeNull()
    unmount()

    render(
      <HilosActionError
        action={fakeAction(
          'Something went wrong',
          new ActionError(
            'settings::apply',
            'fail',
            'Something went wrong',
            'RuntimeException',
            'SQLSTATE[HY000]: lock wait timeout',
          ),
        )}
      />,
    )
    const type = document.querySelector(
      '[data-id="hilos-action-error-details"] [data-id="hilos-action-error-type"]',
    )
    expect(type).not.toBeNull()
    expect(type?.textContent).toBe('RuntimeException')
  })

  it('opens the panel on the message and closes it when the message goes', () => {
    const message = 'The connection dropped before it answered'
    const { rerender } = render(
      <HilosActionError action={fakeAction(message)} />,
    )

    fireEvent.click(byId('hilos-action-error-details') as HTMLElement)
    expect(byId('hilos-action-error-message')?.textContent).toContain(message)

    // Nothing was thrown as an ActionError, so the failure is null — the old
    // guard watched that and would have closed the panel in the same frame.
    rerender(<HilosActionError action={fakeAction(message)} />)
    expect(byId('hilos-action-error-message')).not.toBeNull()

    rerender(<HilosActionError action={fakeAction(null)} />)
    expect(byId('hilos-action-error-message')).toBeNull()
  })
})
