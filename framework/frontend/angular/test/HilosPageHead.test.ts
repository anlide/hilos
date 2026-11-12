// Shared cases run on the real Angular head; native TemplateRef proof stays local.
import { Component, Directive, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { HilosPageHeadAction, HilosPageIdentity } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosPageHead } from '../src/HilosPageHead.js'

import {
  ENTITY,
  ENTITY_PARAMS,
  resolvePath,
} from '../../test-support/pageHeadFixtures.js'
import { registerPageHeadBlockCases } from '../../test-support/pageHeadBlockCases.js'
import { registerPageHeadNavigationCases } from '../../test-support/pageHeadNavigationCases.js'
import { registerPageHeadCenteringCases } from '../../test-support/pageHeadCenteringCases.js'
import { mountPageHeadBlock } from './pageHeadBlockMount.js'

/** How many probe elements the page's templates have created so far. */
let probesCreated = 0

/** Counts every probe element a page template creates. */
@Directive({ selector: '[testProbe]' })
class TestProbe {
  constructor() {
    probesCreated++
  }
}

/** A page module handing the head the four block templates. */
@Component({
  selector: 'test-page-head-host',
  imports: [HilosPageHead, TestProbe],
  template: `
    <hilos-page-head
      [identity]="identity()"
      [page]="page()"
      [params]="params()"
      [resolvePath]="resolvePath"
      [kind]="kind()"
      [action]="action()"
      [visual]="visual"
      [meta]="meta"
      [badges]="badges"
      [description]="description"
    />
    <ng-template #visual
      ><span testProbe data-id="probe-tile">US</span></ng-template
    >
    <ng-template #meta
      ><span testProbe data-id="probe-code">us</span></ng-template
    >
    <ng-template #badges
      ><span testProbe class="badge" data-id="probe-badge"
        >Custom</span
      ></ng-template
    >
    <ng-template #description
      ><p testProbe data-id="probe-description">Currency $ USD</p></ng-template
    >
  `,
})
class PageHeadHost {
  readonly identity = signal<HilosPageIdentity | undefined>(ENTITY)
  readonly page = signal('demo_entity')
  readonly params = signal<Record<string, string>>(ENTITY_PARAMS)
  readonly kind = signal<'section' | 'entity'>('entity')
  readonly action = signal<HilosPageHeadAction | undefined>(undefined)
  protected readonly resolvePath = resolvePath
}

/**
 * Mount the head inside a page module that hands it every block template.
 *
 * @param set Adjusts the host's bindings before the first render.
 * @returns The rendered host.
 */
function mountWithTemplates(
  set: (host: PageHeadHost) => void = () => {},
): ComponentFixture<PageHeadHost> {
  probesCreated = 0
  const fixture = TestBed.createComponent(PageHeadHost)
  set(fixture.componentInstance)
  fixture.detectChanges()

  return fixture
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

registerPageHeadBlockCases(
  'Angular',
  { describe, it, expect },
  mountPageHeadBlock,
)
registerPageHeadNavigationCases(
  'Angular',
  { describe, it, expect, vi },
  mountPageHeadBlock,
)
registerPageHeadCenteringCases(
  'Angular',
  { describe, it, expect, vi },
  mountPageHeadBlock,
)

describe('HilosPageHead', () => {
  it('instantiates real templates only after the owner answers', () => {
    const host = mountWithTemplates((page) => {
      page.identity.set(undefined)
      page.action.set({ page: 'demo_section', label: 'Back' })
    })

    // Angular-specific: TemplateRef instantiation is deferred, not just hidden.
    expect(probesCreated).toBe(0)
    host.componentInstance.identity.set(ENTITY)
    host.detectChanges()
    expect(probesCreated).toBe(4)
  })
})
