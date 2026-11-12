import { Component, inject, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HilosPages } from '@hilos/core/base'
import type {
  LayoutPublicStatusInput,
  LayoutPublicStatusView,
} from '../../test-support/layoutPublicStatusFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { HilosStatusPage } from '../src/public/HilosStatusPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

@Component({
  imports: [HilosStatusPage],
  template: `<hilos-public-status-page
    [connection]="host.input.connection"
    [actions]="host.input.actions"
  />`,
})
class PublicStatusRoute {
  protected readonly host = inject(PublicStatusShellHost)
}

@Component({ template: `<p data-id="ordinary-page">Ordinary</p>` })
class OrdinaryPage {}

@Component({ template: `<form data-id="test-sign-in"></form>` })
class AuthSurface {}

@Component({
  imports: [HilosLayout, HilosModal, HilosPageTemplate, HilosView],
  template: `
    <hilos-layout
      [connection]="input.connection"
      [allowDisconnectedInteraction]="allow()"
    >
      <span brand>{{ input.brand ?? 'Brand' }}</span>
      <hilos-modal
        user
        [open]="modalOpen()"
        aria-label="Protected action"
        initialFocus="dialog"
      >
        <button
          type="button"
          data-id="shell-dialog-action"
          (click)="input.onSave?.()"
        >
          Save
        </button>
      </hilos-modal>
      <ng-template hilosPage>
        <hilos-view [pages]="pages" [authSurface]="authSurface" />
      </ng-template>
    </hilos-layout>
  `,
})
class PublicStatusShellHost {
  input!: LayoutPublicStatusInput
  readonly allow = signal(false)
  readonly modalOpen = signal(false)
  readonly pages = {
    [HilosPages.PUBLIC_STATUS]: PublicStatusRoute,
    [HilosPages.ABOUT]: OrdinaryPage,
    [HilosPages.TERMS]: OrdinaryPage,
    [HilosPages.STATUS]: OrdinaryPage,
    main: OrdinaryPage,
    ordinary: OrdinaryPage,
  }
  readonly authSurface = AuthSurface
}

/** TemplateRef owns the outlet; the router is installed before component creation. */
export async function mountLayoutPublicStatus(
  input: LayoutPublicStatusInput,
): Promise<LayoutPublicStatusView> {
  const host = document.createElement('div')
  const native: { fixture?: ComponentFixture<PublicStatusShellHost> } = {}
  const update: LayoutPublicStatusView['update'] = (props) => {
    const component = native.fixture!.componentInstance
    if (props.allow !== undefined) component.allow.set(props.allow)
    if (props.modalOpen !== undefined) component.modalOpen.set(props.modalOpen)
  }
  async function flush(run?: () => void | Promise<void>): Promise<void> {
    await run?.()
    native.fixture!.detectChanges()
    await Promise.resolve()
    native.fixture!.detectChanges()
  }
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    const errors: unknown[] = []
    try {
      native.fixture?.destroy()
    } catch (error) {
      errors.push(error)
    }
    try {
      TestBed.resetTestingModule()
    } catch (error) {
      errors.push(error)
    }
    try {
      await Promise.resolve()
    } finally {
      host.remove()
    }
    if (errors.length)
      throw new AggregateError(errors, 'Angular public Status disposal failed')
  }
  const view = { root: host, update, flush, unmount }
  input.retain(view)
  document.body.appendChild(host)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
  })
  const fixture = TestBed.createComponent(PublicStatusShellHost)
  native.fixture = fixture
  // TestBed retains the fixture for its own reset; dispose this owner once.
  const destroy = fixture.destroy.bind(fixture)
  let destroyed = false
  fixture.destroy = () => {
    if (destroyed) return
    destroyed = true
    destroy()
  }
  fixture.componentInstance.input = input
  update(input)
  host.appendChild(fixture.nativeElement as HTMLElement)
  await flush()
  return view
}
