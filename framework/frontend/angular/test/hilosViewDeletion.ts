// Native Angular DI, signals, templates and input events for the real outlet.
import { Component, signal, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { vi } from 'vitest'

import type { WithHilosViewDeletion } from '../../test-support/hilosViewDeletionFixtures.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/**
 * A card on the admin shell with one dialog in #pageModals, opened from a
 * button in its #pageBody. The draft is the card's own state, so it lives
 * exactly as long as the card's instance does.
 *
 * @param preserve Whether the card opts in.
 */
export function shellPage(preserve: boolean) {
  const mounted = vi.fn()
  @Component({
    selector: 'test-card-page',
    imports: [HilosAdminPage, HilosModal],
    template: `
      <hilos-admin-page page="lazy" [preserveModalDraftsOnDelete]="preserve">
        <ng-template #pageBody>
          <p data-id="page-body">The card</p>
          <button type="button" data-id="open-draft" (click)="open.set(true)">
            Rename
          </button>
        </ng-template>
        <ng-template #pageModals>
          <hilos-modal [(open)]="open" title="Rename">
            <input
              data-id="draft"
              data-autofocus
              [value]="draft()"
              (input)="onDraft($event)"
            />
          </hilos-modal>
        </ng-template>
      </hilos-admin-page>
    `,
  })
  class Card {
    protected readonly preserve = preserve
    protected readonly open = signal(false)
    protected readonly draft = signal('')

    constructor() {
      mounted()
    }

    protected onDraft(event: Event): void {
      this.draft.set((event.target as HTMLInputElement).value)
    }
  }

  return { component: Card, mounted }
}

@Component({
  selector: 'test-deletion-main',
  template: '<div data-id="main">Known</div>',
})
class MainPage {}
@Component({
  selector: 'test-deletion-status',
  template: '<div data-id="public-file-reader">Public file reader</div>',
})
class PublicFileReader {}

export const withHilosViewDeletion: WithHilosViewDeletion<
  Type<unknown>
> = async (steps) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const fixtures: ComponentFixture<HilosView>[] = []
  try {
    await steps({
      main: MainPage,
      status: PublicFileReader,
      card: shellPage,
      async mount({ router, pages }) {
        TestBed.configureTestingModule({
          providers: [{ provide: HILOS_ROUTER, useValue: router }],
        })
        const fixture = TestBed.createComponent(HilosView)
        fixtures.push(fixture)
        fixture.componentRef.setInput('pages', pages)
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
      TestBed.resetTestingModule()
    } finally {
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
