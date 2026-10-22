// The current-page mark of the Angular HilosLink: the path match marks the
// anchor, and a caller's hilosLinkCurrent decides it instead. A template
// [attr.aria-current] cannot, since the directive's host binding wins over it
// (HIL-1605).
import { Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { createSignal } from '@hilos/core'
import type { HilosRouter } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosLink } from '../src/HilosLink.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** The path the router stands at in every case. */
const CURRENT_PATH = '/hilos/billing'

/** An anchor under the directive, its address and decided mark set per case. */
@Component({
  selector: 'test-hilos-link-host',
  imports: [HilosLink],
  template: `
    <a
      [hilosLink]="to()"
      [hilosLinkCurrent]="current()"
      data-id="test-hilos-link"
      >Billing</a
    >
  `,
})
class HilosLinkHost {
  readonly to = signal(CURRENT_PATH)
  readonly current = signal<'page' | null | undefined>(undefined)
}

/**
 * Mount the anchor under a router standing at CURRENT_PATH.
 *
 * @param to The address the anchor points at.
 * @param current The mark its caller decides, or undefined to decide none.
 * @returns The rendered anchor.
 */
function mount(to: string, current: 'page' | null | undefined): HTMLElement {
  const router = {
    currentPath: createSignal(CURRENT_PATH),
    navigate: () => {},
  } as unknown as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosLinkHost)
  fixture.componentInstance.to.set(to)
  fixture.componentInstance.current.set(current)
  fixture.detectChanges()

  const anchor = (
    fixture.nativeElement as HTMLElement
  ).querySelector<HTMLElement>('[data-id="test-hilos-link"]')
  if (anchor === null) {
    throw new Error('The anchor did not render')
  }

  return anchor
}

describe('HilosLink', () => {
  it.each([
    ['undefined', 'the route', 'page', undefined, CURRENT_PATH, 'page'],
    ['undefined', 'another page', 'nothing', undefined, '/hilos', null],
    ['null', 'the route', 'nothing', null, CURRENT_PATH, null],
    ['null', 'another page', 'nothing', null, '/hilos', null],
    ["'page'", 'the route', 'page', 'page', CURRENT_PATH, 'page'],
    ["'page'", 'another page', 'page', 'page', '/hilos', 'page'],
  ] as const)(
    'given hilosLinkCurrent %s and pointing at %s, marks %s',
    (_given, _target, _mark, current, to, expected) => {
      const anchor = mount(to, current)

      expect(anchor.getAttribute('href')).toBe(to)
      expect(anchor.getAttribute('aria-current')).toBe(expected)
    },
  )
})
