// The banner region of the Angular shell: the full-width strip a project fills
// below the nav, which until HIL-933 existed only in the Vue shell. Six
// assertions, the same six the React peer makes
// (react/test/HilosMaintenance.test.tsx, describe "HilosLayout banner region"):
// the region is there and empty when nothing is projected, it carries what a
// project projects, it stands between the navigation and the content, it holds
// the framework's own protected-mode strip, that strip brings no live region of
// its own, and it stays above the strip a project passes.
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
 * @param protectedMode Protected-mode state the shell reads off the connection.
 * @returns A connection double the shell can mount on.
 */
function fakeConnection(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
): HilosConnection {
  return {
    get state(): ConnectionState {
      return 'connected'
    },
    get protectedMode(): ProtectedModeStatus {
      return protectedMode
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

/** The window seen from inside it: the mode holds the node, not this client. */
const ADMITTED: ProtectedModeStatus = {
  ...PROTECTED_MODE_INACTIVE,
  acceptsPass: true,
  bannerMessage: 'The restore is being verified.',
}

/**
 * A host for the filled case: projected content cannot be handed to a component
 * created directly, so the strip is written into a template around the shell.
 *
 * The connection is writable so a case can swap in a mode that raises the
 * framework strip before the first change detection.
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
  connection = fakeConnection()
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

  it('puts the framework strip inside the banner region', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection(ADMITTED))
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(
      region?.querySelector('[data-id="protected-mode-banner"]'),
    ).not.toBeNull()
  })

  it('adds no live region of its own when the strip goes up', () => {
    // The shell always carries live regions of its own - the page title, the
    // connection indicator, this region - so what the strip owes is a delta of
    // zero, not a document with exactly one of them.
    const live = '[role="status"][aria-live="polite"]'
    const down = TestBed.createComponent(HilosLayout)
    down.componentRef.setInput('connection', fakeConnection())
    down.detectChanges()
    const up = TestBed.createComponent(HilosLayout)
    up.componentRef.setInput('connection', fakeConnection(ADMITTED))
    up.detectChanges()

    expect(
      (up.nativeElement as HTMLElement).querySelectorAll(live).length,
    ).toBe((down.nativeElement as HTMLElement).querySelectorAll(live).length)
  })

  it('keeps the framework strip above the one a project passes', () => {
    const fixture = TestBed.createComponent(BannerHost)
    fixture.componentInstance.connection = fakeConnection(ADMITTED)
    fixture.detectChanges()

    // The order is the region's own child order now, not the order two
    // independent blocks happen to be written in.
    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null
    const children = Array.from(region?.children ?? [])
    const strip = children.findIndex(
      (child) => child.getAttribute('data-id') === 'protected-mode-banner',
    )
    const passed = children.findIndex(
      (child) => child.getAttribute('data-id') === 'test-banner',
    )

    expect(strip).toBeGreaterThanOrEqual(0)
    expect(passed).toBeGreaterThan(strip)
  })
})
