import { Component } from '@angular/core'
import { HilosTableLive as LiveRoom } from './HilosTableLive.js'

@Component({
  imports: [LiveRoom],
  template: `
    <section>
      <!-- <hilos-table-live /> -->
      <hilos-table-live />
      <div class="table-responsive"><table></table></div>
      <div data-id="hilos-table-cards"></div>
    </section>
  `,
})
export class HilosViewportTable {}
