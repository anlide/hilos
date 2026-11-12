import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'
import { TableViewportController } from '@hilos/core/base'
import type { TableRow, TableViewportDescriptor } from '@hilos/core/base'
import {
  registerTableFooterCases,
  type TableFooterInput,
  type TableFooterView,
} from '../../test-support/tableFooterCases.js'
import { HilosTableFooter } from '../src/HilosTableFooter.js'

/** Own the real component fixture before delivering its controller input. */
async function mountNativeFooter(
  input: TableFooterInput,
): Promise<TableFooterView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<HilosTableFooter<unknown>> | undefined
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
      throw new AggregateError(errors, 'Angular table footer cleanup failed')
  }
  try {
    document.body.appendChild(host)
    fixture = TestBed.createComponent(HilosTableFooter<unknown>)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.componentRef.setInput('controller', input.controller)
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
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular table footer setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFooterCases('Angular', { describe, it, expect }, mountNativeFooter)

// The footer says nothing about the declaration, but a table only draws one
// when it has a frame — so the tests give it the smallest one there is.
const FRAME = { title: 'Backups', columns: [{ key: 'name', label: 'Name' }] }

function makeController(
  own: (controller: TableViewportController<unknown>) => void,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: FRAME,
  })
  own(controller)

  controller.ingestSubscriptionWindow(
    [],
    0,
    true,
    null,
    null,
    10,
    undefined,
    {},
    [],
  )

  return { controller, sent }
}

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosTableFooter controller replacement', () => {
  // These get-call spies check that Angular's effect detaches both body and footer
  // mirrors on setInput replacement and fixture destruction. The settled count
  // confirms that the same native fixture adopted the new input before cleanup
  // baselines are taken. Shared replacement output is also exercised through
  // HilosViewportTable, where Vue recreates its internal Footer at the owner boundary.
  it('replaces both subscriptions with the controller and cleans them on destroy', () => {
    const controllers: TableViewportController<unknown>[] = []
    const restoreSpies: (() => void)[] = []
    let fixture: ComponentFixture<HilosTableFooter<unknown>> | undefined
    let destroyed = false
    const errors: unknown[] = []
    const own = (controller: TableViewportController<unknown>) =>
      controllers.push(controller)
    try {
      const first = makeController(own).controller
      first.ingestWindow(window(1), 1, true, null, null, 20)
      const firstBodyGet = vi.spyOn(first.frame.body, 'get')
      restoreSpies.push(() => firstBodyGet.mockRestore())
      const firstFooterGet = vi.spyOn(first.frame.footer, 'get')
      restoreSpies.push(() => firstFooterGet.mockRestore())
      fixture = TestBed.createComponent(HilosTableFooter<unknown>)
      fixture.componentRef.setInput('controller', first)
      fixture.detectChanges()

      const second = makeController(own).controller
      second.ingestWindow(window(2), 2, true, null, null, 20)
      const secondBodyGet = vi.spyOn(second.frame.body, 'get')
      restoreSpies.push(() => secondBodyGet.mockRestore())
      const secondFooterGet = vi.spyOn(second.frame.footer, 'get')
      restoreSpies.push(() => secondFooterGet.mockRestore())
      fixture.componentRef.setInput('controller', second)
      fixture.detectChanges()
      fixture.detectChanges()
      expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
        '1 – 2 of 2',
      )

      const firstBodyReads = firstBodyGet.mock.calls.length
      const firstFooterReads = firstFooterGet.mock.calls.length
      first.ingestRefusal('internal_error')
      first.ingestCount(9, true)
      expect(firstBodyGet).toHaveBeenCalledTimes(firstBodyReads)
      expect(firstFooterGet).toHaveBeenCalledTimes(firstFooterReads)

      fixture.destroy()
      destroyed = true
      const secondBodyReads = secondBodyGet.mock.calls.length
      const secondFooterReads = secondFooterGet.mock.calls.length
      second.ingestRefusal('internal_error')
      second.ingestCount(9, true)
      expect(secondBodyGet).toHaveBeenCalledTimes(secondBodyReads)
      expect(secondFooterGet).toHaveBeenCalledTimes(secondFooterReads)
    } catch (error) {
      errors.push(error)
    } finally {
      for (const release of [
        () => {
          if (!destroyed) fixture?.destroy()
        },
        ...controllers.map((controller) => () => controller.dispose()),
        ...restoreSpies,
      ]) {
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
        'Angular Footer replacement cleanup failed',
        {
          cause: errors[0],
        },
      )
  })
})
