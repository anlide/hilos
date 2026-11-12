import { describe, expect, it } from 'vitest'
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { TableViewportController } from '@hilos/core/base'
import type {
  TableSelectionInput,
  TableSelectionMountOptions,
  TableSelectionView,
} from '../../test-support/tableSelectionFixtures.js'
import { registerTableSelectionStateCases } from '../../test-support/tableSelectionStateCases.js'
import { registerTableSelectionConfirmCases } from '../../test-support/tableSelectionConfirmCases.js'

import { HilosTableSelection } from '../src/HilosTableSelection.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'

/** A host binding the panel's inputs. */
@Component({
  selector: 'test-table-selection-host',
  imports: [HilosTableSelection],
  template: `<hilos-table-selection
    [controller]="controller"
    [shown]="shown()"
  />`,
})
class SelectionHost {
  controller!: TableViewportController<unknown>
  // A signal, so that taking the panel down reaches an OnPush child.
  readonly shown = signal(true)
}

/** Own the real component fixture before delivering its controller input. */
async function mountTableSelection(
  input: TableSelectionInput,
  options: TableSelectionMountOptions,
): Promise<TableSelectionView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<SelectionHost> | undefined
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
      throw new AggregateError(
        errors,
        'Angular table selection cleanup failed',
        { cause: errors[0] },
      )
  }
  try {
    document.body.appendChild(host)
    if (options.adminViewMode !== undefined) {
      TestBed.configureTestingModule({
        providers: [
          {
            provide: HILOS_ADMIN_VIEW_MODE,
            useValue: signal(options.adminViewMode).asReadonly(),
          },
        ],
      })
    }
    fixture = TestBed.createComponent(SelectionHost)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.componentInstance.controller = input.controller
    fixture.componentInstance.shown.set(input.shown)
    fixture.detectChanges()
    await fixture.whenStable()
    return {
      root: host,
      unmount,
      async updateShown(shown) {
        fixture!.componentInstance.shown.set(shown)
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
      async settle() {
        if (released) return
        await fixture!.whenStable()
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
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
        'Angular table selection setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableSelectionStateCases(
  'Angular',
  { describe, it, expect },
  mountTableSelection,
)

registerTableSelectionConfirmCases(
  'Angular',
  { describe, it, expect },
  mountTableSelection,
)
