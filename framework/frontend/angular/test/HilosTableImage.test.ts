import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosTableCell } from '../src/HilosTableCell.js'
import type { HilosTableCellContext } from '../src/HilosTableCell.js'
import { HilosTableImage } from '../src/HilosTableImage.js'

interface Row {
  name: string
  picture: string | null
}

const ROWS = {
  a: { name: 'Harbor', picture: 'https://files.example/harbor.jpg' },
  b: { name: 'Draft', picture: null },
} satisfies Record<string, Row>

/** Which of the page's templates the image column hands the tile, if any. */
type Pick = 'picture' | 'comments' | 'blank' | 'none'

/** A page declaring its image column the way a real one does: an ordinary cell template. */
@Component({
  imports: [HilosTableImage, HilosTableCell],
  template: `
    <ng-template #picture hilosTableCell="picture" let-row let-rowKey="rowKey">
      @if (shown() && row.picture) {
        <img
          [src]="row.picture"
          [alt]="'Picture of ' + row.name"
          [attr.data-row-key]="rowKey"
        />
      }
    </ng-template>
    <ng-template #comments hilosTableCell="picture"
      ><!-- the page draws nothing here --></ng-template
    >
    <ng-template #blank hilosTableCell="picture">{{ caption() }}</ng-template>
    @if (mounted()) {
      <hilos-table-image
        [template]="
          pick() === 'picture'
            ? picture
            : pick() === 'comments'
              ? comments
              : pick() === 'blank'
                ? blank
                : null
        "
        [cellContext]="context()"
      />
    }
  `,
})
class ImageHost {
  readonly pick = signal<Pick>('picture')
  readonly shown = signal(true)
  readonly caption = signal('  ')
  readonly mounted = signal(true)
  readonly context = signal<HilosTableCellContext<Row>>({
    $implicit: ROWS.a,
    rowKey: 'a',
  })
}

async function settle(f: ComponentFixture<ImageHost>) {
  f.detectChanges()
  await f.whenStable()
  f.detectChanges()
  await f.whenStable()
}

async function mount(setup: (host: ImageHost) => void = () => undefined) {
  const f = TestBed.createComponent(ImageHost)
  setup(f.componentInstance)
  const root = f.nativeElement as HTMLElement
  document.body.append(root)
  await settle(f)
  return { f, root, host: f.componentInstance }
}

/** The tile's frame, found by its hook. */
function frame(root: HTMLElement, rowKey: string): HTMLElement {
  const found = root.querySelector<HTMLElement>(
    `[data-id="hilos-table-tile-image-${rowKey}"]`,
  )
  expect(found).not.toBeNull()
  return found!
}

/** What a reader is told inside the frame, leaving out what is hidden from them. */
function readable(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? ''
  if (node instanceof Element && node.getAttribute('aria-hidden') === 'true')
    return ''
  return [...node.childNodes].map(readable).join('')
}

/** The frame says "No image" and nothing else, with no picture beside it. */
function expectNoImage(node: HTMLElement) {
  expect(readable(node).replace(/\s+/g, ' ').trim()).toBe('No image')
  expect(node.querySelector('img')).toBeNull()
  // Whatever the fallback hides from a reader is decoration, never its words.
  for (const hidden of node.querySelectorAll('[aria-hidden="true"]'))
    expect(hidden.textContent?.trim()).toBe('')
}

/** The frame holds the page's one picture of this row, and no fallback beside it. */
function expectPicture(node: HTMLElement, rowKey: string, row: Row) {
  const images = node.ownerDocument.querySelectorAll('img')
  expect(images).toHaveLength(1)
  const image = images[0]!
  expect(node.contains(image)).toBe(true)
  expect(image.getAttribute('src')).toBe(row.picture)
  expect(image.alt).toBe(`Picture of ${row.name}`)
  expect(image.getAttribute('data-row-key')).toBe(rowKey)
  expect(readable(node)).not.toContain('No image')
  return image
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

describe('HilosTableImage', () => {
  it('stamps the page template once with the cell context and no fallback', async () => {
    const f = await mount()
    const image = expectPicture(frame(f.root, 'a'), 'a', ROWS.a)

    // A new context for the same row passes through; the stamp is kept, not redone.
    f.host.context.set({ $implicit: ROWS.a, rowKey: 'a' })
    await settle(f.f)
    expect(expectPicture(frame(f.root, 'a'), 'a', ROWS.a)).toBe(image)
  })

  it('says No image when the column has no template', async () => {
    const f = await mount((host) => host.pick.set('none'))
    expectNoImage(frame(f.root, 'a'))
  })

  it.each([
    ['comments only', 'comments'],
    ['blank text', 'blank'],
  ] as const)(
    'says No image when the template renders %s',
    async (_name, pick) => {
      const f = await mount((host) => host.pick.set(pick))
      expectNoImage(frame(f.root, 'a'))
    },
  )

  it.each([
    ['has no template', 'none'],
    ['renders comments only', 'comments'],
  ] as const)(
    'keeps No image standing once it arrives where the column %s',
    async (_name, pick) => {
      const f = await mount((host) => host.pick.set(pick))
      const node = frame(f.root, 'a')
      expectNoImage(node)
      const fallback = [...node.children].find((child) =>
        readable(child).includes('No image'),
      )
      expect(fallback).toBeDefined()
      const removed: Node[] = []
      const watch = new MutationObserver((records) => {
        for (const record of records) removed.push(...record.removedNodes)
      })
      watch.observe(node, { childList: true, subtree: true })

      // The fallback's own arrival is a change inside the frame the component
      // watches; reading it as content would take the fallback away, and its
      // leaving would bring it back.
      for (let round = 0; round < 3; round++) await settle(f.f)
      watch.disconnect()
      expect(removed).toEqual([])
      expect(node.contains(fallback!)).toBe(true)
      expectNoImage(frame(f.root, 'a'))
    },
  )

  it('follows content that a template shows and hides without any input changing', async () => {
    const f = await mount((host) => host.shown.set(false))
    expectNoImage(frame(f.root, 'a'))

    f.host.shown.set(true)
    await settle(f.f)
    expectPicture(frame(f.root, 'a'), 'a', ROWS.a)

    f.host.shown.set(false)
    await settle(f.f)
    expectNoImage(frame(f.root, 'a'))

    f.host.pick.set('blank')
    await settle(f.f)
    expectNoImage(frame(f.root, 'a'))
    f.host.caption.set('Picture coming')
    await settle(f.f)
    expect(readable(frame(f.root, 'a')).trim()).toBe('Picture coming')
  })

  it('reads again when the template or the row is replaced', async () => {
    const f = await mount((host) => host.pick.set('comments'))
    expectNoImage(frame(f.root, 'a'))

    // A page's own local change detection draws the new stamp before the
    // application's render hooks read it: the answer read for the old template
    // is not trusted for the new one, so the fallback is not left beside it.
    f.host.pick.set('picture')
    f.f.changeDetectorRef.detectChanges()
    expect(frame(f.root, 'a').querySelector('img')).not.toBeNull()
    expect(readable(frame(f.root, 'a'))).not.toContain('No image')
    await settle(f.f)
    expectPicture(frame(f.root, 'a'), 'a', ROWS.a)

    f.host.context.set({ $implicit: ROWS.b, rowKey: 'b' })
    await settle(f.f)
    expectNoImage(frame(f.root, 'b'))

    f.host.context.set({ $implicit: ROWS.a, rowKey: 'a' })
    await settle(f.f)
    expectPicture(frame(f.root, 'a'), 'a', ROWS.a)
  })

  it('drops a late observer delivery after a replacement and after it is gone', async () => {
    const observers: { nodes: Set<Node>; callback: () => void }[] = []
    class Observer {
      readonly state: (typeof observers)[number]
      constructor(callback: () => void) {
        this.state = { nodes: new Set(), callback }
        observers.push(this.state)
      }
      observe(node: Node) {
        this.state.nodes.add(node)
      }
      disconnect() {
        this.state.nodes.clear()
      }
    }
    vi.stubGlobal('MutationObserver', Observer)
    const f = await mount()
    expectPicture(frame(f.root, 'a'), 'a', ROWS.a)
    expect(observers).toHaveLength(1)
    const [first] = observers
    expect(first!.nodes.size).toBe(1)
    expect(
      [...first!.nodes].every((node) => frame(f.root, 'a').contains(node)),
    ).toBe(true)

    f.host.context.set({ $implicit: ROWS.b, rowKey: 'b' })
    await settle(f.f)
    expectNoImage(frame(f.root, 'b'))
    expect(first!.nodes.size).toBe(0)
    expect(observers).toHaveLength(2)

    // A delivery queued for the replaced stamp must not speak for the new one.
    first!.callback()
    await settle(f.f)
    expectNoImage(frame(f.root, 'b'))

    f.host.mounted.set(false)
    await settle(f.f)
    expect(f.root.querySelector('[data-id^="hilos-table-tile-image-"]')).toBe(
      null,
    )
    expect(observers.every((observer) => observer.nodes.size === 0)).toBe(true)
    const nodes = vi.spyOn(Node.prototype, 'childNodes', 'get')
    for (const observer of observers) observer.callback()
    expect(nodes).not.toHaveBeenCalled()
  })
})
