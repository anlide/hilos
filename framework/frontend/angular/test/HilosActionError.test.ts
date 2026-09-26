// The Angular port of react/test/HilosActionError.test.tsx and
// vue/src/HilosActionError.test.ts, under the same case names: the adapter that
// draws a tracked action's refusal on the form refusal row.
import { Component, signal, type WritableSignal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { ActionError } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosActionError } from '../src/HilosActionError.js'
import type { HilosTrackedAction } from '../src/hilosTrackedAction.js'

/** The state of a tracked action, with the two fields a test drives by hand. */
interface FakeAction extends HilosTrackedAction {
  readonly error: WritableSignal<string | null>
  readonly failure: WritableSignal<ActionError | null>
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
  failure: ActionError | null = null,
): FakeAction {
  return {
    loading: signal(false),
    busy: signal(false),
    error: signal(error),
    failure: signal(failure),
    run: async () => false,
    clearError: () => {},
  }
}

/** A host that hands the component an action, a suppressed flag and a title. */
@Component({
  selector: 'test-action-error-host',
  imports: [HilosActionError],
  template: `
    <hilos-action-error
      [action]="action"
      [suppressed]="suppressed()"
      [detailsTitle]="detailsTitle()"
    />
  `,
})
class ActionErrorHost {
  action: FakeAction = fakeAction(null)
  readonly suppressed = signal(false)
  readonly detailsTitle = signal("Couldn't save")
}

/**
 * Mount the host with an action and render it once.
 *
 * @param action The tracked action to draw.
 * @param suppressed Whether the action is answering elsewhere.
 * @param detailsTitle What the mounting place says the action failed to do.
 * @returns The mounted fixture.
 */
function mountError(
  action: FakeAction,
  suppressed = false,
  detailsTitle = "Couldn't save",
): ComponentFixture<ActionErrorHost> {
  const fixture = TestBed.createComponent(ActionErrorHost)
  fixture.componentInstance.action = action
  fixture.componentInstance.suppressed.set(suppressed)
  fixture.componentInstance.detailsTitle.set(detailsTitle)
  fixture.detectChanges()

  return fixture
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosActionError', () => {
  it('draws the slot and no plate while nothing has failed', () => {
    mountError(fakeAction(null))
    const slot = byId('hilos-action-error-slot')
    expect(slot).not.toBeNull()
    expect(slot?.getAttribute('role')).toBe('alert')
    expect(slot?.getAttribute('aria-live')).toBe('assertive')
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('holds the room with an invisible twin while nothing has failed', () => {
    mountError(fakeAction(null))
    const twin = document.querySelector(
      '[data-id="hilos-action-error-slot"] [data-id="hilos-action-error-idle"]',
    )
    expect(twin).not.toBeNull()
    // Hidden from the eye and from the reader both: the room is all it is for.
    expect(twin?.classList.contains('invisible')).toBe(true)
    expect(twin?.getAttribute('aria-hidden')).toBe('true')
  })

  it('draws the refusal on one line, and says it only once', () => {
    mountError(fakeAction('Value must be an integer of 0 or more'))
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
    mountError(fakeAction('Not applied'), true)
    expect(byId('hilos-action-error-slot')).not.toBeNull()
    expect(byId('hilos-action-error-idle')).not.toBeNull()
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('offers the details button on every refusal, named for a reader', () => {
    // Nothing was held back here — no type, no original text — and the button
    // is still the way to the whole of a truncated sentence.
    const plain = mountError(fakeAction('Something went wrong'))
    const button = byId('hilos-action-error-details')
    expect(button).not.toBeNull()
    expect(button?.getAttribute('aria-label')).toBe('Show error details')
    expect(byId('hilos-action-error-type')).toBeNull()
    plain.destroy()

    mountError(
      fakeAction(
        'Something went wrong',
        new ActionError(
          'settings::apply',
          'fail',
          'Something went wrong',
          'RuntimeException',
          'SQLSTATE[HY000]: lock wait timeout',
        ),
      ),
    )
    const type = document.querySelector(
      '[data-id="hilos-action-error-details"] [data-id="hilos-action-error-type"]',
    )
    expect(type).not.toBeNull()
    expect(type?.textContent).toBe('RuntimeException')
  })

  it('opens the panel on the message and closes it when the message goes', () => {
    const message = 'The connection dropped before it answered'
    const action = fakeAction(message)
    const fixture = mountError(action)

    byId('hilos-action-error-details')?.click()
    fixture.detectChanges()
    expect(byId('hilos-action-error-full')?.textContent).toContain(message)

    // Nothing was thrown as an ActionError, so the failure is null — the old
    // guard watched that and would have closed the panel in the same frame.
    fixture.detectChanges()
    expect(byId('hilos-action-error-full')).not.toBeNull()

    action.error.set(null)
    fixture.detectChanges()
    expect(byId('hilos-action-error-full')).toBeNull()
  })

  it('draws the compact row of the form refusal, not a plate of its own', () => {
    mountError(fakeAction('Not applied'))
    const row = byId('hilos-action-error')
    expect(row?.classList.contains('small')).toBe(true)
    expect(row?.classList.contains('py-1')).toBe(true)
    const button = byId('hilos-action-error-details')
    expect(button?.classList.contains('btn-link')).toBe(true)
    expect(document.querySelector('.rounded-pill')).toBeNull()
  })

  it('closes an open panel when the action turns suppressed', () => {
    const fixture = mountError(fakeAction('Not applied'))
    byId('hilos-action-error-details')?.click()
    fixture.detectChanges()
    expect(byId('hilos-action-error-full')).not.toBeNull()

    fixture.componentInstance.suppressed.set(true)
    fixture.detectChanges()
    expect(byId('hilos-action-error-full')).toBeNull()
    expect(byId('hilos-action-error-idle')).not.toBeNull()
  })

  it('treats an empty message as no refusal', () => {
    mountError(fakeAction(''))
    expect(byId('hilos-action-error-idle')).not.toBeNull()
    expect(byId('hilos-action-error')).toBeNull()
  })

  it('heads the details panel with what the place says failed', () => {
    const fixture = mountError(
      fakeAction('Value must be an integer of 0 or more'),
      false,
      "Couldn't delete the backup",
    )
    byId('hilos-action-error-details')?.click()
    fixture.detectChanges()

    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Couldn't delete the backup",
    )
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe("Couldn't delete the backup")
  })
})
