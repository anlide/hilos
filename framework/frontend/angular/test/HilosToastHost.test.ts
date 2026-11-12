// Angular providers, native events and lifecycle for the shared ToastHost union.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import type { HilosToastStore } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { registerToastHostCases } from '../../test-support/toastHostCases.js'
import type { WithToastHost } from '../../test-support/toastHostFixtures.js'
import { HilosToastHost } from '../src/HilosToastHost.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Bind the real component through the same parent view as the original cases. */
@Component({
  selector: 'test-toast-host',
  imports: [HilosToastHost],
  template: `<hilos-toast-host [store]="store" />`,
})
class ToastHost {
  store!: HilosToastStore
}

/** Reset TestBed after creation, detection or steps, including failure paths. */
const withToastHost: WithToastHost = async ({ store, router }, steps) => {
  const originalNodes = new Set(document.body.childNodes)
  const previousFocus = document.activeElement
  try {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router }],
    })
    const fixture = TestBed.createComponent(ToastHost)
    fixture.componentInstance.store = store
    let unmounted = false
    const flush = async (change?: () => void): Promise<void> => {
      change?.()
      if (unmounted) return
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
      await fixture.whenStable()
    }
    await flush()
    await steps({
      root: fixture.nativeElement as HTMLElement,
      flush,
      async click(target, init) {
        target.dispatchEvent(
          new MouseEvent('click', { bubbles: true, cancelable: true, ...init }),
        )
        await flush()
      },
      async mouseOver(target) {
        target.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
        await flush()
      },
      async mouseLeave(target) {
        target.dispatchEvent(new MouseEvent('mouseleave'))
        await flush()
      },
      async unmount() {
        unmounted = true
        fixture.destroy()
      },
    })
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      for (const node of [...document.body.childNodes]) {
        if (!originalNodes.has(node)) node.remove()
      }
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerToastHostCases('Angular', { describe, it, expect, vi }, withToastHost)
