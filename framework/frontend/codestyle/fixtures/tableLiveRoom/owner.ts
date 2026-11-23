import { Component } from '@angular/core'
const ROW_CLASS = 'alert d-flex'
@Component({
  template: `
    <div
      class="mb-2 position-relative"
      [class.bg-body-tertiary]="null === live().top"
      [class.rounded]="!live().top"
      data-id="hilos-table-live-slot"
    >
      <div
        [class]="spacerClass"
        aria-hidden="true"
        data-id="hilos-table-live-idle"
      >
        <i class="bi bi-info-circle"></i>
        <span class="btn btn-outline-secondary">&nbsp;</span>
      </div>
      @if (live().top; as top) {
        <div class="border spinner-border"><button>Details</button></div>
      } @else {
        <!-- Nothing to announce. -->
      }
      <hilos-modal><button class="border">Close</button></hilos-modal>
      <span class="visually-hidden" role="status">{{ announcement() }}</span>
    </div>
  `,
})
export class HilosTableLive {
  protected readonly spacerClass = `${ROW_CLASS} invisible alert-secondary`
}
