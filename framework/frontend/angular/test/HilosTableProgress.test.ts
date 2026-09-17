// The Angular port of vue/src/HilosTableProgress.test.ts, under the same case
// names the Vue reference and the React port run.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import type { HilosTableProgress as HilosTableProgressState } from '@hilos/core'

import { HilosTableProgress } from '../src/HilosTableProgress.js'

/** A host binding the bar and the accessible name of its track. */
@Component({
  selector: 'test-table-progress-host',
  imports: [HilosTableProgress],
  template: `<hilos-table-progress [progress]="progress" [label]="label" />`,
})
class ProgressHost {
  progress!: HilosTableProgressState
  label = 'Work on this table'
}

// A bar as the core hands it over: the fraction is already worked out there, so
// these tests hand over the answer rather than the two numbers behind it.
function progress(fraction: number | null): HilosTableProgressState {
  return {
    progressKey: 'nightly',
    current: 34,
    total: fraction === null ? null : 120,
    fraction,
    detail: {},
  }
}

function mountProgress(
  fraction: number | null,
  label = 'Work on this table',
): ComponentFixture<ProgressHost> {
  const fixture = TestBed.createComponent(ProgressHost)
  fixture.componentInstance.progress = progress(fraction)
  fixture.componentInstance.label = label
  fixture.detectChanges()

  return fixture
}

function track(fixture: ComponentFixture<unknown>): HTMLElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    '[role="progressbar"]',
  ) as HTMLElement
}

function bar(fixture: ComponentFixture<unknown>): HTMLElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    '.progress-bar',
  ) as HTMLElement
}

describe('HilosTableProgress', () => {
  it('draws a known fraction as a percentage, in the width and in aria-valuenow', () => {
    const fixture = mountProgress(0.284)

    expect(track(fixture).getAttribute('aria-valuenow')).toBe('28')
    expect(track(fixture).getAttribute('aria-valuemin')).toBe('0')
    expect(track(fixture).getAttribute('aria-valuemax')).toBe('100')
    expect(bar(fixture).style.getPropertyValue('--hilos-progress')).toBe('28')
  })

  it('draws work with no total as a striped track carrying no number', () => {
    const fixture = mountProgress(null)

    expect(bar(fixture).classList.contains('progress-bar-striped')).toBe(true)
    expect(bar(fixture).classList.contains('progress-bar-animated')).toBe(true)
    expect(bar(fixture).style.getPropertyValue('--hilos-progress')).toBe('100')
    expect(track(fixture).hasAttribute('aria-valuenow')).toBe(false)
  })

  it('takes its accessible name from the place that draws it', () => {
    const fixture = mountProgress(0.5, 'Work on this row')

    expect(track(fixture).getAttribute('aria-label')).toBe('Work on this row')
  })

  it('puts the width class on the bar and the height class on the track', () => {
    const fixture = mountProgress(0.5)

    expect(track(fixture).classList.contains('hilos-progress-track')).toBe(true)
    expect(bar(fixture).classList.contains('hilos-progress')).toBe(true)
  })
})
