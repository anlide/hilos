// HilosUploadPicker — the paperclip door of the upload field: a button that
// opens the browser's file chooser over a hidden input. The chosen files go to
// the field, and the input is emptied at once, or the same file could not be
// chosen twice in a row. The accept filter is a hint to the chooser only: a drop
// or a paste passes it by, and the server decides.
import { HILOS_UPLOAD_FIELD_COPY, type HilosUploadField } from '@hilos/core'
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'

import { mirrorHilosSignal } from './hilosSignal.js'

/** The paperclip that chooses files for an upload field. */
@Component({
  selector: 'hilos-upload-picker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="btn btn-outline-secondary flex-shrink-0"
      [attr.aria-label]="copy.attach"
      [disabled]="disabled()"
      [attr.data-id]="dataId()"
      (click)="chooser.click()"
    >
      <i class="bi bi-paperclip" aria-hidden="true"></i>
    </button>
    <input
      #chooser
      type="file"
      multiple
      class="d-none"
      [accept]="accept()"
      [attr.data-id]="dataId() + '-input'"
      (change)="picked($event)"
    />
  `,
})
export class HilosUploadPicker {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field = input.required<HilosUploadField>()
  /** The data-id of the button; the hidden input derives its own from it. */
  readonly dataId = input.required<string>()
  /** The chooser's file filter, as the input's accept attribute; empty takes any file. */
  readonly accept = input('')
  protected readonly copy = HILOS_UPLOAD_FIELD_COPY
  protected readonly disabled = signal(false)

  constructor() {
    effect((onCleanup) => {
      onCleanup(mirrorHilosSignal(this.field().disabled, this.disabled))
    })
  }

  protected picked(event: Event): void {
    const target = event.target as HTMLInputElement
    this.field().add(target.files ?? [])
    target.value = ''
  }
}
