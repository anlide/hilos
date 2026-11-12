import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import {
  registerBreadcrumbCases,
  type BreadcrumbInput,
  type BreadcrumbView,
} from '../../test-support/breadcrumbCases.js'
import { HilosBreadcrumb } from '../src/HilosBreadcrumb.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Bind the real breadcrumb's inputs and optional injected router. */
async function mountBreadcrumb({
  crumbs,
  label,
  router,
}: BreadcrumbInput): Promise<BreadcrumbView> {
  let fixture: ComponentFixture<HilosBreadcrumb> | undefined
  try {
    if (router !== undefined) {
      TestBed.configureTestingModule({
        providers: [{ provide: HILOS_ROUTER, useValue: router }],
      })
    }
    fixture = TestBed.createComponent(HilosBreadcrumb)
    const root = fixture.nativeElement as HTMLElement
    fixture.componentRef.setInput('crumbs', crumbs)
    if (label !== undefined) fixture.componentRef.setInput('label', label)
    fixture.detectChanges()
    return {
      root,
      async unmount() {
        try {
          fixture!.destroy()
        } finally {
          root.remove()
          TestBed.resetTestingModule()
        }
      },
    }
  } catch (error) {
    try {
      fixture?.destroy()
    } finally {
      TestBed.resetTestingModule()
    }
    throw error
  }
}

registerBreadcrumbCases('Angular', { describe, it, expect }, mountBreadcrumb)
