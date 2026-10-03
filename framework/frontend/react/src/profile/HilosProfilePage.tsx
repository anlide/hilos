import { useContext, useEffect, useMemo } from 'react'
import {
  computedSignal,
  createHilosProfileEmailChangeFlow,
  createHilosProfilePhotoFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  hilosChildLinks,
  HilosPages,
  HILOS_PROFILE_ROOT_COPY as COPY,
  HILOS_PROFILE_PHOTO_COPY as PHOTO_COPY,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosLink } from '../HilosLink.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosRouterContext } from '../hilosRouterContext.js'
import { useSignal } from '../useSignal.js'
import { HilosAccountDeletion } from './HilosAccountDeletion.js'
import { HilosProfileEmailChange } from './HilosProfileEmailChange.js'
import { HilosProfileRename } from './HilosProfileRename.js'
import { HilosProfilePhoto } from './HilosProfilePhoto.js'

/**
 * Pass the same two objects on every render — module constants or memoized: a
 * new object rebuilds the page's stores and closes a window that is open.
 */
export interface HilosProfilePageProps {
  /** The project's connection, scopes and action lifecycle. */
  context: HilosProfilePageContext
  /** What only the project knows: the name, its rename, its lists. */
  binding: HilosProfileBinding
}

/**
 * The profile root (HIL-1169): the person's own line, the Account rows, a row
 * per catalog section with its live summary, and the danger zone.
 */
export function HilosProfilePage({ context, binding }: HilosProfilePageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosProfilePage requires a HilosRouterContext provider.')
  const identity = useSignal(router.pageIdentity)
  const route = useSignal(router.currentRoute)
  const sections = hilosChildLinks(
    identity?.children ?? [],
    route.params,
    router.resolvePath,
  )
  const root = useMemo(
    () => createHilosProfileRootStore(context, binding),
    [context, binding],
  )
  useEffect(() => {
    root.start()
    return () => root.dispose()
  }, [root])
  const rename = useMemo(
    () =>
      binding.rename === null
        ? null
        : createHilosProfileRenameFlow(context, binding.name, binding.rename),
    [context, binding],
  )
  useEffect(() => () => rename?.dispose(), [rename])
  const email = useMemo(
    () => createHilosProfileEmailChangeFlow(context),
    [context],
  )
  useEffect(() => () => email.dispose(), [email])
  const photoFlow = useMemo(
    () => createHilosProfilePhotoFlow(context, root.photo),
    [context, root],
  )
  useEffect(() => () => photoFlow.dispose(), [photoFlow])
  // Change waits while the window asks whether the confirmation is needed.
  const renameOpeningSignal = useMemo(
    () => computedSignal(() => rename?.stepUp.busy.get() ?? false),
    [rename],
  )
  const renameOpening = useSignal(renameOpeningSignal)
  const signedIn = useSignal(root.signedIn)
  const photoEnabled = useSignal(root.photoEnabled)
  const photo = useSignal(root.photo)
  const summaries = useSignal(root.summaries)
  const verifiedEmail = useSignal(root.verifiedEmail)
  const passkeyOnly = useSignal(root.passkeyOnly)
  const name = useSignal(binding.name)
  const emailOpening = useSignal(email.busy)

  // Nobody signed in, or the session is not known yet: a placeholder, never page
  // content. The shell's auth gate mounts the sign-in surface in place once the
  // AUTHENTICATED guard answers 401.
  if (!signedIn)
    return (
      <p className="text-body-secondary" data-id="profile-loading">
        {COPY.loading}
      </p>
    )
  return (
    <section data-id="profile-view">
      <HilosPageHeading />
      {name ? (
        <div
          className="d-flex align-items-center gap-3 mt-3"
          data-id="profile-identity"
        >
          {photoEnabled ? (
            <button
              type="button"
              data-id="profile-photo-open"
              className="btn p-0 border-0 bg-transparent position-relative"
              title={PHOTO_COPY.open}
              aria-label={PHOTO_COPY.open}
              onClick={() => photoFlow.open()}
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
          )}
          <div className="flex-grow-1 text-break">
            <div className="h5 mb-0" data-id="profile-identity-name">
              {name}
            </div>
            {verifiedEmail ? (
              <div
                className="small text-body-secondary"
                data-id="profile-identity-email"
              >
                {verifiedEmail}
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
      <h2 className="h6 text-uppercase text-body-secondary mt-4 mb-2">
        {COPY.account}
      </h2>
      {name ? (
        <div data-id="profile-detail">
          <div className="d-flex align-items-center gap-3 py-3 border-bottom">
            <i
              className="bi bi-person fs-5 text-body-secondary"
              aria-hidden="true"
            ></i>
            <div className="flex-grow-1 text-break">
              <div className="fw-semibold small">{COPY.name}</div>
              <div className="small text-body-secondary" data-id="profile-name">
                {name}
              </div>
            </div>
            {rename ? (
              <LoadingButton
                className="btn-outline-secondary btn-sm"
                loading={renameOpening}
                data-id="profile-edit"
                onClick={() => void rename.open()}
              >
                {COPY.change}
              </LoadingButton>
            ) : null}
          </div>
          {verifiedEmail ? (
            <div className="d-flex align-items-center gap-3 py-3 border-bottom">
              <i
                className="bi bi-envelope fs-5 text-body-secondary"
                aria-hidden="true"
              ></i>
              <div className="flex-grow-1 text-break">
                <div className="fw-semibold small">{COPY.email}</div>
                <div
                  className="small text-body-secondary"
                  data-id="profile-email"
                >
                  {COPY.verified.replace('{address}', verifiedEmail)}
                </div>
              </div>
              <LoadingButton
                className="btn-outline-secondary btn-sm"
                loading={emailOpening}
                data-id="profile-email-change"
                onClick={() => void email.open(verifiedEmail)}
              >
                {COPY.change}
              </LoadingButton>
            </div>
          ) : null}
        </div>
      ) : (
        <p className="text-body-secondary" data-id="profile-loading">
          {COPY.loading}
        </p>
      )}
      <section className="mt-4" aria-labelledby="profile-sections-heading">
        <h2
          id="profile-sections-heading"
          className="h6 text-uppercase text-body-secondary mb-2"
        >
          {COPY.sections}
        </h2>
        {sections.map((section) => (
          <div
            key={section.page}
            className="d-flex align-items-center gap-3 py-3 border-bottom"
            data-id="profile-section"
          >
            <i
              className={`bi fs-5 text-body-secondary ${hilosProfileSectionIcon(section.page)}`}
              aria-hidden="true"
            ></i>
            <div className="flex-grow-1 text-break">
              <div className="fw-semibold small">{section.label}</div>
              <div
                className="small text-body-secondary"
                data-id={`${hilosProfileSectionId(section.page)}-summary`}
              >
                {summaries[section.page] ?? ''}
              </div>
              {section.page === HilosPages.PROFILE_SIGN_IN && passkeyOnly ? (
                <div
                  className="small text-warning-emphasis"
                  data-id="profile-sign-in-passkey-only-line"
                >
                  <i
                    className="bi bi-exclamation-triangle me-1"
                    aria-hidden="true"
                  ></i>
                  {COPY.passkeyOnly}
                </div>
              ) : null}
            </div>
            <HilosLink
              to={section.to}
              className="btn btn-sm btn-outline-secondary"
              data-id={`${hilosProfileSectionId(section.page)}-open`}
            >
              {COPY.open}
            </HilosLink>
          </div>
        ))}
      </section>
      <HilosAccountDeletion context={context} />
      {rename ? <HilosProfileRename flow={rename} /> : null}
      {photoEnabled ? <HilosProfilePhoto flow={photoFlow} /> : null}
      <HilosProfileEmailChange flow={email} />
    </section>
  )
}
