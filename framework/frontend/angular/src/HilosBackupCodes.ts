// HilosBackupCodes — a set of backup codes of the second factor shown once, the
// way the design asks (HIL-494, Design п.6): the list, a download of it as a text
// file, a copy of it, and "I have saved these codes", which the screen under it
// waits for. The codes are the only instant way back in when the app is lost, so
// the screen pushes the person to keep them rather than flash them past.
import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
  signal,
} from '@angular/core'
import {
  copyToClipboard,
  downloadTextFile,
  isClipboardAvailable,
} from '@hilos/core'

/** The name the downloaded list is offered under. */
const FILE_NAME = 'backup-codes.txt'

let backupCodesSeq = 0

/** A set of backup codes with the ways to keep them. */
@Component({
  selector: 'hilos-backup-codes',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div data-id="backup-codes">
      <ul
        class="list-unstyled row row-cols-2 g-2 font-monospace mb-3"
        data-id="backup-codes-list"
      >
        @for (code of codes(); track code) {
          <li class="col text-center">
            <span class="d-block border rounded py-1">{{ code }}</span>
          </li>
        }
      </ul>
      <div class="d-flex gap-2 mb-3">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm flex-fill"
          data-id="backup-codes-download"
          (click)="download()"
        >
          <i class="bi bi-download me-1" aria-hidden="true"></i>
          Download
        </button>
        @if (canCopy) {
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm flex-fill"
            data-id="backup-codes-copy"
            (click)="copy()"
          >
            <i
              class="bi me-1"
              [class.bi-check2]="copied()"
              [class.bi-clipboard]="!copied()"
              aria-hidden="true"
            ></i>
            {{ copied() ? 'Copied' : 'Copy' }}
          </button>
        }
      </div>
      <div class="form-check mb-3">
        <input
          [id]="savedId"
          class="form-check-input"
          type="checkbox"
          data-id="backup-codes-saved"
          [checked]="saved()"
          (change)="updateSaved($event)"
        />
        <label class="form-check-label small" [attr.for]="savedId">
          I have saved these codes
        </label>
      </div>
    </div>
  `,
})
export class HilosBackupCodes {
  /** The codes, in display form. */
  readonly codes = input.required<readonly string[]>()
  /** Whether "I have saved these codes" is ticked. */
  readonly saved = input.required<boolean>()
  /** The checkbox moved. */
  readonly savedChange = output<boolean>()

  /** The checkbox's id, unique per instance so its label finds it. */
  protected readonly savedId = `hilos-backup-codes-saved-${++backupCodesSeq}`
  protected readonly canCopy = isClipboardAvailable()
  protected readonly copied = signal(false)

  /** The list as a file and as the clipboard hold it: one code a line. */
  private asText(): string {
    return `${this.codes().join('\n')}\n`
  }

  protected download(): void {
    downloadTextFile(FILE_NAME, this.asText(), 'text/plain;charset=utf-8')
  }

  protected async copy(): Promise<void> {
    this.copied.set(await copyToClipboard(this.asText()))
  }

  /**
   * Hand the checkbox to the owner.
   *
   * @param event The change event.
   */
  protected updateSaved(event: Event): void {
    this.savedChange.emit((event.target as HTMLInputElement).checked)
  }
}
