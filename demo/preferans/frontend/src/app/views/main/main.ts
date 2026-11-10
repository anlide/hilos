import { ChangeDetectionStrategy, Component } from '@angular/core'

@Component({
  selector: 'app-main',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<h1 class="visually-hidden">Preferans</h1>`,
})
export class Main {}
