// The routed outlet while a page waits for its first answer (HIL-983): a skeleton
// in the page's place instead of white, never before the delay, gone on the
// answer, and never over a refusal. The page-state marker the e2e suites of all
// three demos wait on keeps its three values through all of it.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createSignal, DEFAULT_SKELETON_DELAY_MS } from '@hilos/core'
import type {
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
  WritableSignal,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const NOT_FOUND: PageSubscriptionError = {
  page: 'main',
  httpCode: 404,
  errorCode: 'not_found',
  message: 'Not found',
}

@Component({
  selector: 'test-main-page',
  template: '<div data-id="main"></div>',
})
class MainPage {}

@Component({
  selector: 'test-main-skeleton',
  template: '<div data-id="main-skeleton"></div>',
})
class MainSkeleton {}

interface WaitingRouter {
  router: HilosRouter
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
}

/**
 * A router whose page is still waiting for its first answer.
 *
 * @param page The key of the current page.
 * @returns The router and the two signals a case drives.
 */
function waitingRouter(page: string): WaitingRouter {
  const pageLoading = createSignal(true)
  const pageError = createSignal<PageSubscriptionError | null>(null)

  return {
    pageLoading,
    pageError,
    router: {
      currentRoute: createSignal<PageRouteMatch>({
        page,
        params: {},
        admin: false,
      }),
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError,
      pageLoading,
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      resolvePath: () => undefined,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    },
  }
}

/**
 * Mount the outlet over a router, optionally with a declared skeleton for the
 * main page.
 *
 * @param router The router the outlet mirrors.
 * @param withSkeletons Whether the project declares its own main skeleton.
 * @returns The mounted fixture.
 */
function mountView(
  router: HilosRouter,
  withSkeletons = false,
): ComponentFixture<HilosView> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosView)
  fixture.componentRef.setInput('pages', { main: MainPage })
  if (withSkeletons) {
    fixture.componentRef.setInput('pageSkeletons', { main: MainSkeleton })
  }
  fixture.detectChanges()

  return fixture
}

function outlast(fixture: ComponentFixture<HilosView>, ms: number): void {
  vi.advanceTimersByTime(ms)
  fixture.detectChanges()
}

function find(
  fixture: ComponentFixture<HilosView>,
  dataId: string,
): Element | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${dataId}"]`,
  )
}

describe('HilosView skeleton', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('draws nothing in the first moments of the wait', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 1)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
  })

  it('draws the default skeleton once the wait outlasts the delay', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(
      find(fixture, 'hilos-page-skeleton')?.querySelector(
        '[data-id="hilos-skeleton"]',
      ),
    ).not.toBeNull()
  })

  it('announces the page skeleton once, whatever it draws', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    const status = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[role="status"]',
    )
    expect(status).toHaveLength(1)
    expect(status[0].textContent).toBe('Loading…')
  })

  it('gives way to the page on its answer', () => {
    const { router, pageLoading } = waitingRouter('main')
    const fixture = mountView(router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    pageLoading.set(false)
    fixture.detectChanges()
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(find(fixture, 'main')).not.toBeNull()
  })

  it('draws the skeleton the project declared for the page key', () => {
    const fixture = mountView(waitingRouter('main').router, true)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'main-skeleton')).not.toBeNull()
    expect(find(fixture, 'hilos-skeleton')).toBeNull()
  })

  it('falls back to the default skeleton for an undeclared page key', () => {
    const fixture = mountView(waitingRouter('settings').router, true)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'main-skeleton')).toBeNull()
    expect(find(fixture, 'hilos-skeleton')).not.toBeNull()
  })

  it('shows the refusal, not a skeleton, when the page is denied', () => {
    const { router, pageError } = waitingRouter('main')
    const fixture = mountView(router)

    pageError.set(NOT_FOUND)
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(find(fixture, 'page-error')).not.toBeNull()
  })

  it('keeps the page-state marker going from loading to ready', () => {
    const { router, pageLoading } = waitingRouter('main')
    const fixture = mountView(router)
    const marker = () =>
      find(fixture, 'hilos-page-state')?.getAttribute('data-state')

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(marker()).toBe('loading')
    pageLoading.set(false)
    fixture.detectChanges()
    expect(marker()).toBe('ready')
  })
})
