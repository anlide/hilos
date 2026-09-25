// The Angular port of vue/src/ConflictActions.test.ts and
// react/test/ConflictActions.test.tsx: these cases verify that ConflictActions
// provides the Save button and conflict resolution choices, reacts to conflict
// state, emits resolution events, and renders the narrow-screen button group.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { ConflictActions } from '../src/ConflictActions.js'

/** A host that drives ConflictActions through signals and captures output events. */
@Component({
  selector: 'test-conflict-actions-host',
  imports: [ConflictActions],
  template: `
    <div
      hilosConflictActions
      [conflict]="conflict()"
      [disableSave]="disableSave()"
      [saveLabel]="saveLabel()"
      [mergeable]="mergeable()"
      (save)="onSave()"
      (acceptMine)="onAcceptMine()"
      (acceptTheirs)="onAcceptTheirs()"
      (merge)="onMerge()"
    >
      @if (useCustomSave()) {
        <ng-template #saveButton let-disabled="disabled" let-onSave="onSave">
          <button
            data-id="custom-save"
            [disabled]="disabled"
            (click)="onSave()"
          >
            Go
          </button>
        </ng-template>
      }
    </div>
  `,
})
class ConflictActionsHost {
  readonly conflict = signal(false)
  readonly disableSave = signal(false)
  readonly saveLabel = signal('Save')
  readonly mergeable = signal(true)
  readonly useCustomSave = signal(false)

  saveCalls = 0
  acceptMineCalls = 0
  acceptTheirsCalls = 0
  mergeCalls = 0

  onSave(): void {
    this.saveCalls += 1
  }

  onAcceptMine(): void {
    this.acceptMineCalls += 1
  }

  onAcceptTheirs(): void {
    this.acceptTheirsCalls += 1
  }

  onMerge(): void {
    this.mergeCalls += 1
  }
}

/**
 * Mount the host and render its initial state.
 *
 * @returns The mounted fixture.
 */
function mountHost(): ComponentFixture<ConflictActionsHost> {
  const fixture = TestBed.createComponent(ConflictActionsHost)
  fixture.detectChanges()

  return fixture
}

describe('ConflictActions', () => {
  it('shows only save without a conflict', () => {
    mountHost()
    expect(document.querySelector('[data-id="conflict-save"]')).not.toBeNull()
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
  })

  it('disables save and shows the resolutions on a conflict', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()

    const save = document.querySelector<HTMLButtonElement>(
      '[data-id="conflict-save"]',
    )
    expect(save?.disabled).toBe(true)
    expect(
      document.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(
      document.querySelector('[data-id="conflict-accept-theirs"]'),
    ).not.toBeNull()
    expect(document.querySelector('[data-id="conflict-merge"]')).not.toBeNull()
  })

  it('emits the chosen resolution', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="conflict-accept-theirs"]')
      ?.click()
    fixture.detectChanges()

    expect(fixture.componentInstance.acceptTheirsCalls).toBe(1)
  })

  it('emits save from the default button', () => {
    const fixture = mountHost()
    document
      .querySelector<HTMLButtonElement>('[data-id="conflict-save"]')
      ?.click()
    fixture.detectChanges()

    expect(fixture.componentInstance.saveCalls).toBe(1)
  })

  it('renders a custom save button with the computed disabled state', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.componentInstance.useCustomSave.set(true)
    fixture.detectChanges()

    const custom = document.querySelector<HTMLButtonElement>(
      '[data-id="custom-save"]',
    )
    expect(custom).not.toBeNull()
    expect(custom?.disabled).toBe(true)
    expect(document.querySelector('[data-id="conflict-save"]')).toBeNull()
  })

  it('hides the merge button when mergeable is false', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.componentInstance.mergeable.set(false)
    fixture.detectChanges()

    expect(
      document.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
  })

  it('shapes the root as a button group and renders resolution buttons without btn-sm', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()

    const root = document.querySelector(
      '[data-id="conflict-save"]',
    )?.parentElement
    expect(root?.classList.contains('hilos-button-group')).toBe(true)
    expect(root?.classList.contains('d-md-flex')).toBe(true)

    const buttons = [
      document.querySelector('[data-id="conflict-accept-mine"]'),
      document.querySelector('[data-id="conflict-accept-theirs"]'),
      document.querySelector('[data-id="conflict-merge"]'),
    ]
    for (const btn of buttons) {
      expect(btn?.classList.contains('btn-sm')).toBe(false)
      expect(btn?.classList.contains('btn')).toBe(true)
    }
  })
})
