import { Component, DestroyRef, inject, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { vi } from 'vitest'
import {
  layoutLifetimeFixture,
  type LayoutLifetimeOptions,
  type LayoutLifetimeView,
} from '../../test-support/layoutLifetimeFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Constructor and DestroyRef receipts belong to the actual embedded view. */
@Component({
  selector: 'test-page-lifetime',
  template: `
    <div data-id="lifetime-page">
      <input
        data-id="lifetime-draft"
        aria-label="Local draft"
        [value]="draft()"
        (input)="draft.set($any($event.target).value)"
      />
    </div>
  `,
})
class PageLifetimeProbe {
  readonly draft = signal('')
  readonly destroyed = vi.fn()
  readonly router = inject(HILOS_ROUTER)

  constructor() {
    const host = inject(PageLifetimeHost)
    host.instances.push(this)
    host.scope.events.push({ phase: 'start', instance: this })
    inject(DestroyRef).onDestroy(this.destroyed)
    inject(DestroyRef).onDestroy(() => {
      host.scope.events.push({ phase: 'stop', instance: this })
    })
  }
}

@Component({
  imports: [HilosLayout, HilosPageTemplate, PageLifetimeProbe],
  template: `
    <hilos-layout [connection]="scope.feed.connection">
      <span brand data-id="lifetime-brand">Brand</span>
      <span nav data-id="lifetime-nav">Navigation</span>
      <span user data-id="lifetime-user">Person</span>
      <span banner data-id="lifetime-banner">Notice</span>
      <ng-template hilosPage>
        <test-page-lifetime />
      </ng-template>
    </hilos-layout>
  `,
})
class PageLifetimeHost {
  scope!: ReturnType<typeof layoutLifetimeFixture>
  readonly instances: PageLifetimeProbe[] = []
}

/** Readonly native handles let the local entry retain Angular-only API proof. */
export interface AngularLayoutLifetimeView extends LayoutLifetimeView {
  readonly instances: readonly PageLifetimeProbe[]
  readonly router: ReturnType<typeof layoutLifetimeFixture>['router']
}

/** Bind the declaration's injector and connection before its first detection. */
export async function mountLayoutLifetime(
  options: LayoutLifetimeOptions,
): Promise<AngularLayoutLifetimeView> {
  const scope = layoutLifetimeFixture(options)
  const host = document.createElement('div')
  let fixture: ComponentFixture<PageLifetimeHost> | undefined
  async function flush(run?: () => void): Promise<void> {
    run?.()
    fixture!.detectChanges()
    await Promise.resolve()
    fixture!.detectChanges()
    await fixture!.whenStable()
    fixture!.detectChanges()
  }
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    try {
      fixture?.destroy()
    } finally {
      try {
        TestBed.resetTestingModule()
      } finally {
        host.remove()
        await scope.dispose()
      }
    }
  }
  try {
    document.body.appendChild(host)
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: scope.router }],
    })
    fixture = TestBed.createComponent(PageLifetimeHost)
    const nativeDestroy = fixture.destroy.bind(fixture)
    let fixtureDestroyed = false
    fixture.destroy = () => {
      if (fixtureDestroyed) return
      fixtureDestroyed = true
      nativeDestroy()
    }
    fixture.componentInstance.scope = scope
    host.appendChild(fixture.nativeElement as HTMLElement)
    await flush()
    return {
      root: host,
      events: scope.events,
      feed: scope.feed,
      router: scope.router,
      instances: fixture.componentInstance.instances,
      flush,
      unmount,
    }
  } catch (error) {
    await unmount()
    throw error
  }
}
