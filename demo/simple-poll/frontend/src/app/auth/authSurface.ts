// The poll demo's sign-in surface: the framework default (@hilos/angular
// HilosAuthSurface) with this project's context closed over it. HilosView mounts
// the surface through ngComponentOutlet, which passes no inputs, so the context
// cannot reach it from outside — this wrapper is what closes it, the same way
// Settings mounts the framework settings page. The framework owns the machine,
// the wire, the screens and the copy; the project owns only what
// hilosAuthContext declares.
//
// A component and not a function for the same reason: what HilosView takes is a
// component type it instantiates itself.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAuthSurface } from '@hilos/angular'

import { hilosAuthContext } from './hilosAuthContext'

@Component({
  selector: 'app-auth-surface',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAuthSurface],
  template: `<hilos-auth-surface [context]="context" />`,
})
export class AuthSurface {
  protected readonly context = hilosAuthContext
}
