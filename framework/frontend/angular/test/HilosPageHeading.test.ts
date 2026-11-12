import { TestBed } from '@angular/core/testing'
import type { HilosPageIdentity } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import {
  createPageHeadingRouter,
  registerPageHeadingCases,
  type PageHeadingView,
} from '../../test-support/pageHeadingCases.js'
import { HilosPageHeading } from '../src/HilosPageHeading.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Bind the real heading's input before its first change detection. */
async function mountHeading(
  identity?: HilosPageIdentity,
): Promise<PageHeadingView> {
  const { router, pageIdentity } = createPageHeadingRouter(identity)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosPageHeading)
  const root = fixture.nativeElement as HTMLElement
  try {
    fixture.componentRef.setInput('dataId', 'test-heading')
    fixture.detectChanges()
    return {
      root,
      async update(value) {
        pageIdentity.set(value)
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

registerPageHeadingCases('Angular', { describe, it, expect }, mountHeading)
