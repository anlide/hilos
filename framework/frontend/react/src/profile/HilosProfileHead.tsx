// The shared profile identity and catalog navigation, with one photo window
// owned by this mount.
import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  createHilosProfileHeadStore,
  createHilosProfilePhotoFlow,
  HILOS_PROFILE_PHOTO_COPY as PHOTO_COPY,
  HILOS_PROFILE_ROOT_COPY as COPY,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
  type HilosProfilePhotoFlow,
} from '@hilos/core/base'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosPageHead } from '../HilosPageHead.js'
import { HilosRouterContext } from '../hilosRouterContext.js'
import { useSignal } from '../useSignal.js'
import { HilosProfilePhoto } from './HilosProfilePhoto.js'

/** Props for the shared profile head. Keep context and store stable across renders. */
export interface HilosProfileHeadProps {
  /** The profile page's connection, scopes and tracked actions. */
  readonly context: HilosProfilePageContext
  /** A started store borrowed from the root; its caller owns its lifetime. */
  readonly store?: HilosProfileHeadStore
}

/**
 * Draw the person's one heading, photo window and live catalog page links.
 *
 * @param props The profile context and an optional caller-owned head store.
 */
export function HilosProfileHead({ context, store }: HilosProfileHeadProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosProfileHead requires a HilosRouterContext provider.')

  const head = useMemo(
    () => store ?? createHilosProfileHeadStore(context),
    [context, store],
  )
  const identity = useSignal(head.identity)
  const route = useSignal(router.currentRoute)
  const name = useSignal(head.name)
  const photo = useSignal(head.photo)
  const photoEnabled = useSignal(head.photoEnabled)
  const verifiedEmail = useSignal(head.verifiedEmail)
  const passkeyOnly = useSignal(head.passkeyOnly)
  const [photoOwner, setPhotoOwner] = useState<{
    context: HilosProfilePageContext
    head: HilosProfileHeadStore
    flow: HilosProfilePhotoFlow
  } | null>(null)
  // Photo disposal is final. Every effect setup, including StrictMode replay,
  // creates its own flow; a replacement context or store hides the old adapter.
  // Publishing that committed owner also rereads the name binding before paint.
  useLayoutEffect(() => {
    if (store === undefined) head.start()
    const flow = createHilosProfilePhotoFlow(context, head.photo)
    setPhotoOwner({ context, head, flow })
    return () => {
      flow.dispose()
      if (store === undefined) head.dispose()
    }
  }, [context, head, store])
  const photoFlow =
    photoOwner?.context === context && photoOwner.head === head
      ? photoOwner.flow
      : null

  return (
    <div data-id="profile-identity">
      <HilosPageHead
        identity={identity}
        page={route.page ?? ''}
        params={route.params}
        resolvePath={router.resolvePath}
        kind="entity"
        tabsLabel="Profile pages"
        visual={
          photoEnabled ? (
            <button
              type="button"
              data-id="profile-photo-open"
              className="btn p-0 border-0 bg-transparent position-relative"
              title={PHOTO_COPY.open}
              aria-label={PHOTO_COPY.open}
              onClick={() => photoFlow?.open()}
            >
              <HilosAvatar name={name} photo={photo} size="lg" />
              <span
                className="position-absolute hilos-avatar-camera bg-body border rounded-circle d-inline-flex align-items-center justify-content-center text-body-emphasis"
                aria-hidden="true"
              >
                <i className="bi bi-camera" />
              </span>
            </button>
          ) : (
            <HilosAvatar name={name} photo={photo} size="lg" />
          )
        }
        description={
          passkeyOnly ? (
            <p
              className="small text-warning-emphasis mb-0"
              data-id="profile-sign-in-passkey-only-line"
            >
              <i
                className="bi bi-exclamation-triangle me-1"
                aria-hidden="true"
              />
              {COPY.passkeyOnly}
            </p>
          ) : verifiedEmail ? (
            <p
              className="small text-body-secondary mb-0"
              data-id="profile-identity-email"
            >
              {verifiedEmail}
            </p>
          ) : null
        }
      />
      {photoEnabled && photoFlow ? (
        <HilosProfilePhoto flow={photoFlow} />
      ) : null}
    </div>
  )
}
