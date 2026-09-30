// ConflictActions — the action group for an edit modal with 3-way-merge
// conflict resolution. The group draws, left to right, the conflict choices, the
// window's Cancel (`<ng-template #cancelButton>`) and Save, in that same markup
// order, so Tab runs left to right. While no conflict stands, an invisible twin
// of the same markup holds the choices' room, so neither the arrival nor the
// leaving of a conflict moves Cancel or Save (styling-rules.md, "The room a
// live message takes"; the owner's decision on the acceptance of HIL-1050). The
// selector is an attribute on a native div, so the host IS the action group. It
// renders a Save button (provide an `<ng-template #saveButton>` to supply a
// custom one, e.g. a LoadingButton; it receives the computed `disabled` and an
// `onSave` handler through the template context) and, only while a conflict is
// unresolved, the three resolution choices: keep mine, take theirs, and merge
// where the surface asks for it. Save stays disabled until the conflict is
// resolved. The outputs fire the choice; the parent form applies it against the
// core threeWayMerge result. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  input,
  output,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { NgTemplateOutlet } from '@angular/common'

/** The context a custom save-button template receives. */
export interface ConflictSaveButtonContext {
  /** Whether save is blocked (an unresolved conflict or an invalid draft). */
  disabled: boolean
  /** Save the resolved draft. */
  onSave: () => void
}

/**
 * The choice buttons and the inert spans of their twin, to the character —
 * the room equals the true width of the choices only while the classes match.
 */
const CHOICE_CLASS = 'btn btn-outline-secondary'

/** The Save-plus-resolutions action group for a conflict-aware edit modal. */
@Component({
  selector: 'div[hilosConflictActions]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  host: {
    class: 'hilos-button-group d-md-flex align-items-center gap-2 flex-wrap',
  },
  template: `
    @if (conflict()) {
      <div
        class="hilos-conflict-choices d-flex gap-2"
        data-id="conflict-choices"
      >
        <button
          type="button"
          [class]="choiceClass"
          data-id="conflict-accept-mine"
          (click)="acceptMine.emit()"
        >
          Keep mine
        </button>
        <button
          type="button"
          [class]="choiceClass"
          data-id="conflict-accept-theirs"
          (click)="acceptTheirs.emit()"
        >
          Take theirs
        </button>
        @if (mergeable()) {
          <button
            type="button"
            [class]="choiceClass"
            data-id="conflict-merge"
            (click)="merge.emit()"
          >
            Merge
          </button>
        }
      </div>
    } @else {
      <div
        class="hilos-conflict-choices d-flex gap-2 invisible"
        aria-hidden="true"
        data-id="conflict-choices-idle"
      >
        <!-- Spans, not buttons: the twin holds the room, it takes no focus. -->
        <span [class]="choiceClass">Keep mine</span>
        <span [class]="choiceClass">Take theirs</span>
        @if (mergeable()) {
          <span [class]="choiceClass">Merge</span>
        }
      </div>
    }
    @if (cancelButton(); as cancel) {
      <ng-container [ngTemplateOutlet]="cancel" />
    }
    @if (saveButton(); as tpl) {
      <ng-container
        [ngTemplateOutlet]="tpl"
        [ngTemplateOutletContext]="{ disabled: disabled(), onSave: onSave }"
      />
    } @else {
      <button
        type="button"
        class="btn btn-primary"
        [disabled]="disabled()"
        data-id="conflict-save"
        (click)="onSave()"
      >
        {{ saveLabel() }}
      </button>
    }
  `,
})
export class ConflictActions {
  /** Whether an unresolved conflict blocks saving. */
  readonly conflict = input(false)
  /** Disable save independently (e.g. an invalid draft). */
  readonly disableSave = input(false)
  /** Save button label. */
  readonly saveLabel = input('Save')
  /**
   * Whether the Merge resolution is offered. Only a surface that asks for it
   * shows the button — one where splicing the two values makes sense. A typed
   * value (a setting, a field, a name) never asks: the splice is not a value.
   * Defaults to false.
   */
  readonly mergeable = input(false)

  /** Save the resolved draft. */
  readonly save = output<void>()
  /** Resolve a conflict by keeping the user's draft. */
  readonly acceptMine = output<void>()
  /** Resolve a conflict by taking the incoming value. */
  readonly acceptTheirs = output<void>()
  /** Resolve a conflict by merging. */
  readonly merge = output<void>()

  /**
   * A consumer's `<ng-template #cancelButton>` — the window's Cancel, drawn
   * between the choices and Save.
   */
  protected readonly cancelButton =
    contentChild<TemplateRef<unknown>>('cancelButton')

  /** A consumer's `<ng-template #saveButton>` replacing the default Save button. */
  protected readonly saveButton =
    contentChild<TemplateRef<ConflictSaveButtonContext>>('saveButton')

  /** The class the choice buttons and their twin share, to the character. */
  protected readonly choiceClass = CHOICE_CLASS

  protected readonly disabled = computed(
    () => this.disableSave() || this.conflict(),
  )
  protected readonly onSave = (): void => {
    this.save.emit()
  }
}
