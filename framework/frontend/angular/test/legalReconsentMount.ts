import type { OutputRefSubscription } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  releaseLegalReconsent,
  type LegalReconsentView,
  type MountLegalReconsent,
} from '../../test-support/legalReconsentPreviewCases.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { HilosLegalReconsent } from '../src/legal/HilosLegalReconsent.js'

/** Own the fixture before inputs or detection and reset its module on every exit. */
export const mountLegalReconsent: MountLegalReconsent = async (
  input,
  options,
) => {
  const host = document.createElement('div')
  const native: { fixture?: ComponentFixture<HilosLegalReconsent> } = {}
  const subscriptions: OutputRefSubscription[] = []
  let disposed = false
  const view: LegalReconsentView = {
    root: host,
    async update(input) {
      for (const [name, value] of Object.entries(input)) {
        native.fixture!.componentRef.setInput(name, value)
      }
      native.fixture!.detectChanges()
    },
    async interact(action) {
      action()
      native.fixture!.detectChanges()
    },
    async unmount() {
      if (disposed) return
      disposed = true
      await releaseLegalReconsent([
        ...subscriptions.map(
          (subscription) => () => subscription.unsubscribe(),
        ),
        () => native.fixture?.destroy(),
        () => {
          TestBed.resetTestingModule()
        },
        () => host.remove(),
      ])
    },
  }
  try {
    document.body.appendChild(host)
    await TestBed.configureTestingModule({
      imports: [HilosLegalReconsent],
      providers: options?.router
        ? [{ provide: HILOS_ROUTER, useValue: options.router }]
        : [],
    }).compileComponents()
    native.fixture = TestBed.createComponent(HilosLegalReconsent)
    const destroy = native.fixture.destroy.bind(native.fixture)
    let fixtureDestroyed = false
    native.fixture.destroy = () => {
      if (fixtureDestroyed) return
      fixtureDestroyed = true
      destroy()
    }
    const { onShow, onLater, onAccept, onRetry, ...props } = input
    const component = native.fixture.componentInstance
    if (onShow) subscriptions.push(component.show.subscribe(onShow))
    if (onLater) subscriptions.push(component.later.subscribe(() => onLater()))
    if (onAccept)
      subscriptions.push(component.accept.subscribe(() => onAccept()))
    if (onRetry) subscriptions.push(component.retry.subscribe(() => onRetry()))
    host.appendChild(native.fixture.nativeElement as HTMLElement)
    for (const [name, value] of Object.entries(props)) {
      native.fixture.componentRef.setInput(name, value)
    }
    native.fixture.detectChanges()
    return view
  } catch (error) {
    try {
      await view.unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular legal re-consent setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
