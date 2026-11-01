// @vitest-environment node

import { StrictMode, useRef } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { HIDDEN_VALUE, TableViewportController } from '@hilos/core/base'

import { HilosModal } from '../src/HilosModal.js'
import { HilosLicenseDependenciesPage } from '../src/public/HilosLicenseDependenciesPage.js'
import { HilosLicensePage } from '../src/public/HilosLicensePage.js'
import { HilosTableValue } from '../src/HilosTableValue.js'
import { HilosTableText } from '../src/HilosTableText.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import {
  HilosTableTextContext,
  useTableTextContext,
  useTableTextReading,
  useTableTextRegion,
} from '../src/hilosTableText.js'

const inventory = {
  project: 'demo-tasks',
  projectLicenseText: 'Project license <script>alert(1)</script>',
  entries: [
    {
      name: 'react',
      version: '19.2.7',
      language: 'js' as const,
      license: 'MIT',
      licenseText: null,
      repository: 'https://github.com/facebook/react',
    },
  ],
}

describe('React server rendering', () => {
  it('renders real text/parts/hidden consumers and the table without DOM globals or render effects', () => {
    const controller = new TableViewportController<unknown>({
      resolve: (row) => row.slots,
      sendViewport: () => {},
      frame: {
        title: 'Text',
        rowHeight: { mode: 'lines', count: 2 },
        columns: [
          { key: 'name', label: 'Name', heightContent: 'text' },
          { key: 'parts', label: 'Parts', heightContent: 'parts' },
        ],
      },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      1,
      true,
      null,
      null,
      10,
    )
    const register = vi.fn(controller.geometry.register)
    Object.defineProperty(controller, 'geometry', { value: { register } })
    const command = vi.spyOn(controller, 'expandText')
    const props = {
      controller,
      readRecord: () => null,
      columnKey: 'parts',
      label: 'Parts',
      heightContent: 'parts' as const,
      projection: 'wide' as const,
    }
    try {
      expect(typeof window).toBe('undefined')
      expect(typeof document).toBe('undefined')
      expect(typeof HTMLElement).toBe('undefined')
      expect(typeof ResizeObserver).toBe('undefined')
      const html = renderToStaticMarkup(
        <>
          <HilosTableValue {...props}>
            <HilosTableText value="Full <text>" />
          </HilosTableValue>
          <HilosTableValue {...props}>
            <HilosTableText value={HIDDEN_VALUE} />
          </HilosTableValue>
          <HilosViewportTable
            controller={controller}
            cells={{
              name: () => 'Wrapped text',
              parts: () => <HilosTableText value="Table text" />,
            }}
          />
        </>,
      )
      expect(html).toContain('Full &lt;text&gt;')
      expect(html).toContain('hilos-table-text-lines-2 is-open')
      expect(html).toContain('data-id="hilos-hidden"')
      expect(html.match(/Wrapped text/g)).toHaveLength(2)
      expect(html.match(/Table text/g)).toHaveLength(2)
      expect(html).not.toContain('hilos-table-text-toggle')
      expect(html).not.toContain('[object Object]')
      expect(register).not.toHaveBeenCalled()
      expect(command).not.toHaveBeenCalled()
    } finally {
      controller.dispose()
      vi.restoreAllMocks()
    }
  })

  it('renders the declared Table and Tiles choice of a table bar without DOM globals', () => {
    const controller = new TableViewportController<unknown>({
      resolve: (row) => row.slots,
      sendViewport: () => {},
      frame: {
        title: 'Pictures',
        columns: [{ key: 'name', label: 'Name' }],
        views: {
          tableKey: 'pictures',
          order: ['table', 'tiles'],
          imageColumn: 'name',
        },
      },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'A' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const setView = vi.spyOn(controller, 'setView')
    try {
      expect(typeof window).toBe('undefined')
      expect(typeof document).toBe('undefined')
      const html = renderToStaticMarkup(
        <HilosViewportTable
          controller={controller}
          cells={{ name: () => 'A' }}
        />,
      )
      const opening = (choice: string) =>
        html.match(
          new RegExp(`<button[^>]*data-id="hilos-table-view-${choice}"[^>]*>`),
        )?.[0] ?? ''
      expect(html).toContain('aria-label="Table view"')
      expect(html.match(/data-id="hilos-table-view"/g)).toHaveLength(1)
      expect(opening('table')).toContain('aria-pressed="true"')
      expect(opening('tiles')).toContain('aria-pressed="false"')
      expect(html.indexOf('hilos-table-view-table')).toBeLessThan(
        html.indexOf('hilos-table-view-tiles'),
      )
      expect(setView).not.toHaveBeenCalled()
    } finally {
      controller.dispose()
      vi.restoreAllMocks()
    }
  })

  it('renders declared tiles on their opening view without storage, effects or geometry, under ids of their own', () => {
    const controller = new TableViewportController<{ name: string }>({
      resolve: (row) => ({ name: String(row.slots.name) }),
      sendViewport: () => {},
      frame: {
        title: 'Pictures',
        columns: [
          { key: 'name', label: 'Name' },
          { key: 'picture', label: 'Picture' },
          { key: 'note', label: 'Note', detail: true },
        ],
        views: {
          tableKey: 'pictures',
          order: ['tiles', 'table'],
          imageColumn: 'picture',
        },
      },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'A' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.expandRow('a', true)
    const register = vi.fn(controller.geometry.register)
    Object.defineProperty(controller, 'geometry', { value: { register } })
    const storage = {
      getItem: vi.fn(() =>
        JSON.stringify({ version: 1, views: { pictures: 'table' } }),
      ),
      setItem: vi.fn(),
    }
    vi.stubGlobal('localStorage', storage)
    const setView = vi.spyOn(controller, 'setView')
    try {
      expect(typeof window).toBe('undefined')
      expect(typeof document).toBe('undefined')
      const html = renderToStaticMarkup(
        <StrictMode>
          <HilosViewportTable
            controller={controller}
            cells={{
              name: (row) => row.name,
              picture: (row) => <img src="/a.png" alt={row.name} />,
            }}
            details={{ note: () => 'Noted' }}
          />
        </StrictMode>,
      )
      const tiles =
        html.match(/<div class="([^"]*)" data-id="hilos-table-tiles"/)?.[1] ??
        ''
      expect(tiles.split(' ')).not.toContain('d-none')
      expect(html).toContain('data-id="hilos-table-tile-a"')
      expect(html).toContain('data-id="hilos-table-tile-image-a"')
      expect(html).toContain('data-id="hilos-table-card-a"')
      const panels = [
        ...html.matchAll(
          /id="([^"]+)"[^>]*data-id="hilos-table-row-detail-a"/g,
        ),
      ].map((match) => match[1])
      const controls = [...html.matchAll(/aria-controls="([^"]+)"/g)].map(
        (match) => match[1],
      )
      expect(panels).toHaveLength(3)
      expect(new Set(panels).size).toBe(3)
      expect(new Set(controls)).toEqual(new Set(panels))
      expect(storage.getItem).not.toHaveBeenCalled()
      expect(storage.setItem).not.toHaveBeenCalled()
      expect(setView).not.toHaveBeenCalled()
      expect(register).not.toHaveBeenCalled()
    } finally {
      vi.unstubAllGlobals()
      controller.dispose()
      vi.restoreAllMocks()
    }
  })

  it('renders text context and reading hooks without browser APIs or committed registrations', () => {
    const controller = new TableViewportController<unknown>({
      resolve: (row) => row.slots,
      sendViewport: () => {},
      frame: {
        columns: [{ key: 'name', label: 'Name', heightContent: 'parts' }],
        rowHeight: { mode: 'lines', count: 2 },
      },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      1,
      true,
      null,
      null,
      10,
    )
    const registration = controller.geometry.register(() => ({ records: [] }))
    const record = registration.measurement('a', 'wide')!.record
    const geometry = vi.fn(controller.geometry.register)
    Object.defineProperty(controller, 'geometry', {
      value: { register: geometry },
    })
    const command = vi.spyOn(controller, 'expandText')
    const counts: number[] = []
    function Text({ projection }: { projection: 'wide' | 'card' }) {
      const context = useTableTextContext({
        controller,
        readRecord: () => record,
        columnKey: 'name',
        label: 'Name',
        heightContent: 'parts',
        projection,
      })
      counts.push(context.regions)
      useTableTextRegion(context)
      const target = useRef<HTMLDivElement>(null)
      const content = useRef<HTMLDivElement>(null)
      const reading = useTableTextReading(context, target, content, true)
      expect(reading.overflow).toBeNull()
      expect(reading.showControl).toBe(false)
      return (
        <HilosTableTextContext.Provider value={context}>
          <div
            id={reading.contentId}
            className={reading.textClass}
            ref={target}
          >
            <div ref={content}>Text</div>
          </div>
        </HilosTableTextContext.Provider>
      )
    }
    try {
      expect(typeof window).toBe('undefined')
      expect(typeof document).toBe('undefined')
      expect(typeof HTMLElement).toBe('undefined')
      const html = renderToStaticMarkup(
        <>
          <Text projection="wide" />
          <Text projection="card" />
        </>,
      )
      expect(counts).toEqual([0, 0])
      expect(html).toContain('hilos-table-text-lines-2')
      const ids = [...html.matchAll(/id="([^"]+)"/g)].map((match) => match[1])
      expect(ids).toHaveLength(2)
      expect(new Set(ids).size).toBe(2)
      expect(ids[0]).toMatch(/-wide$/)
      expect(ids[1]).toMatch(/-card$/)
      expect(geometry).not.toHaveBeenCalled()
      expect(command).not.toHaveBeenCalled()
    } finally {
      registration.dispose()
      controller.dispose()
      vi.restoreAllMocks()
    }
  })

  it('renders a closed modal without a browser document', () => {
    const html = renderToStaticMarkup(<HilosModal open={false} />)

    expect(html).toBe('')
  })

  it('renders only the exact project License document without a browser document', () => {
    const html = renderToStaticMarkup(
      <HilosLicensePage inventory={inventory} />,
    )

    expect(html).toContain('Project license')
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html.match(/<h1\b/g) ?? []).toHaveLength(1)
    expect(html).toContain('data-id="hilos-page-head-title">License</h1>')
    expect(html).toContain('class="bi bi-journal-code"')
    expect(html).toContain(
      'The license of this project, and of everything it is built on.',
    )
    expect(html).not.toContain('data-id="hilos-viewport-table"')
    expect(html).not.toContain('data-id="license-package-open"')
    expect(html).not.toContain('data-id="hilos-table-action-copy"')
    expect(html).not.toContain('data-id="license-copy-status"')
    expect(html).not.toContain('data-id="license-text-missing"')
  })

  it('renders the complete Dependencies table and actions without browser globals', () => {
    expect(typeof window).toBe('undefined')
    expect(typeof document).toBe('undefined')
    const html = renderToStaticMarkup(
      <StrictMode>
        <HilosLicenseDependenciesPage inventory={inventory} />
      </StrictMode>,
    )

    expect(html).toContain('data-id="hilos-page-head-title">License</h1>')
    expect(html).toContain('data-id="hilos-viewport-table"')
    expect(html).toContain('data-id="hilos-table-count">1 – 1 of 1</span>')
    expect(html).toContain('data-id="license-package-open"')
    expect(html.match(/data-id="license-package-open"/g)).toHaveLength(2)
    expect(html).toContain('react')
    expect(html).toContain('19.2.7')
    expect(html).toContain('data-id="hilos-table-action-copy"')
    expect(html).toContain('Copy the list')
    expect(html).toContain('data-id="hilos-table-action-download"')
    expect(html).toContain('Download as a file')
    expect(html).toContain('data-id="license-copy-status"')
    expect(html).not.toContain('data-id="hilos-table-loading"')
    expect(html.match(/<h1\b/g) ?? []).toHaveLength(1)
  })
})
