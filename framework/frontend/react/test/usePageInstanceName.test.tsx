import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  Component,
  StrictMode,
  Suspense,
  use,
  useLayoutEffect,
  useMemo,
  type ReactNode,
} from 'react'
import { createRoot } from 'react-dom/client'
import { renderToStaticMarkup } from 'react-dom/server'
import {
  ScopeManager,
  bindPageInstanceName,
  createSignal,
  pageIdentity,
  type ReadonlySignal,
} from '@hilos/core/base'
import { useSignal } from '../src/useSignal.js'
import { usePageInstanceName } from '../src/usePageInstanceName.js'

const OWNER = 'entity'
const TAB = 'entity_names'
const PARENT = 'entities'

type Name = ReadonlySignal<string | undefined>

/** Open the owner's names tab as its page_response would lay it out. */
function openTab(scopes: ScopeManager, key = TAB): ScopeManager {
  const scope = scopes.openPage(key)
  scope.data.set('pageLabel', 'Names')
  scope.data.set('pageLead', 'The names of one entity.')
  scope.data.set('pageBreadcrumb', [
    { page: PARENT, label: 'Entities' },
    { page: OWNER, label: 'Entity' },
    { page: key, label: 'Names' },
  ])
  scope.data.set('pageHeadOwner', {
    page: OWNER,
    label: 'Entity',
    lead: 'One entity.',
    icon: null,
  })
  return scopes
}

/** The owner caption the head and the title would show right now. */
function ownerLabel(scopes: ScopeManager): string | undefined {
  return pageIdentity(scopes).get()?.pageHeadOwner?.label
}

/** The breadcrumb captions right now, root first. */
function crumbs(scopes: ScopeManager): string[] | undefined {
  return pageIdentity(scopes)
    .get()
    ?.breadcrumb.map((crumb) => crumb.label)
}

/** Reads the identity in render only: it never subscribes to anything. */
function PlainReader({ scopes }: { scopes: ScopeManager }) {
  return (
    <h1 data-testid="plain">
      {pageIdentity(scopes).get()?.pageHeadOwner?.label}
    </h1>
  )
}

/** Mirrors the identity through the React signal bridge, like the shell. */
function SignalReader({ scopes }: { scopes: ScopeManager }) {
  const identity = useSignal(useMemo(() => pageIdentity(scopes), [scopes]))
  return <h2 data-testid="mirror">{identity?.pageHeadOwner?.label}</h2>
}

interface PageProps {
  scopes: ScopeManager
  name: Name
  page?: string
}

/** A page that binds its name and draws the head readers itself. */
function Page({ scopes, name, page = OWNER }: PageProps) {
  usePageInstanceName(scopes, page, name)
  return (
    <>
      <PlainReader scopes={scopes} />
      <SignalReader scopes={scopes} />
    </>
  )
}

/** Records what the core identity says when its layout effect runs. */
function LayoutProbe({
  scopes,
  seen,
}: {
  scopes: ScopeManager
  seen: (string | undefined)[]
}) {
  useLayoutEffect(() => {
    seen.push(ownerLabel(scopes))
  })
  return null
}

function text(testId: string, root: ParentNode = document): string | null {
  return root.querySelector(`[data-testid="${testId}"]`)?.textContent ?? null
}

/** A promise a test settles by hand. */
function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve = () => {}
  const promise = new Promise<void>((settle) => {
    resolve = settle
  })
  return { promise, resolve }
}

function Suspend({ until }: { until: Promise<void> }) {
  use(until)
  return null
}

function Fail(): never {
  throw new Error('A sibling failed to render.')
}

class Boundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    return this.state.failed ? <p>Failed</p> : this.props.children
  }
}

describe('usePageInstanceName', () => {
  afterEach(cleanup)

  it('binds in the layout phase, follows the source and releases on unmount', () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')
    const seen: (string | undefined)[] = []

    const view = render(
      <>
        <Page scopes={scopes} name={name} />
        <LayoutProbe scopes={scopes} seen={seen} />
      </>,
    )

    // The sibling's first layout effect runs after the hook's: the core
    // already carries the name before any passive effect.
    expect(seen[0]).toBe('Ireland')
    expect(crumbs(scopes)).toEqual(['Entities', 'Ireland', 'Names'])
    expect(text('mirror')).toBe('Ireland')

    act(() => name.set('Renamed'))
    expect(text('mirror')).toBe('Renamed')
    expect(ownerLabel(scopes)).toBe('Renamed')

    view.unmount()
    expect(ownerLabel(scopes)).toBe('Entity')
    expect(crumbs(scopes)).toEqual(['Entities', 'Entity', 'Names'])
    name.set('After unmount')
    expect(ownerLabel(scopes)).toBe('Entity')
  })

  it('rereads the identity below it before the first paint', async () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')
    const container = document.createElement('div')
    document.body.append(container)
    const root = createRoot(container)
    const reactAct = globalThis as typeof globalThis & {
      IS_REACT_ACT_ENVIRONMENT?: boolean
    }
    const actEnvironment = reactAct.IS_REACT_ACT_ENVIRONMENT
    const painted = deferred()
    const firstPaint: Record<string, string | null> = {}

    // The first commit's outermost layout effect queues a microtask. It runs
    // once the task that committed is over, and a browser paints only after
    // that: what it reads is what the first paint shows. Passive effects, and
    // with them the bridge's own subscription, are not owed by then.
    function Frame({ children }: { children: ReactNode }) {
      useLayoutEffect(() => {
        queueMicrotask(() => {
          firstPaint.plain = text('plain', container)
          firstPaint.mirror = text('mirror', container)
          painted.resolve()
        })
      }, [])
      return children
    }

    reactAct.IS_REACT_ACT_ENVIRONMENT = false
    try {
      root.render(
        <Frame>
          <Page scopes={scopes} name={name} />
        </Frame>,
      )
      await painted.promise
    } finally {
      reactAct.IS_REACT_ACT_ENVIRONMENT = actEnvironment
      act(() => root.unmount())
      container.remove()
    }

    // The plain reader subscribes to nothing: only the hook's own pre-paint
    // re-render can have shown it the name.
    expect(firstPaint).toEqual({ plain: 'Ireland', mirror: 'Ireland' })
  })

  it('leaves one registration after a StrictMode replay and none after unmount', () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')

    const view = render(
      <StrictMode>
        <Page scopes={scopes} name={name} />
      </StrictMode>,
    )

    expect(ownerLabel(scopes)).toBe('Ireland')
    expect(text('plain')).toBe('Ireland')
    expect(text('mirror')).toBe('Ireland')
    act(() => name.set('Renamed'))
    expect(ownerLabel(scopes)).toBe('Renamed')

    view.unmount()
    expect(ownerLabel(scopes)).toBe('Entity')
    name.set('After unmount')
    expect(ownerLabel(scopes)).toBe('Entity')
  })

  it('registers nothing for a render React suspends before committing', async () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')
    const data = deferred()

    await act(async () => {
      render(
        <Suspense fallback={<p data-testid="fallback">Loading</p>}>
          <Page scopes={scopes} name={name} />
          <Suspend until={data.promise} />
        </Suspense>,
      )
    })

    expect(text('fallback')).toBe('Loading')
    expect(text('plain')).toBeNull()
    expect(ownerLabel(scopes)).toBe('Entity')

    await act(async () => {
      data.resolve()
      await data.promise
    })
    expect(text('plain')).toBe('Ireland')
    expect(ownerLabel(scopes)).toBe('Ireland')
  })

  it('registers nothing for a render a failing sibling throws away', () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')
    const logged = vi.spyOn(console, 'error').mockImplementation(() => {})

    try {
      render(
        <Boundary>
          <Page scopes={scopes} name={name} />
          <Fail />
        </Boundary>,
      )
    } finally {
      logged.mockRestore()
    }

    expect(text('plain')).toBeNull()
    expect(ownerLabel(scopes)).toBe('Entity')
    expect(crumbs(scopes)).toEqual(['Entities', 'Entity', 'Names'])
  })

  it('registers nothing in a server render', () => {
    const scopes = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')

    const html = renderToStaticMarkup(<Page scopes={scopes} name={name} />)

    expect(html).toContain('Entity')
    expect(html).not.toContain('Ireland')
    expect(ownerLabel(scopes)).toBe('Entity')
  })

  it('moves its registration to a replacement source, page key or scopes', () => {
    const first = openTab(new ScopeManager())
    const second = openTab(new ScopeManager())
    const original = createSignal<string | undefined>('Ireland')
    const replacement = createSignal<string | undefined>('Éire')

    const view = render(<Page scopes={first} name={original} />)
    expect(ownerLabel(first)).toBe('Ireland')

    view.rerender(<Page scopes={first} name={replacement} />)
    expect(ownerLabel(first)).toBe('Éire')
    expect(text('plain')).toBe('Éire')
    act(() => original.set('Stale source'))
    expect(ownerLabel(first)).toBe('Éire')

    view.rerender(<Page scopes={first} name={replacement} page={PARENT} />)
    expect(crumbs(first)).toEqual(['Éire', 'Entity', 'Names'])
    expect(text('plain')).toBe('Entity')

    view.rerender(<Page scopes={second} name={replacement} page={PARENT} />)
    expect(crumbs(first)).toEqual(['Entities', 'Entity', 'Names'])
    expect(crumbs(second)).toEqual(['Éire', 'Entity', 'Names'])

    view.unmount()
    expect(crumbs(second)).toEqual(['Entities', 'Entity', 'Names'])
  })

  it('keeps a later producer on re-render and when the earlier view unmounts', () => {
    const scopes = openTab(new ScopeManager())
    const earlier = createSignal<string | undefined>('Earlier')
    const later = createSignal<string | undefined>('Later')

    const view = render(<Page scopes={scopes} name={earlier} />)
    const disposeLater = bindPageInstanceName(scopes, OWNER, later)
    expect(ownerLabel(scopes)).toBe('Later')

    // Same scopes, page and source: the effect does not run again, so the
    // earlier view does not take the key back.
    view.rerender(<Page scopes={scopes} name={earlier} />)
    expect(ownerLabel(scopes)).toBe('Later')

    view.unmount()
    expect(ownerLabel(scopes)).toBe('Later')
    disposeLater()
    expect(ownerLabel(scopes)).toBe('Entity')
  })

  it('reaches no other application and no other page key', () => {
    const bound = openTab(new ScopeManager())
    const other = openTab(new ScopeManager())
    const name = createSignal<string | undefined>('Ireland')

    render(<Page scopes={bound} name={name} />)

    expect(crumbs(bound)).toEqual(['Entities', 'Ireland', 'Names'])
    expect(ownerLabel(other)).toBe('Entity')
    expect(crumbs(other)).toEqual(['Entities', 'Entity', 'Names'])
  })
})
