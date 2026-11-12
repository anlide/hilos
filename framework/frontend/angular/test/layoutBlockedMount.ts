import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type {
  LayoutBlockedInput,
  LayoutBlockedView,
} from '../../test-support/layoutBlockedLiveCases.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** A real eager page, created and destroyed by HilosView's component outlet. */
@Component({
  standalone: true,
  imports: [HilosModal],
  template: `
    <div data-id="private-content">
      <h1>Profile</h1>
      <button type="button" data-id="open-draft" (click)="open.set(true)">
        Edit
      </button>
      <hilos-modal
        title="Edit phone"
        [open]="open()"
        [confirmOnClose]="true"
        (openChange)="open.set($event)"
      >
        <input data-id="draft" data-autofocus="" aria-label="Phone number" />
      </hilos-modal>
    </div>
  `,
})
class PrivatePage {
  readonly open = signal(false)
}

@Component({
  standalone: true,
  template: `<form data-id="test-sign-in"><h1>Sign in</h1></form>`,
})
class AuthSurface {}

@Component({
  standalone: true,
  imports: [HilosLayout, HilosPageTemplate, HilosView],
  template: `
    <hilos-layout [connection]="connection">
      <ng-template hilosPage>
        <hilos-view [pages]="pages" [authSurface]="authSurface" />
      </ng-template>
    </hilos-layout>
  `,
})
class LayoutBlockedHost {
  connection!: LayoutBlockedInput['connection']
  readonly pages = { main: PrivatePage }
  readonly authSurface = AuthSurface
}

/** Provide before construction and retain the fixture before first detection. */
export async function mountLayoutBlocked(
  input: LayoutBlockedInput,
): Promise<LayoutBlockedView> {
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<LayoutBlockedHost> | undefined
  let destroyed = false
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      fixture?.destroy()
    } finally {
      try {
        TestBed.resetTestingModule()
      } finally {
        host.remove()
      }
    }
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    fixture!.detectChanges()
    await Promise.resolve()
    fixture!.detectChanges()
    await fixture!.whenStable()
    fixture!.detectChanges()
  }
  try {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
    })
    fixture = TestBed.createComponent(LayoutBlockedHost)
    const nativeDestroy = fixture.destroy.bind(fixture)
    let fixtureDestroyed = false
    fixture.destroy = () => {
      if (fixtureDestroyed) return
      fixtureDestroyed = true
      nativeDestroy()
    }
    fixture.componentInstance.connection = input.connection
    host.appendChild(fixture.nativeElement as HTMLElement)
    await flush()
    return { root: host, flush, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (releaseError) {
      throw new AggregateError([error, releaseError], undefined, {
        cause: releaseError,
      })
    }
    throw error
  }
}
