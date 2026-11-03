import { TestBed } from '@angular/core/testing'
import { HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalRevisionTextPage } from '../src/admin/legal/HilosLegalRevisionTextPage.js'
import { text } from './helpers/hilosLegalDocument.js'
import { revisionHarness } from './helpers/hilosLegalRevision.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

describe('HilosLegalRevisionTextPage', () => {
  it('renders the selected historical clauses in composed order with its deviation in place', () => {
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
    )
    const clauses = [
      ...h.root.querySelectorAll('[data-id="legal-revision-text"] li'),
    ]
    expect(clauses.map((node) => text(node, 'strong'))).toEqual([
      'Historical first.',
      'Historical deviation.',
      'Historical last.',
    ])
    expect(clauses[1]!.textContent).toContain(
      'The historical project retention.',
    )
    expect(
      text(clauses[1]!, '[data-id="legal-revision-clause-deviation"]'),
    ).toBe('Project deviation from: Limited retention · stricter')
    expect(h.root.querySelector('[data-id="legal-changes"]')).toBeNull()
    h.fixture.destroy()
  })

  it('mounts without table controllers, viewports, counters or actions', () => {
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
    )
    expect(h.controllers).toEqual([])
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    expect(
      h.root.querySelectorAll('table, button, input, select, form'),
    ).toHaveLength(0)
    expect(
      h.root.querySelector('[data-id="legal-revision-accepted"]'),
    ).toBeNull()
    expect(
      h.root.querySelector('[data-id="legal-open-acceptances"]'),
    ).toBeNull()
    h.fixture.destroy()
    expect(h.listenerCount()).toBe(0)
  })
})
