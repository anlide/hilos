import { Component } from '@angular/core'
import type { HilosTrackedAction } from '@hilos/angular/base'

@Component({
  selector: 'test-panel',
  templateUrl: './angularPanel.html',
})
export class AngularPanel {
  declare action: HilosTrackedAction
}

@Component({
  selector: 'page-only',
  template: '<p class="invisible">{{ action.error() }}</p>',
})
export class PageOnly {
  declare action: HilosTrackedAction
}
