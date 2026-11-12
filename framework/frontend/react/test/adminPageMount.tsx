import { act, cleanup, render } from '@testing-library/react'
import { StrictMode, useContext, useEffect } from 'react'
import type {
  AdminPageInput,
  AdminPagePart,
  AdminPageView,
} from '../../test-support/adminPageFixtures.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosAdminViewModeContext, useLookOnly } from '../src/hilosLookOnly.js'
import { HilosPageErrorBoundaryContext } from '../src/hilosPageErrorBoundaryContext.js'
import { HilosPageHeadingIdContext } from '../src/hilosPageHeadingContext.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Stable component identity keeps the actual modal mount across live errors. */
function ModalsProbe({ mounted }: { mounted: () => void }) {
  const headingId = useContext(HilosPageHeadingIdContext)
  const viewMode = useContext(HilosAdminViewModeContext)
  useEffect(mounted, [mounted])
  return (
    <span data-id="modals-probe">{`${headingId} ${String(viewMode)}`}</span>
  )
}

/** Consume the actual heading provider during native child render. */
function HeadingProbe() {
  const headingId = useContext(HilosPageHeadingIdContext)
  return <span data-id="heading-probe">{headingId}</span>
}

/** Read both the page's context and the control's look-only API. */
function ViewModeProbe() {
  const viewMode = useContext(HilosAdminViewModeContext)
  const lookOnly = useLookOnly()
  return (
    <>
      <span data-id="view-mode-probe">{String(viewMode)}</span>
      <span data-id="look-only-probe">{String(lookOnly.locked)}</span>
      <span data-id="look-only-described">{lookOnly.describedBy}</span>
    </>
  )
}

/** Translate the shell's controlled inputs to real React props and children. */
export async function mountAdminPage(
  input: AdminPageInput,
  strict = false,
): Promise<AdminPageView> {
  const host = document.body.appendChild(document.createElement('div'))
  let mounted: Pick<ReturnType<typeof render>, 'rerender'> | undefined
  const part = (content: AdminPagePart | undefined) =>
    content === undefined ? undefined : (
      <span data-id={content.dataId}>{content.text}</span>
    )
  const element = ({
    router,
    body,
    contextProbe,
    table,
    headContent,
    deletion,
    ...props
  }: AdminPageInput) => (
    <HilosRouterContext.Provider value={router}>
      <HilosPageErrorBoundaryContext.Provider value={deletion?.retain}>
        <HilosAdminPage
          {...props}
          preserveModalDraftsOnDelete={deletion?.preserve}
          modals={
            deletion === undefined ? undefined : (
              <ModalsProbe mounted={deletion.modalsMounted} />
            )
          }
          headVisual={part(headContent?.visual)}
          headMeta={part(headContent?.meta)}
          headBadges={part(headContent?.badges)}
          headDescription={part(headContent?.description)}
          headSkeletonVisual={part(headContent?.skeletonVisual)}
        >
          {deletion !== undefined ? (
            <button type="button" data-id="opener">
              Rename
            </button>
          ) : body !== undefined ? (
            <p data-id={body.dataId}>{body.text}</p>
          ) : contextProbe === 'heading' ? (
            <HeadingProbe />
          ) : contextProbe === 'view-mode' ? (
            <ViewModeProbe />
          ) : table !== undefined ? (
            <HilosViewportTable
              controller={table}
              cells={{ name: (row) => row.name }}
            />
          ) : undefined}
        </HilosAdminPage>
      </HilosPageErrorBoundaryContext.Provider>
    </HilosRouterContext.Provider>
  )
  function destroy(): void {
    try {
      cleanup()
    } finally {
      host.remove()
    }
  }
  try {
    const tree = (next: AdminPageInput) =>
      strict ? <StrictMode>{element(next)}</StrictMode> : element(next)
    mounted = render(tree(input), { container: host })
    return {
      root: host,
      async update(next) {
        mounted!.rerender(tree(next))
      },
      async flush(change) {
        act(change)
      },
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    destroy()
    throw error
  }
}
