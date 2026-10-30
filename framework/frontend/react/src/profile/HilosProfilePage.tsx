import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import {
  computedSignal,
  createHilosProfileEmailChangeFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  createHilosTimeZonePicker,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PROFILE_ROOT_COPY as COPY,
  hilosImpersonation,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosTimeZonePicker,
} from '@hilos/core/base'
import { HilosProfileHead } from './HilosProfileHead.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { HilosAccountDeletion } from './HilosAccountDeletion.js'
import { HilosProfileEmailChange } from './HilosProfileEmailChange.js'
import { HilosProfileRename } from './HilosProfileRename.js'
import { HilosProfileTheme } from './HilosProfileTheme.js'
import { HilosProfileTimeZone } from './HilosProfileTimeZone.js'

/**
 * Pass the same two objects on every render — module constants or memoized: a
 * new object rebuilds the page's stores and closes a window that is open.
 */
export interface HilosProfilePageProps {
  /** The project's connection, scopes and action lifecycle. */
  context: HilosProfilePageContext
  /** What only the project knows: the name and its rename. */
  binding: HilosProfileBinding
}

/**
 * The profile root (HIL-1169): the person's own line, the Account rows (Name,
 * Email, Time zone, Theme), the shared profile head and the danger zone.
 */
export function HilosProfilePage({ context, binding }: HilosProfilePageProps) {
  const root = useMemo(
    () => createHilosProfileRootStore(context, binding),
    [context, binding],
  )
  const rename = useMemo(
    () =>
      binding.rename === null
        ? null
        : createHilosProfileRenameFlow(context, binding.name, binding.rename, {
            remember: true,
          }),
    [context, binding],
  )
  const [, refreshHead] = useState(0)
  useLayoutEffect(() => {
    root.start()
    refreshHead((version) => version + 1)
    return () => root.dispose()
  }, [root])
  useEffect(() => {
    rename?.start()
    return () => rename?.dispose()
  }, [rename])
  const email = useMemo(
    () => createHilosProfileEmailChangeFlow(context),
    [context],
  )
  useEffect(() => () => email.dispose(), [email])
  // Change waits while the window asks whether the confirmation is needed.
  const renameOpeningSignal = useMemo(
    () => computedSignal(() => rename?.stepUp.busy.get() ?? false),
    [rename],
  )
  const renameOpening = useSignal(renameOpeningSignal)
  const signedIn = useSignal(root.signedIn)
  const verifiedEmail = useSignal(root.verifiedEmail)
  const name = useSignal(binding.name)
  const emailOpening = useSignal(email.busy)
  const theme = useSignal(root.theme)
  const timeZone = useSignal(root.timeZone)
  const impersonation = useSignal(hilosImpersonation)
  const [themeOpen, setThemeOpen] = useState(false)
  const [timeZoneOpen, setTimeZoneOpen] = useState(false)
  const [timeZoneOwner, setTimeZoneOwner] = useState<{
    context: HilosProfilePageContext
    picker: HilosTimeZonePicker
  } | null>(null)
  // Disposal is final: each setup, including StrictMode replay, owns a fresh
  // picker. A changed context takes its old adapter away before the next setup.
  useEffect(() => {
    const picker = createHilosTimeZonePicker(context)
    setTimeZoneOwner({ context, picker })
    return () => picker.dispose()
  }, [context])
  // A sign-out takes the choice windows away with the page; they must not come
  // back by themselves with the next sign-in.
  useEffect(() => {
    if (!signedIn) {
      setThemeOpen(false)
      setTimeZoneOpen(false)
    }
  }, [signedIn])

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
      {name ? <HilosProfileHead context={context} store={root.head} /> : null}
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
          <div
            className="d-flex align-items-center gap-3 py-3 border-bottom"
            data-id="profile-time-zone-row"
          >
            <i
              className="bi bi-clock fs-5 text-body-secondary"
              aria-hidden="true"
            />
            <div className="flex-grow-1 text-break">
              <div className="fw-semibold small">{COPY.timeZone}</div>
              <div
                className="small text-body-secondary"
                data-id="profile-time-zone"
              >
                {timeZone}
              </div>
            </div>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={impersonation !== null}
              aria-describedby={
                impersonation ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined
              }
              data-id="profile-time-zone-change"
              onClick={() => {
                if (hilosImpersonation.get() === null) setTimeZoneOpen(true)
              }}
            >
              {COPY.change}
            </button>
          </div>
          {theme !== null ? (
            <div className="d-flex align-items-center gap-3 py-3 border-bottom">
              <i
                className="bi bi-circle-half fs-5 text-body-secondary"
                aria-hidden="true"
              ></i>
              <div className="flex-grow-1 text-break">
                <div className="fw-semibold small">{COPY.theme}</div>
                <div
                  className="small text-body-secondary"
                  data-id="profile-theme"
                >
                  {theme}
                </div>
              </div>
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                disabled={impersonation !== null}
                aria-describedby={
                  impersonation ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined
                }
                data-id="profile-theme-change"
                onClick={() => setThemeOpen(true)}
              >
                {COPY.change}
              </button>
            </div>
          ) : null}
        </div>
      ) : (
        <p className="text-body-secondary" data-id="profile-loading">
          {COPY.loading}
        </p>
      )}
      <HilosAccountDeletion context={context} />
      {rename ? <HilosProfileRename flow={rename} /> : null}
      <HilosProfileEmailChange flow={email} />
      <HilosProfileTheme open={themeOpen} onClose={() => setThemeOpen(false)} />
      {timeZoneOwner?.context === context ? (
        <HilosProfileTimeZone
          open={timeZoneOpen}
          onClose={() => setTimeZoneOpen(false)}
          picker={timeZoneOwner.picker}
        />
      ) : null}
    </section>
  )
}
