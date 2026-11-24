import { Component as View } from '@angular/core'
import {
  HilosActionError,
  type HilosTrackedAction,
  HilosFormError,
  HilosModal as LocalDialog,
} from '@hilos/angular/base'

@View({
  selector: 'test-direct',
  imports: [LocalDialog, HilosFormError, HilosActionError],
  template: `
    <hilos-modal initialFocus="dialog">
      <div data-id="save-refusal-slot">
        <div class="opacity-0" data-id="save-refusal-twin" aria-hidden="true">
          &nbsp;
        </div>
        @if (action.error()) {
          <div data-id="save-refusal">{{ refusal }}</div>
        }
      </div>

      <ng-template #modalActions>
        @if (action.error()) {
          <p>Changes could not be saved.</p>
        } @else {
          <div [style.min-height.px]="32" aria-hidden="true"></div>
        }
      </ng-template>

      <div [class.invisible]="!action.error()">{{ refusal }}</div>

      <div class="invisible" data-id="shared-error-wrapper">
        Save error
        <hilos-form-error [message]="refusal" />
      </div>
      <hilos-action-error />
      <div class="invisible" role="status">Waiting for a response.</div>
      @if (action.error()) {
        <p>You cannot continue.</p>
      }

      <!-- <p class="invisible">Changes could not be saved.</p> -->
    </hilos-modal>
    <div class="invisible alert-danger">{{ action.error() }}</div>
  `,
})
export class Direct {
  declare action: HilosTrackedAction
  refusal = ''
}

export const example =
  '<hilos-modal><p class="invisible alert-danger">Error</p></hilos-modal>'
