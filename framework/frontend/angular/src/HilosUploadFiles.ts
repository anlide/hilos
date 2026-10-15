// HilosUploadFiles — the chips of the upload field's files and the line of its
// last refusal above them. With no files it draws nothing and takes no room;
// from the first file to the last the refusal's room is held by
// HilosFormError's invisible twin, so a refusal moves nothing (styling-rules.md,
// "The room a live message takes"). The line carries no voice: HilosUploadDrop
// says it aloud, and an announcement here would be heard twice.
// A chip's reading lives on data-state, its icon and tone show it, and a hidden
// word says it — an icon and a color reach no ear.
import {
  HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS,
  HILOS_UPLOAD_FIELD_COPY,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'

import { HilosFormError } from './HilosFormError.js'
import { mirrorHilosSignal } from './hilosSignal.js'

/** The chips of an upload field and the room of its refusal line. */
@Component({
  selector: 'hilos-upload-files',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError],
  template: `
    @if (files().length > 0) {
      <div class="mb-2" [attr.data-id]="dataId()">
        <hilos-form-error
          [message]="refusal()"
          [dataId]="dataId() + '-error'"
        />
        <div class="d-flex flex-wrap gap-2">
          @for (file of files(); track file.clientUploadId) {
            <span
              [class]="chipClass(file)"
              [attr.data-id]="dataId() + '-file'"
              [attr.data-state]="file.reading"
            >
              @switch (file.reading) {
                @case ('waiting') {
                  <i class="bi bi-clock" aria-hidden="true"></i>
                  <span class="visually-hidden">{{ copy.waiting }}</span>
                }
                @case ('uploading') {
                  <span class="visually-hidden">{{ copy.uploading }}</span>
                  <span class="hilos-upload-percent d-inline-block text-end"
                    >{{ file.percent }}%</span
                  >
                }
                @case ('received') {
                  <i class="bi bi-paperclip" aria-hidden="true"></i>
                }
                @default {
                  <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                  <span class="visually-hidden">{{ copy.failed }}</span>
                }
              }
              <span
                class="text-truncate hilos-upload-name"
                [title]="file.filename"
                [attr.data-id]="dataId() + '-file-name'"
                >{{ file.filename }}</span
              >
              <button
                type="button"
                class="btn-close hilos-upload-remove"
                [attr.aria-label]="copy.remove + ' ' + file.filename"
                [disabled]="disabled() || file.canceling"
                [attr.data-id]="dataId() + '-file-remove'"
                (click)="field().remove(file.clientUploadId)"
              ></button>
            </span>
          }
        </div>
      </div>
    }
  `,
})
export class HilosUploadFiles {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field = input.required<HilosUploadField>()
  /** The data-id of the block; the refusal line and the chips derive theirs from it. */
  readonly dataId = input.required<string>()
  protected readonly copy = HILOS_UPLOAD_FIELD_COPY
  protected readonly files = signal<readonly HilosUploadFieldFile[]>([])
  protected readonly refusal = signal<string | null>(null)
  protected readonly disabled = signal(false)

  constructor() {
    effect((onCleanup) => {
      const field = this.field()
      const stops = [
        mirrorHilosSignal(field.files, this.files),
        mirrorHilosSignal(field.refusal, this.refusal),
        mirrorHilosSignal(field.disabled, this.disabled),
      ]
      onCleanup(() => stops.forEach((stop) => stop()))
    })
  }

  protected chipClass(file: HilosUploadFieldFile): string {
    const tone =
      file.reading === 'failed'
        ? HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS
        : HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS

    return file.canceling
      ? `${HILOS_UPLOAD_FIELD_CHIP_CLASS} ${tone} ${HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS}`
      : `${HILOS_UPLOAD_FIELD_CHIP_CLASS} ${tone}`
  }
}
