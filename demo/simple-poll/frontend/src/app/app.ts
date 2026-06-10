import { Component } from '@angular/core'

// Blank root view — the step 5.2 conformance shell: an Angular app that builds
// and serves through the docker stack. Real views land on top of this from
// step 7, tracking each core capability as it lands.
@Component({
  selector: 'app-root',
  template: '<main data-id="app-root">Hilos simple-poll (Angular)</main>',
})
export class App {}
