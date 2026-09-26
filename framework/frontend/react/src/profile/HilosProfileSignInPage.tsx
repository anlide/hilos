import { useEffect, useId, useMemo, useRef, useState } from 'react'
import {
  createHilosProfileAddSignInFlow,
  createHilosProfileSignInActions,
  createSignal,
  focusInitial,
  hilosProfileLinkableProviders,
  hilosProfilePasswordState,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  HILOS_PROFILE_SIGN_IN_COPY,
  hilosToasts,
  isPasskeySupported,
  PROFILE_PASSWORD_MODE_ADDED,
  sessionAuthMethods,
  watchHilosProfilePasswordUpdated,
  type HilosAuthContext,
  type HilosProfileSignInMethod,
} from '@hilos/core'
import { HilosActionError } from '../HilosActionError.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const PASSWORD_MIN = 8
const emptyDraft = () => ({
  email: '',
  phone: '',
  code: '',
  newPassword: '',
  confirm: '',
})

/** The account's methods and the dialogs that add, change and remove them. */
export function HilosProfileSignInPage({
  context,
  methods,
}: {
  context: HilosAuthContext
  methods: readonly HilosProfileSignInMethod[]
}) {
  const methodsSignal = useMemo(() => createSignal(methods), [])
  useEffect(() => methodsSignal.set(methods), [methodsSignal, methods])
  const offeredSignal = useMemo(
    () => sessionAuthMethods(context.scopes),
    [context.scopes],
  )
  const offered = useSignal(offeredSignal)
  const providers = hilosProfileLinkableProviders(methods, offered)
  const passwordState = hilosProfilePasswordState(methods)
  const passkeySupported = isPasskeySupported()
  const actions = useMemo(
    () => createHilosProfileSignInActions(context),
    [context],
  )
  const baseId = useId()
  const title = (method: HilosProfileSignInMethod) =>
    hilosProfileSignInTitle(method, offered)

  const [unlinkKey, setUnlinkKey] = useState<string | null>(null)
  const unlinkMethod = methods.find((method) => method.key === unlinkKey)
  const remaining = methods
    .filter((method) => method.key !== unlinkKey)
    .map(title)
    .join(', ')
  const unlinkAction = useTrackedAction()
  const removing = useRef(false)
  useEffect(() => {
    if (
      unlinkKey !== null &&
      !methods.some((method) => method.key === unlinkKey)
    )
      setUnlinkKey(null)
  }, [methods, unlinkKey])
  function askUnlink(method: HilosProfileSignInMethod): void {
    if (!method.canUnlink || removing.current) return
    unlinkAction.clearError()
    setUnlinkKey(method.key)
  }
  async function remove(): Promise<void> {
    if (unlinkKey === null || removing.current) return
    removing.current = true
    if (await unlinkAction.run(actions.unlinkIdentity(Number(unlinkKey))))
      setUnlinkKey(null)
    removing.current = false
  }

  const [passwordOpen, setPasswordOpen] = useState(false)
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const passwordAction = useTrackedAction()
  const [passwordAwaiting, setPasswordAwaiting] = useState(false)
  const waitingPassword = useRef(false)
  const passwordValid =
    currentPassword !== '' &&
    newPassword.length >= PASSWORD_MIN &&
    newPassword === confirmPassword
  function changePassword(): void {
    passwordAction.clearError()
    setCurrentPassword('')
    setNewPassword('')
    setConfirmPassword('')
    setPasswordAwaiting(false)
    waitingPassword.current = false
    setPasswordOpen(true)
  }
  async function savePassword(): Promise<void> {
    if (!passwordValid || waitingPassword.current || passwordAction.busy) return
    waitingPassword.current = true
    setPasswordAwaiting(true)
    if (
      !(await passwordAction.run(
        actions.setPassword(currentPassword, newPassword),
      ))
    ) {
      waitingPassword.current = false
      setPasswordAwaiting(false)
    }
  }
  useEffect(
    () =>
      watchHilosProfilePasswordUpdated(context.connection, (data) => {
        setPasswordOpen(false)
        setPasswordAwaiting(false)
        waitingPassword.current = false
        setCurrentPassword('')
        setNewPassword('')
        setConfirmPassword('')
        hilosToasts.push(
          data.mode === PROFILE_PASSWORD_MODE_ADDED
            ? HILOS_PROFILE_SIGN_IN_COPY.passwordAdded
            : HILOS_PROFILE_SIGN_IN_COPY.passwordChanged,
          { severity: 'success' },
        )
      }),
    [context.connection],
  )

  const flow = useMemo(
    () => createHilosProfileAddSignInFlow(context, methodsSignal),
    [context, methodsSignal],
  )
  useEffect(() => () => flow.dispose(), [flow])
  const step = useSignal(flow.step)
  const busy = useSignal(flow.busy)
  const refusal = useSignal(flow.refusal)
  const pendingProvider = useSignal(flow.provider)
  const [draft, setDraft] = useState(emptyDraft)
  const addBody = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const dialog = addBody.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  }, [step])
  function openAdd(): void {
    setDraft(emptyDraft())
    flow.open()
  }
  const passwordFields = [
    {
      key: 'newPassword' as const,
      label: 'New password',
      type: 'password',
      autocomplete: 'new-password',
      dataId: 'profile-add-password-new',
    },
    {
      key: 'confirm' as const,
      label: 'Confirm new password',
      type: 'password',
      autocomplete: 'new-password',
      dataId: 'profile-add-password-confirm',
    },
  ]
  const codeField = {
    key: 'code' as const,
    label: 'Code',
    type: 'text',
    autocomplete: 'one-time-code',
    dataId:
      step === 'phone-code'
        ? 'profile-add-sms-code'
        : 'profile-add-password-code',
  }
  const fields =
    step === 'password-email'
      ? [
          {
            key: 'email' as const,
            label: 'Email',
            type: 'email',
            autocomplete: 'email',
            dataId: 'profile-add-password-email',
          },
        ]
      : step === 'phone-number'
        ? [
            {
              key: 'phone' as const,
              label: 'Phone number',
              type: 'tel',
              autocomplete: 'tel',
              dataId: 'profile-add-sms-phone',
            },
          ]
        : step === 'phone-code'
          ? [codeField]
          : step === 'password-new'
            ? passwordFields
            : step === 'password-code'
              ? [codeField, ...passwordFields]
              : []
  const addValid =
    fields.every(
      (field) =>
        (field.type === 'password'
          ? draft[field.key]
          : draft[field.key].trim()) !== '',
    ) &&
    (!step.startsWith('password-') ||
      step === 'password-email' ||
      (draft.newPassword.length >= PASSWORD_MIN &&
        draft.newPassword === draft.confirm))
  const submitId =
    step === 'phone-number'
      ? 'profile-add-sms-request'
      : step === 'phone-code'
        ? 'profile-add-sms-confirm'
        : step === 'password-email'
          ? 'profile-add-password-request'
          : 'profile-add-password-save'
  const errorId = step.startsWith('phone-')
    ? 'profile-add-sms-error'
    : step.startsWith('password-')
      ? 'profile-add-password-error'
      : 'profile-sign-in-add-error'
  async function submitAdd(): Promise<void> {
    if (!addValid || busy) return
    switch (step) {
      case 'password-new':
        await flow.submitPasswordNew(draft.newPassword)
        break
      case 'password-email':
        await flow.submitPasswordEmail(draft.email.trim())
        break
      case 'password-code':
        await flow.submitPasswordCode(draft.code.trim(), draft.newPassword)
        break
      case 'phone-number':
        await flow.submitPhone(draft.phone.trim())
        break
      case 'phone-code':
        await flow.submitPhoneCode(draft.code.trim())
        break
    }
  }

  return (
    <section data-id="profile-sign-in-view">
      <HilosPageHeading />
      {methods.length === 0 ? (
        <p className="text-body-secondary">No ways to sign in.</p>
      ) : null}
      <div data-id="profile-identities-list">
        {methods.map((method) => (
          <div
            key={method.key}
            className="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
            data-id="profile-identity-item"
            data-identity-key={method.key}
          >
            <div className="flex-grow-1 text-break">
              <div className="fw-semibold" data-id="identity-type">
                {title(method)}
              </div>
              <div
                className="small text-body-secondary"
                data-id={
                  method.type === 'passkey'
                    ? 'identity-passkey-added'
                    : 'identity-identifier'
                }
              >
                {hilosProfileSignInSubtitle(method)}
              </div>
              {method.verified ? (
                <span
                  className="badge text-bg-success"
                  data-id="identity-verified"
                >
                  Verified
                </span>
              ) : (
                <span
                  className="badge text-bg-secondary"
                  data-id="identity-unverified"
                >
                  Unverified
                </span>
              )}
              {!method.canUnlink ? (
                <div
                  className="small text-warning-emphasis"
                  data-id="identity-unlink-blocked"
                >
                  {HILOS_PROFILE_SIGN_IN_COPY.onlyMethod}
                </div>
              ) : null}
            </div>
            <div className="d-flex gap-2">
              {method.type === 'password' ? (
                <button
                  className="btn btn-sm btn-outline-secondary"
                  type="button"
                  data-id="profile-password-change"
                  onClick={changePassword}
                >
                  Change
                </button>
              ) : null}
              <button
                className="btn btn-sm btn-outline-secondary"
                type="button"
                disabled={!method.canUnlink}
                data-id="identity-unlink"
                onClick={() => askUnlink(method)}
              >
                {method.type === 'oauth' ? 'Unlink' : 'Remove'}
              </button>
            </div>
          </div>
        ))}
      </div>
      <button
        className="btn btn-sm btn-outline-primary mt-3"
        type="button"
        data-id="profile-sign-in-add"
        onClick={openAdd}
      >
        Add a way to sign in
      </button>
      <HilosModal
        open={unlinkKey !== null}
        onClose={() => {
          if (!removing.current) setUnlinkKey(null)
        }}
        title={`Remove ${unlinkMethod ? title(unlinkMethod) : 'sign-in method'}?`}
        initialFocus="dialog"
        closeOnEsc={!unlinkAction.busy}
        closeOnBackdrop={!unlinkAction.busy}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={unlinkAction.busy}
              data-id="identity-unlink-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn btn-danger"
              loading={unlinkAction.loading}
              disabled={unlinkAction.busy}
              data-id="identity-unlink-yes"
              onClick={() => void remove()}
            >
              Remove
            </LoadingButton>
          </>
        )}
      >
        <div data-id="profile-unlink-modal">
          <p>
            You will no longer be able to sign in with{' '}
            {unlinkMethod ? title(unlinkMethod) : 'this method'}. You can still
            use: {remaining}.
          </p>
          <div data-id="profile-unlink-error">
            <HilosActionError
              action={unlinkAction}
              detailsTitle="Couldn't remove the sign-in method"
            />
          </div>
        </div>
      </HilosModal>
      <HilosModal
        open={passwordOpen}
        onClose={() => setPasswordOpen(false)}
        title="Change your password"
        confirmOnClose={
          currentPassword !== '' || newPassword !== '' || confirmPassword !== ''
        }
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="profile-password-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              type="submit"
              form={`${baseId}-password`}
              className="btn btn-primary"
              loading={passwordAction.loading}
              disabled={
                !passwordValid || passwordAwaiting || passwordAction.busy
              }
              data-id="profile-password-save"
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        <form
          id={`${baseId}-password`}
          data-id="profile-password-modal"
          onSubmit={(event) => {
            event.preventDefault()
            void savePassword()
          }}
        >
          <label htmlFor={`${baseId}-current`} className="form-label">
            Current password
          </label>
          <input
            id={`${baseId}-current`}
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
            type="password"
            autoComplete="current-password"
            className="form-control mb-3"
            data-id="profile-password-current"
            data-autofocus
          />
          <label htmlFor={`${baseId}-new`} className="form-label">
            New password
          </label>
          <input
            id={`${baseId}-new`}
            value={newPassword}
            onChange={(event) => setNewPassword(event.target.value)}
            type="password"
            autoComplete="new-password"
            className="form-control mb-3"
            aria-describedby={`${baseId}-password-hint`}
            data-id="profile-password-new"
          />
          <label htmlFor={`${baseId}-confirm`} className="form-label">
            Confirm new password
          </label>
          <input
            id={`${baseId}-confirm`}
            value={confirmPassword}
            onChange={(event) => setConfirmPassword(event.target.value)}
            type="password"
            autoComplete="new-password"
            className="form-control mb-3"
            aria-describedby={`${baseId}-password-hint`}
            data-id="profile-password-confirm"
          />
          <div
            id={`${baseId}-password-hint`}
            className="form-text mb-3"
            data-id="profile-password-hint"
          >
            {HILOS_PROFILE_SIGN_IN_COPY.passwordHint}
          </div>
          <div data-id="profile-set-password-error">
            <HilosActionError
              action={passwordAction}
              detailsTitle="Couldn't change the password"
            />
          </div>
        </form>
      </HilosModal>
      <HilosModal
        open={step !== 'closed'}
        onClose={flow.close}
        title="Add a way to sign in"
        initialFocus="dialog"
        confirmOnClose={Object.values(draft).some((value) => value !== '')}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="profile-sign-in-add-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            {step !== 'choose' ? (
              <>
                <button
                  type="button"
                  className="btn btn-outline-secondary"
                  disabled={busy}
                  data-id="profile-sign-in-add-back"
                  onClick={flow.back}
                >
                  Back
                </button>
                <LoadingButton
                  type="submit"
                  form={`${baseId}-add`}
                  className="btn btn-primary"
                  loading={busy}
                  disabled={!addValid || busy}
                  data-id={submitId}
                >
                  {step === 'password-email' || step === 'phone-number'
                    ? 'Send code'
                    : 'Save'}
                </LoadingButton>
              </>
            ) : null}
          </>
        )}
      >
        <div ref={addBody} data-id="profile-sign-in-add-modal">
          <div
            className="visually-hidden"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-id="profile-sign-in-add-live"
          >
            {refusal}
          </div>
          <div className="hilos-stack">
            <div className="invisible" aria-hidden="true" inert>
              {['Code', 'New password', 'Confirm new password'].map((label) => (
                <div key={label} className="mb-3">
                  <div className="form-label">{label}</div>
                  <div className="form-control">&nbsp;</div>
                </div>
              ))}
              <div className="form-text">
                {HILOS_PROFILE_SIGN_IN_COPY.passwordHint}
              </div>
            </div>
            <div
              className="invisible d-flex flex-column gap-2"
              aria-hidden="true"
              inert
            >
              {!passwordState.hasPassword ? (
                <span className="btn btn-outline-secondary">
                  <span className="d-flex align-items-center gap-3 text-start">
                    <i className="bi bi-lock fs-5" aria-hidden="true"></i>
                    <span>
                      <span className="d-block fw-semibold small">
                        Password
                      </span>
                      <span className="d-block small text-body-secondary">
                        {HILOS_PROFILE_SIGN_IN_COPY.passwordDescription}
                      </span>
                    </span>
                  </span>
                </span>
              ) : null}
              <span className="btn btn-outline-secondary">
                <span className="d-flex align-items-center gap-3 text-start">
                  <i className="bi bi-phone fs-5" aria-hidden="true"></i>
                  <span>
                    <span className="d-block fw-semibold small">Phone</span>
                    <span className="d-block small text-body-secondary">
                      {HILOS_PROFILE_SIGN_IN_COPY.phoneDescription}
                    </span>
                  </span>
                </span>
              </span>
              {providers.map((entry) => (
                <span key={entry.key} className="btn btn-outline-secondary">
                  <span className="d-flex align-items-center gap-3 text-start">
                    <i
                      className="bi bi-box-arrow-in-right fs-5"
                      aria-hidden="true"
                    ></i>
                    <span>
                      <span className="d-block fw-semibold small">
                        {entry.label}
                      </span>
                      <span className="d-block small text-body-secondary">
                        {HILOS_PROFILE_SIGN_IN_COPY.providerDescription}
                      </span>
                    </span>
                  </span>
                </span>
              ))}
              {passkeySupported ? (
                <span className="btn btn-outline-secondary">
                  <span className="d-flex align-items-center gap-3 text-start">
                    <i
                      className="bi bi-fingerprint fs-5"
                      aria-hidden="true"
                    ></i>
                    <span>
                      <span className="d-block fw-semibold small">Passkey</span>
                      <span className="d-block small text-body-secondary">
                        {HILOS_PROFILE_SIGN_IN_COPY.passkeyDescription}
                      </span>
                    </span>
                  </span>
                </span>
              ) : null}
            </div>
            {step === 'choose' ? (
              <div className="d-flex flex-column gap-2 align-self-start">
                {!passwordState.hasPassword ? (
                  <button
                    type="button"
                    className="btn btn-outline-secondary"
                    disabled={busy}
                    data-id="profile-sign-in-choose-password"
                    onClick={flow.choosePassword}
                  >
                    <span className="d-flex align-items-center gap-3 text-start">
                      <i className="bi bi-lock fs-5" aria-hidden="true"></i>
                      <span>
                        <span className="d-block fw-semibold small">
                          Password
                        </span>
                        <span className="d-block small text-body-secondary">
                          {HILOS_PROFILE_SIGN_IN_COPY.passwordDescription}
                        </span>
                      </span>
                    </span>
                  </button>
                ) : null}
                <button
                  type="button"
                  className="btn btn-outline-secondary"
                  disabled={busy}
                  data-id="profile-sign-in-choose-phone"
                  onClick={flow.choosePhone}
                >
                  <span className="d-flex align-items-center gap-3 text-start">
                    <i className="bi bi-phone fs-5" aria-hidden="true"></i>
                    <span>
                      <span className="d-block fw-semibold small">Phone</span>
                      <span className="d-block small text-body-secondary">
                        {HILOS_PROFILE_SIGN_IN_COPY.phoneDescription}
                      </span>
                    </span>
                  </span>
                </button>
                {providers.map((entry) => (
                  <LoadingButton
                    key={entry.key}
                    className="btn btn-outline-secondary"
                    loading={busy && pendingProvider === entry.key}
                    disabled={busy}
                    data-id={`profile-oauth-link-${entry.key}`}
                    onClick={() => void flow.chooseProvider(entry.key)}
                  >
                    <span className="d-flex align-items-center gap-3 text-start">
                      <i
                        className="bi bi-box-arrow-in-right fs-5"
                        aria-hidden="true"
                      ></i>
                      <span>
                        <span className="d-block fw-semibold small">
                          {entry.label}
                        </span>
                        <span className="d-block small text-body-secondary">
                          {HILOS_PROFILE_SIGN_IN_COPY.providerDescription}
                        </span>
                      </span>
                    </span>
                  </LoadingButton>
                ))}
                {passkeySupported ? (
                  <LoadingButton
                    className="btn btn-outline-secondary"
                    loading={busy && pendingProvider === null}
                    disabled={busy}
                    data-id="profile-passkey-add"
                    onClick={() => void flow.choosePasskey()}
                  >
                    <span className="d-flex align-items-center gap-3 text-start">
                      <i
                        className="bi bi-fingerprint fs-5"
                        aria-hidden="true"
                      ></i>
                      <span>
                        <span className="d-block fw-semibold small">
                          Passkey
                        </span>
                        <span className="d-block small text-body-secondary">
                          {HILOS_PROFILE_SIGN_IN_COPY.passkeyDescription}
                        </span>
                      </span>
                    </span>
                  </LoadingButton>
                ) : null}
              </div>
            ) : (
              <form
                id={`${baseId}-add`}
                className="align-self-start"
                onSubmit={(event) => {
                  event.preventDefault()
                  void submitAdd()
                }}
              >
                {fields.map((field, index) => (
                  <div key={field.key} className="mb-3">
                    <label
                      htmlFor={`${baseId}-add-${field.key}`}
                      className="form-label"
                    >
                      {field.label}
                    </label>
                    <input
                      id={`${baseId}-add-${field.key}`}
                      value={draft[field.key]}
                      onChange={(event) =>
                        setDraft({ ...draft, [field.key]: event.target.value })
                      }
                      type={field.type}
                      autoComplete={field.autocomplete}
                      className="form-control"
                      data-id={field.dataId}
                      aria-describedby={
                        field.type === 'password'
                          ? `${baseId}-add-password-hint`
                          : undefined
                      }
                      data-autofocus={index === 0 ? '' : undefined}
                    />
                  </div>
                ))}
                {step === 'password-new' || step === 'password-code' ? (
                  <div
                    id={`${baseId}-add-password-hint`}
                    className="form-text"
                    data-id="profile-add-password-hint"
                  >
                    {HILOS_PROFILE_SIGN_IN_COPY.passwordHint}
                  </div>
                ) : null}
              </form>
            )}
          </div>
          <HilosFormError message={refusal} dataId={errorId} />
        </div>
      </HilosModal>
    </section>
  )
}
