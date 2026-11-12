import { afterEach, describe, expect, it, vi } from 'vitest'
import type { OutputRefSubscription } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createSignal, type HilosRouter } from '@hilos/core/base'
import { reconsentContent } from '../../core/test/legal/reconsentFixture.js'
import { registerLegalReconsentAcceptCases } from '../../test-support/legalReconsentAcceptCases.js'
import { registerLegalReconsentContentCases } from '../../test-support/legalReconsentContentCases.js'
import { registerLegalReconsentNonWindowBusyCases } from '../../test-support/legalReconsentNonWindowBusyCases.js'
import { registerLegalReconsentPresentationCases } from '../../test-support/legalReconsentPresentationCases.js'
import {
  registerLegalReconsentPreviewCases,
  releaseLegalReconsent,
} from '../../test-support/legalReconsentPreviewCases.js'
import { registerLegalReconsentWindowBusyCases } from '../../test-support/legalReconsentWindowBusyCases.js'
import { HilosLegalReconsent } from '../src/legal/HilosLegalReconsent.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { mountLegalReconsent } from './legalReconsentMount.js'

registerLegalReconsentPreviewCases(
  'Angular',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentPresentationCases(
  'Angular',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentContentCases(
  'Angular',
  { describe, it, expect },
  mountLegalReconsent,
)
registerLegalReconsentAcceptCases(
  'Angular',
  { describe, it, expect, vi },
  mountLegalReconsent,
)

registerLegalReconsentNonWindowBusyCases(
  'Angular',
  { describe, it, expect },
  mountLegalReconsent,
)

registerLegalReconsentWindowBusyCases(
  'Angular',
  { describe, it, expect },
  mountLegalReconsent,
)

afterEach(() => {
  TestBed.resetTestingModule()
  vi.useRealTimers()
})

const PERSON = { name: 'Bob', impersonated: false }

/** Own partial native setup and subscriptions before exercising an unflushed input. */
async function withNativeWindow(
  inputs: Record<string, unknown>,
  check: (
    fixture: ComponentFixture<HilosLegalReconsent>,
    root: HTMLElement,
    subscriptions: OutputRefSubscription[],
  ) => void,
  router?: HilosRouter,
): Promise<void> {
  const native: { fixture?: ComponentFixture<HilosLegalReconsent> } = {}
  const subscriptions: OutputRefSubscription[] = []
  const errors: unknown[] = []
  const host = document.createElement('div')
  try {
    document.body.appendChild(host)
    await TestBed.configureTestingModule({
      imports: [HilosLegalReconsent],
      providers: router ? [{ provide: HILOS_ROUTER, useValue: router }] : [],
    }).compileComponents()
    native.fixture = TestBed.createComponent(HilosLegalReconsent)
    const fixture = native.fixture
    const destroy = fixture.destroy.bind(fixture)
    let fixtureDestroyed = false
    fixture.destroy = () => {
      if (fixtureDestroyed) return
      fixtureDestroyed = true
      destroy()
    }
    host.appendChild(fixture.nativeElement as HTMLElement)
    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value)
    }
    fixture.detectChanges()
    check(fixture, host, subscriptions)
  } catch (error) {
    errors.push(error)
  } finally {
    try {
      await releaseLegalReconsent([
        ...subscriptions.map(
          (subscription) => () => subscription.unsubscribe(),
        ),
        () => native.fixture?.destroy(),
        () => {
          TestBed.resetTestingModule()
        },
        () => host.remove(),
      ])
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Angular pre-detect case and cleanup failed',
    )
}

describe('Angular "the terms have changed" screen (HIL-500)', () => {
  // ComponentRef.setInput updates Angular input signals before fixture.detectChanges
  // writes native disabled bindings. These four checks deliberately dispatch during
  // that API-specific gap. The settled disabled state, DOM/text identity and ordinary
  // outputs are exercised by the common cases. This local test retains only Angular's
  // pre-detect guard checkpoint.
  it.each([
    { kind: 'changes', buttons: ['full-text', 'compare', 'refuse', 'later'] },
    { kind: 'text', buttons: ['back', 'refuse', 'later'] },
    { kind: 'compare', buttons: ['back', 'refuse', 'later'] },
    { kind: 'refuse', buttons: ['back', 'close'] },
  ])(
    'guards the $kind outputs before native disabled bindings are detected',
    async ({ kind, buttons }) => {
      const native: { fixture?: ComponentFixture<HilosLegalReconsent> } = {}
      const subscriptions: OutputRefSubscription[] = []
      const errors: unknown[] = []
      const host = document.createElement('div')
      try {
        document.body.appendChild(host)
        await TestBed.configureTestingModule({
          imports: [HilosLegalReconsent],
        }).compileComponents()
        native.fixture = TestBed.createComponent(HilosLegalReconsent)
        const fixture = native.fixture
        const destroy = fixture.destroy.bind(fixture)
        let fixtureDestroyed = false
        fixture.destroy = () => {
          if (fixtureDestroyed) return
          fixtureDestroyed = true
          destroy()
        }
        host.appendChild(fixture.nativeElement as HTMLElement)
        for (const [name, value] of Object.entries({
          variant: 'window',
          content: reconsentContent('remind'),
          view: { kind, document: 'terms' },
          error: 'The terms changed. Review them again.',
          person: PERSON,
        })) {
          fixture.componentRef.setInput(name, value)
        }
        fixture.detectChanges()
        const shown = vi.fn()
        const later = vi.fn()
        const retry = vi.fn()
        subscriptions.push(
          fixture.componentInstance.show.subscribe(shown),
          fixture.componentInstance.later.subscribe(later),
          fixture.componentInstance.retry.subscribe(retry),
        )
        const nodes = [...buttons, 'not-you'].map((id) => {
          const node = host.querySelector<HTMLElement>(
            `[data-id="legal-reconsent-${id}"]`,
          )
          if (!node) throw new Error(`Missing legal re-consent control: ${id}`)
          return node
        })
        fixture.componentRef.setInput('busy', true)
        for (const node of nodes) {
          node.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        }
        expect(shown).not.toHaveBeenCalled()
        expect(later).not.toHaveBeenCalled()
        expect(retry).not.toHaveBeenCalled()
      } catch (error) {
        errors.push(error)
      } finally {
        try {
          await releaseLegalReconsent([
            ...subscriptions.map(
              (subscription) => () => subscription.unsubscribe(),
            ),
            () => native.fixture?.destroy(),
            () => {
              TestBed.resetTestingModule()
            },
            () => host.remove(),
          ])
        } catch (error) {
          errors.push(error)
        }
      }
      if (errors.length === 1) throw errors[0]
      if (errors.length > 1)
        throw new AggregateError(
          errors,
          'Angular pre-detect case and cleanup failed',
        )
    },
  )

  // ComponentRef.setInput makes busy live before detectChanges paints disabled.
  // Keep this failed-read checkpoint in that native gap; the settled full cycle
  // with Bob, the error and the released Retry/Later outputs is shared.
  it('guards failed-read outputs before native disabled bindings are detected', async () => {
    await withNativeWindow(
      {
        variant: 'window',
        content: null,
        view: { kind: 'changes' },
        error: 'The terms changed. Review them again.',
        person: PERSON,
      },
      (fixture, root, subscriptions) => {
        const shown = vi.fn()
        const later = vi.fn()
        const retry = vi.fn()
        subscriptions.push(fixture.componentInstance.show.subscribe(shown))
        subscriptions.push(fixture.componentInstance.later.subscribe(later))
        subscriptions.push(fixture.componentInstance.retry.subscribe(retry))
        const nodes = ['retry', 'later', 'not-you'].map((id) => {
          const node = root.querySelector<HTMLElement>(
            `[data-id="legal-reconsent-${id}"]`,
          )
          if (!node) throw new Error(`Missing legal re-consent control: ${id}`)
          return node
        })
        fixture.componentRef.setInput('busy', true)
        for (const node of nodes)
          node.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        expect(shown).not.toHaveBeenCalled()
        expect(later).not.toHaveBeenCalled()
        expect(retry).not.toHaveBeenCalled()
      },
    )
  })

  // The input signal is live before native link attributes are detected. This
  // Angular-only capture checkpoint must precede detectChanges; the shared cases
  // own settled attributes, refusal identity/copy, release and ordinary navigation.
  it.each(['data', 'delete'])(
    'guards the %s link before native attributes are detected',
    async (link) => {
      const navigate = vi.fn()
      const router = {
        currentPath: createSignal('/'),
        navigate,
      } as unknown as HilosRouter
      await withNativeWindow(
        {
          variant: 'window',
          content: reconsentContent('remind'),
          view: { kind: 'refuse' },
        },
        (fixture, root, subscriptions) => {
          const later = vi.fn()
          subscriptions.push(fixture.componentInstance.later.subscribe(later))
          const anchor = root.querySelector<HTMLElement>(
            `[data-id="legal-reconsent-${link}-link"]`,
          )
          if (!anchor) throw new Error(`Missing legal re-consent link: ${link}`)
          fixture.componentRef.setInput('busy', true)
          for (const init of [
            {},
            { ctrlKey: true },
            { metaKey: true },
            { shiftKey: true },
            { button: 1 },
          ]) {
            const event = new MouseEvent(
              init.button === 1 ? 'auxclick' : 'click',
              { ...init, bubbles: true, cancelable: true },
            )
            expect(anchor.dispatchEvent(event)).toBe(false)
            expect(event.defaultPrevented).toBe(true)
          }
          expect(navigate).not.toHaveBeenCalled()
          expect(later).not.toHaveBeenCalled()
        },
        router,
      )
    },
  )
})
