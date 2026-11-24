import { NgTemplateOutlet } from '@angular/common'
import { Component as View } from '@angular/core'
import {
  HilosModal as Dialog,
  type HilosTrackedAction,
  HilosViewportTable as Grid,
} from '@hilos/angular/base'

import { AngularPanel as Panel, PageOnly } from './angularPanel.js'
import { AngularShell as Shell } from './angularShell.js'

@View({
  selector: 'test-host',
  imports: [Dialog, Grid, Panel, PageOnly, Shell, NgTemplateOutlet],
  template: `
    <test-panel />
    <page-only />
    <ng-template #actions><test-panel /></ng-template>
    <ng-template #unused><p class="invisible">{{ action.error() }}</p></ng-template>
    <hilos-modal [open]="true">
      <ng-template #modalActions>
        <ng-container *ngTemplateOutlet="actions" />
      </ng-template>
      <ng-template #modalBody>
        <test-panel />
        <hilos-viewport-table>
          <ng-template hilosTableCell="name">
            <p class="invisible">{{ action.error() }}</p>
            <hilos-modal [open]="true">
              <p class="invisible">{{ action.error() }}</p>
            </hilos-modal>
          </ng-template>
        </hilos-viewport-table>
      </ng-template>
    </hilos-modal>
    <test-shell>
      <ng-template #content>
        <p class="invisible">{{ action.error() }}</p>
      </ng-template>
    </test-shell>
  `,
})
export class AngularHost {
  declare action: HilosTrackedAction
}
