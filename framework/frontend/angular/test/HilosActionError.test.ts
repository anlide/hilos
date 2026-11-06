// The Angular entry of HilosActionError's shared cases
// (../../test-support/actionErrorCases.ts): the steps and expectations live
// there, and this file only mounts the real component in a TestBed host and
// drives Angular's change detection for them.
import { Component, signal, type WritableSignal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { ActionError } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import {
  registerActionErrorCases,
  type ActionErrorInput,
  type ActionErrorView,
} from '../../test-support/actionErrorCases.js'
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

/**
 * A host that hands the component an action, a suppressed flag and a title.
 * Its fields are plain signals, not inputs, so this test file needs no JIT
 * transform of its own; the mount sets them before the first render.
 */
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
  readonly detailsTitle = signal('')
}

/**
 * Destroy a fixture and take its host element off the page. The test module
 * itself is reset by the package setup after each case, not here.
 *
 * @param fixture The fixture to destroy.
 */
function destroy(fixture: ComponentFixture<ActionErrorHost>): void {
  fixture.destroy()
  const element: HTMLElement = fixture.nativeElement
  element.remove()
}

/**
 * Mount the real component in its host; a mount that throws destroys what it
 * had already created.
 *
 * The action's outcome is the action's signals, the rest are the host's: an
 * update writes each where the component reads it from.
 *
 * @param input What the component is mounted with.
 * @returns The mounted view.
 */
async function mountActionError(
  input: ActionErrorInput,
): Promise<ActionErrorView> {
  const fixture = TestBed.createComponent(ActionErrorHost)
  try {
    const host = fixture.componentInstance
    const action = fakeAction(input.error, input.failure)
    host.action = action
    host.suppressed.set(input.suppressed)
    host.detailsTitle.set(input.detailsTitle)
    fixture.detectChanges()

    return {
      root: document.body,
      async update(patch) {
        if (patch.error !== undefined) {
          action.error.set(patch.error)
        }
        if (patch.failure !== undefined) {
          action.failure.set(patch.failure)
        }
        if (patch.suppressed !== undefined) {
          host.suppressed.set(patch.suppressed)
        }
        if (patch.detailsTitle !== undefined) {
          host.detailsTitle.set(patch.detailsTitle)
        }
        fixture.detectChanges()
      },
      async click(target) {
        target.click()
        fixture.detectChanges()
      },
      async flush() {
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async unmount() {
        destroy(fixture)
      },
    }
  } catch (error) {
    destroy(fixture)
    throw error
  }
}

registerActionErrorCases('Angular', { describe, it, expect }, mountActionError)
