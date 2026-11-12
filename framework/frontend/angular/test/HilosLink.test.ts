// Angular's directive receives the caller decision through hilosLinkCurrent:
// its host binding takes precedence over a template's attr.aria-current.
import { Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import {
  createLinkRouter,
  registerLinkCases,
  type LinkInput,
  type LinkView,
} from '../../test-support/linkCases.js'
import { HilosLink } from '../src/HilosLink.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Bind the real directive's required target and optional caller decision. */
@Component({
  selector: 'test-hilos-link-host',
  imports: [HilosLink],
  template: `<a [hilosLink]="value().to" [hilosLinkCurrent]="value().current"
    >Billing</a
  >`,
})
class HilosLinkHost {
  readonly value = signal<LinkInput>({ to: '', current: undefined })
}

/** Mount a native anchor under the directive and destroy it before its host. */
async function mountLink(input: LinkInput): Promise<LinkView> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: createLinkRouter() }],
  })
  const fixture = TestBed.createComponent(HilosLinkHost)
  const root = fixture.nativeElement as HTMLElement
  try {
    fixture.componentInstance.value.set(input)
    fixture.detectChanges()
    return {
      root,
      async update(input) {
        fixture.componentInstance.value.set(input)
        fixture.detectChanges()
      },
      async unmount() {
        try {
          fixture.destroy()
        } finally {
          root.remove()
        }
      },
    }
  } catch (error) {
    try {
      fixture.destroy()
    } finally {
      root.remove()
    }
    throw error
  }
}

registerLinkCases('Angular', { describe, it, expect }, mountLink)
