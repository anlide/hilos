// ConflictActions — the action row for an edit modal with 3-way-merge conflict
// resolution. The selector is an attribute on a native div, so the host IS the
// action row. It renders a Save button (provide an `<ng-template #saveButton>`
// to supply a custom one, e.g. a LoadingButton; it receives the computed
// `disabled` and an `onSave` handler through the template context) and, only
// while a conflict is unresolved, the three resolution choices: keep mine, take
// theirs, or merge. Save stays disabled until the conflict is resolved. The
// outputs fire the choice; the parent form applies it against the core
// threeWayMerge result. Bootstrap classes only.
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

/** The Save-plus-resolutions action row for a conflict-aware edit modal. */
@Component({
  selector: 'div[hilosConflictActions]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  host: { class: 'd-flex align-items-center gap-2 flex-wrap' },
  template: `
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
    @if (conflict()) {
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-accept-mine"
        (click)="acceptMine.emit()"
      >
        Keep mine
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-accept-theirs"
        (click)="acceptTheirs.emit()"
      >
        Take theirs
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-merge"
        (click)="merge.emit()"
      >
        Merge
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

  /** Save the resolved draft. */
  readonly save = output<void>()
  /** Resolve a conflict by keeping the user's draft. */
  readonly acceptMine = output<void>()
  /** Resolve a conflict by taking the incoming value. */
  readonly acceptTheirs = output<void>()
  /** Resolve a conflict by merging. */
  readonly merge = output<void>()

  /** A consumer's `<ng-template #saveButton>` replacing the default Save button. */
  protected readonly saveButton =
    contentChild<TemplateRef<ConflictSaveButtonContext>>('saveButton')

  protected readonly disabled = computed(
    () => this.disableSave() || this.conflict(),
  )
  protected readonly onSave = (): void => {
    this.save.emit()
  }
}
