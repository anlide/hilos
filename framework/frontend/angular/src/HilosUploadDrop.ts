// HilosUploadDrop — the wrapper of a project's own content that takes files
// dragged over it or pasted into it, and the voice of the upload field. The
// field (@hilos/core primitives/uploadField) owns the state; this tag only feeds
// it the two doors and draws what it says (multiframework-core.md,
// "Primitives").
// A drag that carries no files — selected text, a link — is not touched at all,
// and a paste without files goes on as an ordinary paste. A file drag is always
// taken, even while the field is disabled, so the browser never opens the file
// in place of the page; the field itself then refuses it.
// The voice lives here and not on the chips: the chips come and go, and a live
// region dies with its block, so the two permanent regions stand on the one
// surface that outlives them (accessibility.md, "The room belongs to the block,
// the voice to the surface").
import {
  HILOS_UPLOAD_FIELD_COPY,
  HILOS_UPLOAD_FIELD_OVERLAY_CLASS,
  type HilosUploadField,
} from '@hilos/core'
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'

import { mirrorHilosSignal } from './hilosSignal.js'

function carriesFiles(event: DragEvent): boolean {
  return event.dataTransfer?.types.includes('Files') ?? false
}

/** The drop and paste door of an upload field, wrapped around the project's content. */
@Component({
  selector: 'hilos-upload-drop',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="position-relative"
      [attr.data-id]="dataId()"
      (dragenter)="enter($event)"
      (dragover)="over($event)"
      (dragleave)="leave($event)"
      (drop)="dropped($event)"
      (paste)="pasted($event)"
    >
      <ng-content />
      @if (dragging()) {
        <div [class]="overlayClass" [attr.data-id]="dataId() + '-overlay'">
          <span class="text-primary fw-semibold"
            ><i class="bi bi-paperclip me-1" aria-hidden="true"></i
            >{{ copy.overlay }}</span
          >
        </div>
      }
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        [attr.data-id]="dataId() + '-alert'"
      >
        {{ alert() }}
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        [attr.data-id]="dataId() + '-status'"
      >
        {{ status() }}
      </div>
    </div>
  `,
})
export class HilosUploadDrop {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field = input.required<HilosUploadField>()
  /** The data-id of the wrapper; the overlay and the two live regions derive theirs from it. */
  readonly dataId = input.required<string>()
  protected readonly copy = HILOS_UPLOAD_FIELD_COPY
  protected readonly overlayClass = `${HILOS_UPLOAD_FIELD_OVERLAY_CLASS} hilos-upload-overlay`
  protected readonly dragging = signal(false)
  protected readonly alert = signal('')
  protected readonly status = signal('')

  constructor() {
    effect((onCleanup) => {
      const field = this.field()
      const stops = [
        mirrorHilosSignal(field.dragging, this.dragging),
        mirrorHilosSignal(field.alert, this.alert),
        mirrorHilosSignal(field.status, this.status),
      ]
      onCleanup(() => stops.forEach((stop) => stop()))
    })
  }

  protected enter(event: DragEvent): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    this.field().dragEnter()
  }

  protected over(event: DragEvent): void {
    if (carriesFiles(event)) {
      event.preventDefault()
    }
  }

  protected leave(event: DragEvent): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    this.field().dragLeave()
  }

  protected dropped(event: DragEvent): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    this.field().drop(event.dataTransfer?.files ?? [])
  }

  protected pasted(event: ClipboardEvent): void {
    const files = event.clipboardData?.files
    if (!files || files.length === 0) {
      return
    }
    event.preventDefault()
    this.field().add(files)
  }
}
