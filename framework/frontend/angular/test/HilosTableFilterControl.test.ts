import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import {
  registerTableFilterControlCases,
  type TableFilterControlInput,
  type TableFilterControlView,
} from '../../test-support/tableFilterControlCases.js'
import { HilosTableFilterControl } from '../src/HilosTableFilterControl.js'

/** Bind signal inputs through the same native template as a caller. */
@Component({
  selector: 'test-table-filter-control-host',
  imports: [HilosTableFilterControl],
  template: `
    <hilos-table-filter-control
      [view]="view"
      [controller]="controller"
      [placement]="placement"
    />
  `,
})
class FilterControlHost {
  view!: TableFilterControlInput['view']
  controller!: TableFilterControlInput['controller']
  placement!: TableFilterControlInput['placement']
}

// Multiple independent controls can be mounted in the same shared case.
const fixtures = new Set<ComponentFixture<FilterControlHost>>()

/** Own the fixture before assigning inputs or running initial detection. */
async function mountFilterControl(
  input: TableFilterControlInput,
): Promise<TableFilterControlView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<FilterControlHost> | undefined
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => fixture?.destroy(),
      () => (fixture?.nativeElement as HTMLElement | undefined)?.remove(),
      () => {
        if (fixture) fixtures.delete(fixture)
        if (fixtures.size === 0) TestBed.resetTestingModule()
      },
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
      throw new AggregateError(errors, 'Angular filter control cleanup failed')
  }
  try {
    document.body.appendChild(host)
    fixture = TestBed.createComponent(FilterControlHost)
    fixtures.add(fixture)
    // TestBed removes every root-prefixed id when it inserts another fixture.
    // This adapter owns this fixture host, including its eventual removal.
    const fixtureHost = fixture.nativeElement as HTMLElement
    fixtureHost.removeAttribute('id')
    host.appendChild(fixtureHost)
    fixture.componentInstance.view = input.view
    fixture.componentInstance.controller = input.controller
    fixture.componentInstance.placement = input.placement
    fixture.detectChanges()
    await fixture.whenStable()
    return {
      root: host,
      unmount,
      async interact(action) {
        action()
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
      async enterDate(field, value) {
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
        'Angular filter control setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFilterControlCases(
  'Angular',
  { describe, it, expect },
  mountFilterControl,
)
