import { Component } from '@angular/core'

export const example = `<div data-id="hilos-table-live-slot"></div>`
export const sampleMetadata = {
  template: '<div data-id="hilos-table-live-idle"></div>',
}

@Component({
  template: `
    <!-- <div data-id="hilos-table-live-slot"></div> -->
    <section>
      <div data-id="hilos-table-live-slot"></div>
      <hilos-modal>
        @if (ready) {
          <hilos-viewport-table />
          <div [attr.data-id]="'hilos-table-live-idle'"></div>
        }
      </hilos-modal>
    </section>
  `,
})
export class Screen {}
