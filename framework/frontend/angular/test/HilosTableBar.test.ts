// The Angular port of vue/src/HilosTableBar.test.ts, under the same case names the
// Vue reference and the React port run, for the layer the Angular bar draws:
// title, search, filters, badge, the order menu, main and auxiliary actions, and
// the filters modal. Every case
// mounts a host that binds the two inputs.
import { Component, signal } from '@angular/core'
import type { WritableSignal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'
import { TableViewportController } from '@hilos/core/base'
import type {
  HilosTableFrame,
  HilosTableSortOrder,
  HilosTableViews,
  TableViewportDescriptor,
} from '@hilos/core/base'
import type {
  TableBarInput,
  TableBarView,
} from '../../test-support/tableBarFixtures.js'
import { withTableBarResources } from '../../test-support/tableBarFixtures.js'
import { registerTableBarBasicCases } from '../../test-support/tableBarBasicCases.js'
import { registerTableBarListActionCases } from '../../test-support/tableBarListActionCases.js'
import { registerTableBarOrderCases } from '../../test-support/tableBarOrderCases.js'
import { registerTableBarSelectionCases } from '../../test-support/tableBarSelectionCases.js'
import { registerTableBarViewCases } from '../../test-support/tableBarViewCases.js'

import { HilosTableBar } from '../src/HilosTableBar.js'

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** A host binding the controller and the id the title carries. */
@Component({
  selector: 'test-table-bar-host',
  imports: [HilosTableBar],
  template: `<hilos-table-bar [controller]="controller" [titleId]="titleId" />`,
})
class BarHost {
  controller!: TableViewportController<unknown>
  titleId = 'table-title'
}

/** Own the fixture before input delivery and destroy before resetting TestBed. */
async function mountTableBar(input: TableBarInput): Promise<TableBarView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<BarHost> | undefined
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => fixture?.destroy(),
      () => (fixture?.nativeElement as HTMLElement | undefined)?.remove(),
      () => TestBed.resetTestingModule(),
      () => host.remove(),
    ]) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Angular table bar cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    fixture = TestBed.createComponent(BarHost)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.componentInstance.controller = input.controller
    fixture.componentInstance.titleId = input.titleId
    fixture.detectChanges()
    await fixture.whenStable()
    return {
      root: host,
      unmount,
      async change(action) {
        action()
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
      async type(field, value) {
        field.value = value
        field.dispatchEvent(new Event('input', { bubbles: true }))
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular table bar setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableBarBasicCases('Angular', { describe, it, expect }, mountTableBar)
registerTableBarListActionCases(
  'Angular',
  { describe, it, expect, vi },
  mountTableBar,
)
registerTableBarOrderCases('Angular', { describe, it, expect }, mountTableBar)
registerTableBarViewCases(
  'Angular',
  { describe, it, expect, vi },
  mountTableBar,
)
registerTableBarSelectionCases(
  'Angular',
  { describe, it, expect },
  mountTableBar,
)

// Native API exception: a closed Angular Modal has a host but no visible dialog.
it('N02 has no mounted Modal host without declared filters', async () => {
  await withTableBarResources(
    { describe, it, expect },
    mountTableBar,
    async (resources) => {
      const view = await resources.mount(
        resources.create({
          title: 'Backups',
          search: {},
          columns: COLUMNS,
        }),
      )
      expect(view.root.querySelector('hilos-modal')).toBeNull()
    },
  )
})

/** A host whose controller can be replaced while the bar stands. */
@Component({
  selector: 'test-table-bar-swap-host',
  imports: [HilosTableBar],
  template: `<hilos-table-bar
    [controller]="controller()"
    titleId="table-title"
  />`,
})
class SwapHost {
  controller!: WritableSignal<TableViewportController<unknown>>
}

function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
  declaredOrders?: readonly HilosTableSortOrder[],
  known = true,
  own?: (controller: TableViewportController<unknown>) => void,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
    declaredOrders,
  })
  own?.(controller)

  if (known)
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      initialFilter ?? {},
      [],
    )

  return { controller, sent }
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function click(fixture: ComponentFixture<unknown>, id: string): void {
  byId(fixture, id)?.click()
  fixture.detectChanges()
}

describe('HilosTableBar view group', () => {
  const PICTURE_VIEWS: HilosTableViews = {
    tableKey: 'pictures',
    order: ['table', 'tiles'],
    imageColumn: 'name',
  }

  /** A table declaring views, with whatever else the case adds around them. */
  function viewed(
    extra: Partial<HilosTableFrame> = {},
    views: HilosTableViews = PICTURE_VIEWS,
  ): ReturnType<typeof makeController> & { rendered: string[][] } {
    const rendered: string[][] = []
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<unknown>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (keys) => rendered.push([...keys]),
      frame: { title: 'Pictures', columns: COLUMNS, views, ...extra },
    })

    return { controller, sent, rendered }
  }

  function viewGroup(fixture: ComponentFixture<unknown>): HTMLElement {
    const group = byId(fixture, 'hilos-table-view')
    expect(group).not.toBeNull()

    return group as HTMLElement
  }

  function viewButtons(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement[] {
    return Array.from(viewGroup(fixture).querySelectorAll('button'))
  }

  /** What a reader meets in the group: each choice's words, and whether it is pressed. */
  function viewReading(
    fixture: ComponentFixture<unknown>,
  ): [string | undefined, string | null][] {
    return viewButtons(fixture).map((button) => [
      button.textContent?.trim(),
      button.getAttribute('aria-pressed'),
    ])
  }

  // Angular's input-driven effect must rebind frame.view on the existing internal
  // Bar instance. Vue's public HilosViewportTable instead keys its internal Bar by
  // controllerMount. Keep this direct-input lifecycle checkpoint; the user-visible
  // replacement sequence runs separately on the public table owner.
  it('follows a replaced controller and lets the old one go', () => {
    const releases: (() => void)[] = []
    const errors: unknown[] = []
    try {
      const first = viewed()
      releases.push(() => first.controller.dispose())
      const second = viewed({}, { ...PICTURE_VIEWS, order: ['tiles', 'table'] })
      releases.push(() => second.controller.dispose())
      const plain = makeController(
        { columns: COLUMNS },
        undefined,
        undefined,
        true,
        (controller) => releases.push(() => controller.dispose()),
      )
      const fixture = TestBed.createComponent(SwapHost)
      releases.push(() => fixture.destroy())
      const current = signal<TableViewportController<unknown>>(first.controller)
      fixture.componentInstance.controller = current
      fixture.detectChanges()
      click(fixture, 'hilos-table-view-tiles')
      expect(viewReading(fixture)).toEqual([
        ['Table', 'false'],
        ['Tiles', 'true'],
      ])

      current.set(second.controller)
      fixture.detectChanges()
      expect(viewReading(fixture)).toEqual([
        ['Tiles', 'true'],
        ['Table', 'false'],
      ])
      // The old controller is no longer mirrored.
      first.controller.setView('table')
      fixture.detectChanges()
      expect(viewReading(fixture)).toEqual([
        ['Tiles', 'true'],
        ['Table', 'false'],
      ])

      current.set(plain.controller)
      fixture.detectChanges()
      expect(byId(fixture, 'hilos-table-view')).toBeNull()
    } catch (error) {
      errors.push(error)
    } finally {
      for (const release of releases.reverse()) {
        try {
          release()
        } catch (error) {
          errors.push(error)
        }
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(
        errors,
        'Angular Bar replacement cleanup failed',
        {
          cause: errors[0],
        },
      )
  })
})
