import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type {
  LayoutChromeInput,
  LayoutChromeView,
} from '../../test-support/layoutChromeFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Ordinary bound fields supply required connection and native projected content. */
@Component({
  imports: [HilosLayout, HilosPageTemplate, LoadingButton],
  template: `
    <hilos-layout [connection]="connection()!">
      <ng-container ngProjectAs="[nav]">
        @if (nav()) {
          <a href="/hidden" hidden data-id="project-nav-hidden">Hidden</a>
          <a href="/first" data-id="project-nav-first">First</a>
          <a href="/second" data-id="project-nav-second">Second</a>
        }
      </ng-container>
      @if (banner(); as content) {
        <p banner [attr.data-id]="content.dataId">{{ content.text }}</p>
      }
      <ng-container ngProjectAs="[user]">
        @if (projectUserControls()) {
          <span data-id="user-bell">Bell</span>
          <span data-id="user-avatar">Avatar</span>
          <a href="/signin" data-id="user-signin">Sign in</a>
        } @else if (user(); as content) {
          <span user [attr.data-id]="content.dataId">{{ content.text }}</span>
        }
      </ng-container>
      <ng-template hilosPage>
        @if (pageAction()) {
          <button hilosLoadingButton data-id="page-action">Send</button>
        } @else {
          <p data-id="page-body">Page</p>
        }
      </ng-template>
    </hilos-layout>
  `,
})
class LayoutChromeHost {
  connection = signal<LayoutChromeInput['connection'] | undefined>(undefined)
  banner = signal<LayoutChromeInput['banner']>(undefined)
  user = signal<LayoutChromeInput['user']>(undefined)
  projectUserControls = signal(false)
  nav = signal(false)
  pageAction = signal(false)
}

/** Bind before detection and destroy native effects before removing their host. */
export async function mountLayoutChrome(
  input: LayoutChromeInput,
  retain?: (view: LayoutChromeView) => void,
): Promise<LayoutChromeView> {
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<LayoutChromeHost> | undefined
  let destroyed = false
  function destroy(): void {
    if (destroyed) return
    destroyed = true
    const errors: unknown[] = []
    for (const release of [
      () => fixture?.destroy(),
      () => TestBed.resetTestingModule(),
      () => host.remove(),
    ]) {
      try {
        release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Layout unmount failed')
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    fixture!.detectChanges()
    await Promise.resolve()
    fixture!.detectChanges()
    await fixture!.whenStable()
    fixture!.detectChanges()
  }
  function update(patch: Partial<LayoutChromeInput>): void {
    if (patch.connection !== undefined)
      fixture!.componentInstance.connection.set(patch.connection)
    if ('banner' in patch) fixture!.componentInstance.banner.set(patch.banner)
    if ('user' in patch) fixture!.componentInstance.user.set(patch.user)
    if (patch.projectUserControls !== undefined)
      fixture!.componentInstance.projectUserControls.set(
        patch.projectUserControls,
      )
    if (patch.nav !== undefined) fixture!.componentInstance.nav.set(patch.nav)
    if (patch.pageAction !== undefined)
      fixture!.componentInstance.pageAction.set(patch.pageAction)
  }
  const view: LayoutChromeView = {
    root: host,
    async update(patch) {
      await flush(() => update(patch))
    },
    flush,
    async unmount() {
      destroy()
    },
  }
  try {
    retain?.(view)
    if (input.router !== undefined) {
      TestBed.configureTestingModule({
        providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
      })
    }
    fixture = TestBed.createComponent(LayoutChromeHost)
    const nativeDestroy = fixture.destroy.bind(fixture)
    let fixtureDestroyed = false
    fixture.destroy = () => {
      if (fixtureDestroyed) return
      fixtureDestroyed = true
      nativeDestroy()
    }
    update(input)
    host.appendChild(fixture.nativeElement as HTMLElement)
    await flush()
    return view
  } catch (error) {
    try {
      destroy()
    } catch (cleanupError) {
      throw new AggregateError([error, cleanupError], 'Layout mount failed', {
        cause: cleanupError,
      })
    }
    throw error
  }
}
