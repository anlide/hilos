import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import {
  registerTableProgressCases,
  type TableProgressInput,
  type TableProgressView,
} from '../../test-support/tableProgressCases.js'
import { HilosTableProgress } from '../src/HilosTableProgress.js'

/** A host binding the real bar and its accessible name. */
@Component({
  selector: 'test-table-progress-host',
  imports: [HilosTableProgress],
  template: `<hilos-table-progress [progress]="progress" [label]="label" />`,
})
class ProgressHost {
  progress!: TableProgressInput['progress']
  label = ''
}

/** Own the fixture before assigning inputs or running initial detection. */
async function mountProgress(
  input: TableProgressInput,
): Promise<TableProgressView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<ProgressHost> | undefined
  async function unmount(): Promise<void> {
    const errors: unknown[] = []
    for (const release of [
      () => fixture?.destroy(),
      () => {
        TestBed.resetTestingModule()
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
      throw new AggregateError(errors, 'Angular progress cleanup failed')
  }
  try {
    document.body.appendChild(host)
    fixture = TestBed.createComponent(ProgressHost)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.componentInstance.progress = input.progress
    fixture.componentInstance.label = input.label
    fixture.detectChanges()
    await fixture.whenStable()
    return { root: host, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular progress setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableProgressCases('Angular', { describe, it, expect }, mountProgress)
