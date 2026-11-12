import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import { vi } from 'vitest'
import type {
  LayoutConnectionInput,
  LayoutConnectionStandalone,
  LayoutConnectionView,
} from '../../test-support/layoutConnectionFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { injectConnectionInteraction } from '../src/hilosConnectionInteraction.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

@Component({ selector: 'test-connection-probe', template: '' })
class ConnectionProbe {
  readonly interaction = injectConnectionInteraction()
}

@Component({
  selector: 'test-connection-viewing-action',
  imports: [LoadingButton],
  providers: [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(true).asReadonly() },
  ],
  template:
    '<button hilosLoadingButton data-id="page-view" aria-describedby="validation">View</button>',
})
class ViewingAction {}

/** Real TemplateRef projection receives the shell's live context provider. */
@Component({
  imports: [
    HilosLayout,
    HilosModal,
    HilosPageTemplate,
    LoadingButton,
    ConnectionProbe,
    ViewingAction,
  ],
  template: `
    <hilos-layout
      [connection]="connection()!"
      [allowDisconnectedInteraction]="allow()"
    >
      <a nav href="/next">Next</a>
      <span user data-id="connection-shell-consumer"
        ><test-connection-probe
      /></span>
      <ng-template hilosPage>
        <span data-id="connection-page-consumer"
          ><test-connection-probe
        /></span>
        <test-connection-viewing-action />
        <input
          data-id="draft"
          [attr.aria-describedby]="description()"
          [disabled]="disabled()"
        />
        <input type="file" data-id="file" />
        <select data-id="choice">
          <option value="a">A</option>
          <option value="b">B</option>
        </select>
        <button
          hilosLoadingButton
          [opensWindow]="true"
          data-id="page-open"
          (click)="clicks()"
        >
          Open
        </button>
        <a href="/action" data-id="page-link" (click)="clicks()">Action</a>
        <hilos-modal
          title="Edit draft"
          [open]="modalOpen()"
          [confirmOnClose]="true"
        >
          <div data-id="connection-modal-body">
            <test-connection-probe />
            <input data-id="modal-initial" data-autofocus />
            <input data-id="modal-draft" />
          </div>
        </hilos-modal>
      </ng-template>
    </hilos-layout>
  `,
})
class ConnectionHost {
  readonly connection = signal<LayoutConnectionInput['connection'] | undefined>(
    undefined,
  )
  readonly allow = signal(false)
  readonly disabled = signal(false)
  readonly description = signal('validation')
  readonly modalOpen = signal(false)
  readonly clicks = vi.fn()
}

// TestBed is shared by simultaneous native roots; the last owner resets it.
let activeRoots = 0

/** Destroy one owned fixture without invalidating another live shell. */
function releaseFixture(
  fixture: ComponentFixture<unknown> | undefined,
  host: HTMLElement,
): void {
  const errors: unknown[] = []
  try {
    fixture?.destroy()
  } catch (error) {
    errors.push(error)
  } finally {
    if (fixture !== undefined) activeRoots--
  }
  try {
    if (activeRoots === 0) TestBed.resetTestingModule()
  } catch (error) {
    errors.push(error)
  } finally {
    host.remove()
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Angular connection cleanup failed')
}

/** Bind required inputs before detection and dispose the native fixture on every exit. */
export async function mountLayoutConnection(
  input: LayoutConnectionInput,
): Promise<LayoutConnectionView> {
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<ConnectionHost> | undefined
  let destroyed = false
  function destroy(): void {
    if (destroyed) return
    destroyed = true
    releaseFixture(fixture, host)
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    fixture!.detectChanges()
    await fixture!.whenStable()
    fixture!.detectChanges()
  }
  function update(patch: Partial<Omit<LayoutConnectionInput, 'router'>>): void {
    const component = fixture!.componentInstance
    if (patch.connection !== undefined)
      component.connection.set(patch.connection)
    if (patch.allow !== undefined) component.allow.set(patch.allow)
    if (patch.disabled !== undefined) component.disabled.set(patch.disabled)
    if (patch.description !== undefined)
      component.description.set(patch.description)
    if (patch.modalOpen !== undefined) component.modalOpen.set(patch.modalOpen)
  }
  try {
    if (input.router !== undefined)
      TestBed.configureTestingModule({
        providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
      })
    fixture = TestBed.createComponent(ConnectionHost)
    activeRoots++
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
    const consumer = (id: string): LayoutConnectionView['interaction'] =>
      (
        fixture!.debugElement
          .query(By.css(`[data-id="${id}"]`))
          .query(By.directive(ConnectionProbe))
          .componentInstance as ConnectionProbe
      ).interaction
    return {
      root: host,
      clicks: fixture.componentInstance.clicks,
      get interaction() {
        return consumer('connection-page-consumer')
      },
      get consumers() {
        return {
          page: consumer('connection-page-consumer'),
          shell: consumer('connection-shell-consumer'),
          modal: consumer('connection-modal-body'),
        }
      },
      async update(patch) {
        await flush(() => update(patch))
      },
      flush,
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    try {
      destroy()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular connection mount and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

/** A real component establishes the standalone Angular injection lifecycle. */
export async function mountStandaloneConnection(): Promise<LayoutConnectionStandalone> {
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<ConnectionProbe> | undefined
  let destroyed = false
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    releaseFixture(fixture, host)
  }
  try {
    fixture = TestBed.createComponent(ConnectionProbe)
    activeRoots++
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.detectChanges()
    await fixture.whenStable()
    return { interaction: fixture.componentInstance.interaction, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular standalone cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
