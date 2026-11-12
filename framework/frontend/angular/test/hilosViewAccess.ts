// Native Angular templates, inputs and effects over the real outlet and modal.
import { Component, signal, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'

import type { WithHilosViewAccess } from '../../test-support/hilosViewAccessFixtures.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { shellPage } from './hilosViewDeletion.js'

@Component({
  selector: 'test-access-dirty',
  imports: [HilosModal],
  template: `
    <div data-id="private-page">
      <button type="button" data-id="open-draft" (click)="open.set(true)">
        Edit
      </button>
      <hilos-modal
        title="Edit"
        [open]="open()"
        [confirmOnClose]="true"
        (openChange)="open.set($event)"
      >
        <input data-id="draft" data-autofocus="" />
      </hilos-modal>
    </div>
  `,
})
class DirtyPage {
  protected readonly open = signal(false)
}
@Component({
  selector: 'test-access-auth',
  template:
    '<form data-id="auth-surface"><input data-id="auth-identifier" data-autofocus="" /></form>',
})
class AuthSurface {}
@Component({
  selector: 'test-access-known',
  template: '<div data-id="known-page">Known</div>',
})
class KnownPage {}
@Component({
  selector: 'test-access-status',
  template: '<div data-id="public-file-reader">Public file reader</div>',
})
class PublicFileReader {}

export const withHilosViewAccess: WithHilosViewAccess<Type<unknown>> = async (
  steps,
  afterUnmount,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const fixtures: ComponentFixture<HilosView>[] = []
  try {
    await steps({
      dirty: DirtyPage,
      auth: AuthSurface,
      known: KnownPage,
      status: PublicFileReader,
      card: shellPage,
      async mount({ router, ...input }) {
        if (fixtures.length === 0)
          TestBed.configureTestingModule({
            providers: [{ provide: HILOS_ROUTER, useValue: router }],
          })
        const fixture = TestBed.createComponent(HilosView)
        fixtures.push(fixture)
        for (const [key, value] of Object.entries(input))
          fixture.componentRef.setInput(key, value)
        const flush = async (change?: () => void) => {
          change?.()
          await Promise.resolve()
          fixture.detectChanges()
          await Promise.resolve()
          fixture.detectChanges()
        }
        await flush()
        return {
          root: fixture.nativeElement as HTMLElement,
          flush,
          async type(input, value) {
            await flush(() => {
              input.value = value
              input.dispatchEvent(new Event('input', { bubbles: true }))
            })
          },
        }
      },
    })
  } finally {
    try {
      for (const fixture of fixtures.reverse()) fixture.destroy()
    } finally {
      afterUnmount()
      TestBed.resetTestingModule()
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
