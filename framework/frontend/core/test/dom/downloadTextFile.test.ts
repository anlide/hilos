// The core test project runs with no browser at all, which is also the first
// case here: the seam is called during no render, but a public page's module
// graph is loaded by a server renderer, so absence of a document must be an
// answer rather than a crash. The rest stands globals up by hand.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { downloadTextFile } from '../../src/dom/downloadTextFile.js'

interface FakeAnchor {
  href: string
  download: string
  click: ReturnType<typeof vi.fn>
  remove: ReturnType<typeof vi.fn>
}

/** A document that records the one anchor the seam builds. */
function stubDocument(): FakeAnchor {
  const anchor: FakeAnchor = {
    href: '',
    download: '',
    click: vi.fn(),
    remove: vi.fn(),
  }
  vi.stubGlobal('document', {
    createElement: vi.fn(() => anchor),
    body: { appendChild: vi.fn() },
  })

  return anchor
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('downloadTextFile', () => {
  it('returns false without throwing where there is no document', () => {
    expect(downloadTextFile('licenses.csv', 'package\r\n', 'text/csv')).toBe(
      false,
    )
  })

  it('returns false where object URLs cannot be made', () => {
    stubDocument()
    vi.stubGlobal('URL', {})

    expect(downloadTextFile('licenses.csv', 'package\r\n', 'text/csv')).toBe(
      false,
    )
  })

  it('clicks a download anchor and revokes the object URL it used', () => {
    const anchor = stubDocument()
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn(() => 'blob:hilos/1'),
      revokeObjectURL,
    })

    expect(
      downloadTextFile('licenses.csv', 'package\r\n', 'text/csv;charset=utf-8'),
    ).toBe(true)
    expect(anchor.download).toBe('licenses.csv')
    expect(anchor.href).toBe('blob:hilos/1')
    expect(anchor.click).toHaveBeenCalledOnce()
    expect(anchor.remove).toHaveBeenCalledOnce()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:hilos/1')
  })

  it('returns false and still revokes when the click throws', () => {
    const anchor = stubDocument()
    anchor.click.mockImplementation(() => {
      throw new Error('denied')
    })
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn(() => 'blob:hilos/2'),
      revokeObjectURL,
    })

    expect(downloadTextFile('licenses.csv', 'package\r\n', 'text/csv')).toBe(
      false,
    )
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:hilos/2')
  })
})
