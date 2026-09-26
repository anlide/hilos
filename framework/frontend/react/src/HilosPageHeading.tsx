import { useContext, useId } from 'react'
import { hilosCrumbLinks } from '@hilos/core'
import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** A catalog heading for the current page, with its breadcrumb and lead. */
export function HilosPageHeading({
  dataId = 'hilos-page-title',
}: {
  dataId?: string
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosPageHeading requires a HilosRouterContext provider.')
  const headingId = useId()
  const identity = useSignal(router.pageIdentity)
  const route = useSignal(router.currentRoute)
  const crumbs = hilosCrumbLinks(
    identity?.breadcrumb ?? [],
    route.params,
    router.resolvePath,
  )
  return identity ? (
    <>
      {crumbs.length > 1 ? <HilosBreadcrumb crumbs={crumbs} /> : null}
      <h1 id={headingId} className="h4 mb-1" data-id={dataId}>
        {identity.label}
      </h1>
      {identity.lead ? (
        <p className="text-body-secondary">{identity.lead}</p>
      ) : null}
    </>
  ) : (
    <div className="placeholder-glow mb-3" data-id={`${dataId}-skeleton`}>
      <span className="placeholder col-3 d-block mb-2 rounded" />
      <span className="placeholder col-6 d-block rounded" />
    </div>
  )
}
