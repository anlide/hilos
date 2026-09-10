// HilosPrivacyEraseModal — the confirmation the erase on /privacy asks for: one
// question, two answers, no fields, the dangerous answer in red, the shape the
// component mockup draws for a destructive confirmation.
//
// Its body is the LIST of what goes, one line per registry entry, taken from each
// entry's own label — that is what makes the click worth asking for: the person
// agrees to a list rather than to an adjective, and the list is right by
// construction because it IS the registry. Under it the three sentences of the
// boundary: this device only, irreversible, and the account untouched.
//
// Not exported from the package. It is this page's own confirmation and has no
// second caller. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core'

import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'

/** The destructive confirmation of the erase block. */
@Component({
  selector: 'hilos-privacy-erase-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="openChange.emit($event)"
      title="Erase everything this browser keeps?"
      [closeOnBackdrop]="!busy()"
      [closeOnEsc]="!busy()"
    >
      <p class="mb-2">This will remove from this browser:</p>
      <ul class="mb-3 ps-3" data-id="privacy-erase-list">
        @for (label of labels(); track label) {
          <li class="small">{{ label }}</li>
        }
      </ul>
      <p class="mb-2 small text-body-secondary">
        Only this device is affected, and only this browser on it.
      </p>
      <p class="mb-2 small text-body-secondary">
        It cannot be undone: nothing here is kept anywhere to put back.
      </p>
      <p class="mb-0 small text-body-secondary">
        Your account and everything in it are untouched — this is not account
        deletion.
      </p>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="privacy-erase-cancel"
          (click)="requestClose()"
        >
          Cancel
        </button>
        <button
          hilosLoadingButton
          type="button"
          class="btn btn-danger"
          [loading]="loading()"
          data-id="privacy-erase-confirm"
          (click)="confirm.emit()"
        >
          Erase and sign out
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosPrivacyEraseModal {
  /** Whether the confirmation is open. */
  readonly open = input(false)

  /** One line per registry entry: what this erase would take. */
  readonly labels = input<readonly string[]>([])

  /** The erase is in flight: the answers are held and the dialog cannot be dismissed. */
  readonly busy = input(false)

  /** Deferred loading of the erase, for the confirming button's spinner. */
  readonly loading = input(false)

  /** Raised when the dialog opens or closes itself. */
  readonly openChange = output<boolean>()

  /** Raised when the dangerous answer is taken. */
  readonly confirm = output<void>()
}
