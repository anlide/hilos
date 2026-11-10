// The Angular port of vue/src/ConflictActions.test.ts and
// react/test/ConflictActions.test.tsx: these cases verify that ConflictActions
// provides the Save button and conflict resolution choices, reacts to conflict
// state, emits resolution events, and renders the narrow-screen button group.
// The last describe is the admin view mode (HIL-1261): on an admin page a
// viewer stands on, Save — the default one and the one a template hands in —
// stands disabled, described by the view-mode strip, and the conflict choices,
// which edit only the draft, stay live.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { ConflictActions } from '../src/ConflictActions.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'

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
      <ng-template #cancelButton>
        <button type="button" data-id="host-cancel">Cancel</button>
      </ng-template>
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
  readonly mergeable = signal(false)
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

/** A live dialog whose parent resolves the choice emitted by its actions. */
@Component({
  selector: 'test-conflict-focus-host',
  imports: [ConflictActions],
  template: `
    @if (open()) {
      <div [attr.role]="inDialog() ? 'dialog' : null" aria-label="Edit title">
        <input aria-label="Title" data-autofocus />
        <input aria-label="Other control" />
        @if (showActions()) {
          <div
            hilosConflictActions
            [conflict]="conflict()"
            [mergeable]="true"
            (acceptMine)="resolve('mine')"
            (acceptTheirs)="resolve('theirs')"
            (merge)="resolve('merge')"
          ></div>
        }
      </div>
    }
    <div role="dialog" aria-label="Other window">
      <input aria-label="Other window field" data-autofocus />
    </div>
  `,
})
class ConflictFocusHost {
  readonly conflict = signal(true)
  readonly open = signal(true)
  readonly inDialog = signal(true)
  readonly showActions = signal(true)
  resolved: string | null = null

  resolve(choice: string): void {
    this.resolved = choice
    this.conflict.set(false)
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
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
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

  it('shows merge only when the surface asks for it (mergeable: true)', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()

    expect(
      document.querySelector('[data-id="conflict-accept-mine"]'),
    ).not.toBeNull()
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()

    fixture.componentInstance.mergeable.set(true)
    fixture.detectChanges()
    expect(document.querySelector('[data-id="conflict-merge"]')).not.toBeNull()
  })

  it('renders resolution buttons without btn-sm', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.componentInstance.mergeable.set(true)
    fixture.detectChanges()

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

  it('stands the choices, then the handed Cancel, then Save', () => {
    const fixture = mountHost()
    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()

    expect(dataIds()).toEqual([
      'conflict-choices',
      'conflict-accept-mine',
      'conflict-accept-theirs',
      'host-cancel',
      'conflict-save',
    ])
  })

  it("holds the choices' room with an idle twin while no conflict stands", () => {
    const fixture = mountHost()
    fixture.componentInstance.mergeable.set(true)
    fixture.detectChanges()
    const twin = document.querySelector(
      '[data-id="conflict-choices-idle"]',
    ) as HTMLElement

    expect(twin.classList.contains('invisible')).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.querySelectorAll('button')).toHaveLength(0)
    expect(
      [...twin.querySelectorAll('span')].map((span) => span.textContent),
    ).toEqual(['Keep mine', 'Take theirs', 'Merge'])
    expect(document.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(dataIds()).toEqual([
      'conflict-choices-idle',
      'host-cancel',
      'conflict-save',
    ])
  })

  it('swaps the twin for the choices with the same classes and labels', () => {
    const fixture = mountHost()
    fixture.componentInstance.mergeable.set(true)
    fixture.detectChanges()
    const twin = readChildren('conflict-choices-idle')

    fixture.componentInstance.conflict.set(true)
    fixture.detectChanges()
    expect(
      document.querySelector('[data-id="conflict-choices-idle"]'),
    ).toBeNull()
    expect(readChildren('conflict-choices')).toEqual(twin)

    fixture.componentInstance.conflict.set(false)
    fixture.detectChanges()
    expect(document.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(readChildren('conflict-choices-idle')).toEqual(twin)
  })
})

describe('ConflictActions focus after resolution', () => {
  /** Mount the real host and find its controls within this fixture. */
  function mountFocusHost() {
    const fixture = TestBed.createComponent(ConflictFocusHost)
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    const control = (name: string): HTMLInputElement =>
      root.querySelector<HTMLInputElement>(`input[aria-label="${name}"]`)!
    const choice = (name = 'accept-mine'): HTMLButtonElement =>
      root.querySelector<HTMLButtonElement>(`[data-id="conflict-${name}"]`)!

    return { fixture, host: fixture.componentInstance, root, control, choice }
  }

  it.each([
    ['accept-mine', 'mine'],
    ['accept-theirs', 'theirs'],
    ['merge', 'merge'],
  ])('returns focus to the same dialog after %s', (name, resolved) => {
    const { fixture, host, root, control, choice } = mountFocusHost()
    const button = choice(name)
    button.focus()
    expect(document.activeElement).toBe(button)
    button.click()
    fixture.detectChanges()

    expect(host.resolved).toBe(resolved)
    expect(root.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(document.activeElement).toBe(control('Title'))
    expect(
      root.querySelector<HTMLButtonElement>('[data-id="conflict-save"]')
        ?.disabled,
    ).toBe(false)
  })

  it('preserves focus moved before the conflict disappears', () => {
    const { fixture, control, choice } = mountFocusHost()
    choice().focus()
    choice().click()
    control('Other control').focus()
    fixture.detectChanges()

    expect(document.activeElement).toBe(control('Other control'))
  })

  it.each(['Other control', 'Other window field'])(
    'preserves a deliberate move to %s before the render callback',
    (name) => {
      const { fixture, control, choice } = mountFocusHost()
      choice().focus()
      choice().click()
      // Render the removal, then let Angular run its pending render callbacks.
      fixture.changeDetectorRef.detectChanges()
      expect(document.activeElement).toBe(document.body)
      control(name).focus()
      TestBed.tick()

      expect(document.activeElement).toBe(control(name))
    },
  )

  it('leaves focus alone outside a dialog', () => {
    const { fixture, host, choice } = mountFocusHost()
    host.inDialog.set(false)
    fixture.detectChanges()
    choice().focus()
    choice().click()
    fixture.detectChanges()

    expect(document.activeElement).toBe(document.body)
  })

  it.each([
    'close',
    'detach',
    'destroy',
    'replace',
    'reconflict',
    'resolve a new conflict',
  ])('drops a pending focus return on %s', (change) => {
    const { fixture, host, root, choice } = mountFocusHost()
    choice().focus()
    choice().click()
    fixture.changeDetectorRef.detectChanges()
    expect(document.activeElement).toBe(document.body)

    if (change === 'close') host.open.set(false)
    else if (change === 'detach') root.remove()
    else if (change === 'destroy') fixture.destroy()
    else if (change === 'replace') {
      host.showActions.set(false)
      fixture.changeDetectorRef.detectChanges()
      host.showActions.set(true)
    } else if (change === 'resolve a new conflict') {
      host.conflict.set(true)
      fixture.changeDetectorRef.detectChanges()
      host.conflict.set(false)
    } else host.conflict.set(true)
    TestBed.tick()

    expect(document.activeElement).toBe(document.body)
  })
})

describe('ConflictActions in the admin view mode', () => {
  /**
   * Mount the host on an admin page a viewer stands on, the way HilosAdminPage
   * provides it.
   *
   * @returns The mounted fixture.
   */
  function mountInViewMode(): ComponentFixture<ConflictActionsHost> {
    TestBed.configureTestingModule({
      providers: [
        { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(true).asReadonly() },
      ],
    })

    return mountHost()
  }

  /**
   * One button of the mounted host.
   *
   * @param fixture The mounted host.
   * @param id The button's data-id.
   */
  function button(
    fixture: ComponentFixture<ConflictActionsHost>,
    id: string,
  ): HTMLButtonElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector(
      `[data-id="${id}"]`,
    )
  }

  it('disables the default save and points it at the strip', () => {
    const fixture = mountInViewMode()
    const save = button(fixture, 'conflict-save')

    expect(save?.disabled).toBe(true)
    expect(save?.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    save?.click()
    expect(fixture.componentInstance.saveCalls).toBe(0)
  })

  it('hands the template save a disabled state', () => {
    const fixture = mountInViewMode()
    fixture.componentInstance.useCustomSave.set(true)
    fixture.detectChanges()

    expect(button(fixture, 'custom-save')?.disabled).toBe(true)
  })

  it('keeps the conflict choices, which edit only the draft', () => {
    const fixture = mountInViewMode()
    fixture.componentInstance.conflict.set(true)
    fixture.componentInstance.mergeable.set(true)
    fixture.detectChanges()

    for (const choice of ['accept-mine', 'accept-theirs', 'merge']) {
      const choiceButton = button(fixture, `conflict-${choice}`)
      expect(choiceButton?.disabled).toBe(false)
      choiceButton?.click()
    }
    expect(fixture.componentInstance.acceptMineCalls).toBe(1)
    expect(fixture.componentInstance.acceptTheirsCalls).toBe(1)
    expect(fixture.componentInstance.mergeCalls).toBe(1)
  })

  it('keeps the handed Cancel live', () => {
    const fixture = mountInViewMode()

    expect(button(fixture, 'host-cancel')?.disabled).toBe(false)
  })

  it('leaves the default save untouched outside the mode', () => {
    const fixture = mountHost()
    const save = button(fixture, 'conflict-save')

    expect(save?.disabled).toBe(false)
    expect(save?.getAttribute('aria-describedby')).toBeNull()
  })
})

/** Document order of every data-id under the mounted action group. */
function dataIds(): string[] {
  const root = document.querySelector('[hilosConflictActions]')
  return [...(root?.querySelectorAll('[data-id]') ?? [])].map(
    (el) => el.getAttribute('data-id') ?? '',
  )
}

/** className and text of the direct children of a choices group. */
function readChildren(id: string): { className: string; text: string }[] {
  const group = document.querySelector(`[data-id="${id}"]`)
  return [...(group?.children ?? [])].map((node) => ({
    className: (node as HTMLElement).className,
    text: (node.textContent ?? '').trim(),
  }))
}
