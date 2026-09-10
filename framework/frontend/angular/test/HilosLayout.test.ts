// The banner region of the Angular shell: the full-width strip a project fills
// below the nav, which until HIL-933 existed only in the Vue shell. Three
// assertions, the same three the React peer makes
// (react/test/HilosMaintenance.test.tsx, describe "HilosLayout banner region"):
// the region is there and empty when nothing is projected, it carries what a
// project projects, and it stands between the navigation and the content.
//
// Order is read off the shell's child order rather than off heights: jsdom does
// not lay out, so every height there is zero and a measurement would pass on a
// broken region too.
//
// The wiring these cases run on — the DOM environment, the TestBed platform, and
// the module reset between cases — lives in the package's vitest.setup.ts and was
// laid down by HIL-848; this file configures none of it.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  type ConnectionState,
  type HilosConnection,
  type ProtectedModeStatus,
  type RtStalenessStatus,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosLayout } from '../src/HilosLayout.js'

/**
 * The five values the shell reads off a connection, and nothing else: it takes
 * each one in an effect of its own constructor (src/HilosLayout.ts, the five
 * effects) and never calls anything back.
 *
 * `firstFrameHeld` has to be false — held, the shell renders the hidden
 * boot-state marker alone and the region is not in the tree at all.
 *
 * @returns A connection double the shell can mount on.
 */
function fakeConnection(): HilosConnection {
  return {
    get state(): ConnectionState {
      return 'connected'
    },
    get protectedMode(): ProtectedModeStatus {
      return PROTECTED_MODE_INACTIVE
    },
    get rtStaleness(): RtStalenessStatus {
      return RT_STALENESS_FRESH
    },
    get reconnectDragging(): boolean {
      return false
    },
    get firstFrameHeld(): boolean {
      return false
    },
    on(): () => void {
      return () => {}
    },
  } as unknown as HilosConnection
}

/**
 * A host for the filled case: projected content cannot be handed to a component
 * created directly, so the strip is written into a template around the shell.
 */
@Component({
  selector: 'test-banner-host',
  imports: [HilosLayout],
  template: `
    <hilos-layout [connection]="connection">
      <span banner data-id="test-banner">Acting for someone else</span>
    </hilos-layout>
  `,
})
class BannerHost {
  readonly connection = fakeConnection()
}

describe('HilosLayout banner region', () => {
  it('keeps an empty banner region in the shell for a project to fill', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection())
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(region).not.toBeNull()
    expect(region?.childElementCount).toBe(0)
    expect(region?.textContent?.trim()).toBe('')
    expect(region?.className).toBe('flex-shrink-0')
  })

  it('puts the strip a project passes into the banner region', () => {
    const fixture = TestBed.createComponent(BannerHost)
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(region?.querySelector('[data-id="test-banner"]')).not.toBeNull()
  })

  it('stands the banner region between the navigation and the content', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection())
    fixture.detectChanges()

    const root = fixture.nativeElement.querySelector(
      '[data-id="app-root"]',
    ) as HTMLElement | null
    const children = Array.from(root?.children ?? [])
    const nav = children.findIndex((child) => child.tagName === 'NAV')
    const region = children.findIndex(
      (child) => child.getAttribute('data-id') === 'app-banner',
    )
    const main = children.findIndex(
      (child) => child.id === 'hilos-main-content',
    )

    expect(nav).toBeGreaterThanOrEqual(0)
    expect(region).toBeGreaterThan(nav)
    expect(region).toBeLessThan(main)
  })
})
