import { NgTemplateOutlet } from '@angular/common'
import {
  Component,
  contentChild as query,
  type TemplateRef,
} from '@angular/core'
import { HilosModal } from '@hilos/angular/base'

@Component({
  selector: 'test-shell',
  imports: [HilosModal, NgTemplateOutlet],
  template: `
    <hilos-modal>
      @if (body(); as template) {
        <ng-container [ngTemplateOutlet]="template" />
      }
    </hilos-modal>
  `,
})
export class AngularShell {
  protected readonly body = query<TemplateRef<unknown>>('content')
}
