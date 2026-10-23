import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_I18N_LOCALE_COPY,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleSwitchOn,
  createHilosI18nLocaleSwitchOff,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleSwitchOff,
  hilosI18nLocaleWindowMode,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleFormats,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleTemplates,
  type HilosI18nLocaleWindowMode,
} from '@hilos/core/admin/i18n'
import {
  createSignal,
  focusInitial,
  type TableViewportController,
} from '@hilos/core/base'
import { ConflictActions } from '../../../ConflictActions.js'
import { ConflictHeader } from '../../../ConflictHeader.js'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

interface LocaleWindowProps {
  context: HilosI18nLanguageContext
  controller: TableViewportController<HilosI18nLocaleRow>
  card: HilosI18nLanguageCard | null
  templates: HilosI18nLocaleTemplates | null
  opened: { rowKey: string; mode: HilosI18nLocaleWindowMode } | null
  onClose: () => void
}
const FIELDS = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const
const copy = HILOS_I18N_LOCALE_COPY
const FOOTER = 'modal-footer hilos-button-row p-0 m-0 border-0 align-self-end'

/** Each opening owns its factories and runners until close or route teardown. */
export function HilosI18nLocaleWindow(props: LocaleWindowProps) {
  const [binding, setBinding] = useState({
    opened: props.opened,
    context: props.context,
    controller: props.controller,
    revision: 0,
  })
  if (
    binding.opened !== props.opened ||
    binding.context !== props.context ||
    binding.controller !== props.controller
  )
    setBinding({
      opened: props.opened,
      context: props.context,
      controller: props.controller,
      revision: binding.revision + 1,
    })
  return props.opened ? (
    <LocaleWindow key={binding.revision} {...props} opened={props.opened} />
  ) : null
}

function LocaleWindow({
  context,
  controller,
  card,
  templates,
  opened,
  onClose,
}: LocaleWindowProps & { opened: NonNullable<LocaleWindowProps['opened']> }) {
  const [address] = useState(() => createSignal(card?.languageId ?? null))
  const [openedName] = useState(card?.nativeName ?? '')
  const editor = useMemo(
    () => createHilosI18nLocaleEdit(controller, context),
    [controller, context, address],
  )
  const creator = useMemo(
    () => createHilosI18nLocaleAdd(controller, context),
    [controller, context, address],
  )
  const switcher = useMemo(
    () => createHilosI18nLocaleSwitchOn(context),
    [context, address],
  )
  const offSwitcher = useMemo(
    () => createHilosI18nLocaleSwitchOff(context),
    [context],
  )
  const editRow = useSignal(editor.row),
    editForm = useSignal(editor.form),
    editState = useSignal(editor.state),
    editNotice = useSignal(editor.noticeText),
    editSaveAvailable = useSignal(editor.saveAvailable),
    editSaveLabel = useSignal(editor.saveLabel)
  const addRow = useSignal(creator.row),
    addForm = useSignal(creator.form),
    addAvailable = useSignal(creator.addAvailable),
    addElsewhere = useSignal(creator.elsewhere),
    pairGone = useSignal(creator.gone)
  const focused = useSignal(controller.focusedRow)
  const addAction = useTrackedAction(),
    editAction = useTrackedAction(),
    switchAction = useTrackedAction(),
    offAction = useTrackedAction()
  const { locked } = useLookOnly()
  const [mode, setMode] = useState(opened.mode),
    [step, setStep] = useState<'form' | 'switchOn' | 'switchOff'>('form'),
    [unsavedAtStep, setUnsavedAtStep] = useState(false),
    [viewSnapshot, setViewSnapshot] = useState<HilosI18nLocaleRow | null>(null)
  const mounted = useRef(false),
    sending = useRef(false),
    body = useRef<HTMLDivElement>(null)
  const closeCallback = useRef(onClose)
  closeCallback.current = onClose
  useEffect(() => {
    mounted.current = true
    editor.start()
    const fresh = controller.focusRow(opened.rowKey)
    if (!fresh) closeCallback.current()
    else {
      const freshMode = hilosI18nLocaleWindowMode(fresh)
      setMode(freshMode)
      if (freshMode === 'add' && !creator.open(opened.rowKey))
        closeCallback.current()
      if (freshMode === 'edit' && !editor.open(opened.rowKey))
        closeCallback.current()
      if (freshMode === 'view') setViewSnapshot(fresh)
    }
    return () => {
      mounted.current = false
      editor.dispose()
      creator.dispose()
      controller.releaseFocus()
    }
    // The opening is immutable; callbacks and the live card may change without reopening it.
  }, [editor, creator, controller, opened.rowKey])
  useEffect(() => {
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog, 'dialog')
  }, [step])
  const row = mode === 'add' ? addRow : mode === 'edit' ? editRow : viewSnapshot
  const liveRow =
    focused?.rowKey === row?.rowKey &&
    (mode === 'add' || focused?.localeId === row?.localeId)
      ? focused
      : undefined
  const shownRow = mode === 'view' ? (liveRow ?? viewSnapshot) : row
  const form =
    mode === 'add'
      ? addForm
      : mode === 'edit'
        ? editForm
        : (shownRow?.formats ?? viewSnapshot?.formats ?? addForm)
  const country = row ? hilosI18nLocaleCountryLabel(row) : null
  const busy =
    addAction.busy || editAction.busy || switchAction.busy || offAction.busy
  const subjectGone = card?.languageId !== address.get()
  const editGone = editState.gone || subjectGone,
    addGone = pairGone || subjectGone
  const viewGone =
    subjectGone ||
    !liveRow ||
    liveRow.localeCode === null ||
    liveRow.formats === null
  const offVerdict = hilosI18nLocaleSwitchOff(
    subjectGone ? null : card,
    liveRow,
  )
  const lockedOn = mode === 'edit' && !busy && liveRow?.enabled === true
  const switchedOff = mode === 'view' && !busy && liveRow?.enabled === false
  const noticeKind =
    mode === 'add'
      ? addGone
        ? 'deleted'
        : addElsewhere
          ? 'updated'
          : null
      : mode === 'edit'
        ? editGone
          ? 'deleted'
          : lockedOn
            ? 'updated'
            : (editState.notice?.kind ?? null)
        : viewGone
          ? 'deleted'
          : switchedOff
            ? 'updated'
            : null
  const noticeText =
    mode === 'add'
      ? addGone
        ? copy.elsewhere.deleted
        : addElsewhere
          ? copy.elsewhere.added
          : ''
      : mode === 'edit'
        ? editGone
          ? copy.elsewhere.deleted
          : lockedOn
            ? copy.elsewhere.switchedOn
            : editNotice
        : viewGone
          ? copy.elsewhere.deleted
          : switchedOff
            ? copy.elsewhere.switchedOff
            : ''
  const plate =
    mode === 'add'
      ? row?.catalogFormats
        ? copy.plate.addKnown
        : copy.plate.addOwn
      : mode === 'view' || lockedOn
        ? copy.plate.view
        : row?.catalogFormats
          ? copy.plate.editKnown
          : copy.plate.editOwn
  const formTitle = copy.title(mode, openedName, country),
    switchOnTitle = copy.switchOn.title(openedName, country),
    switchOffTitle = copy.switchOff.title(openedName, country)
  const title =
    step === 'switchOn'
      ? switchOnTitle
      : step === 'switchOff'
        ? switchOffTitle
        : formTitle
  const activeAction =
    step === 'switchOn'
      ? switchAction
      : step === 'switchOff'
        ? offAction
        : mode === 'add'
          ? addAction
          : editAction
  const frozen = mode === 'view' || lockedOn
  function close(): void {
    if (!sending.current && !busy) onClose()
  }
  async function submit(): Promise<void> {
    if (locked || busy || sending.current || subjectGone || step !== 'form')
      return
    if (mode === 'add') {
      await creator.add(addAction.run)
      if (mounted.current && !creator.opened.get()) onClose()
    } else if (mode === 'edit') {
      await editor.save(editAction.run)
      if (mounted.current && !editor.opened.get()) onClose()
    }
  }
  function openSwitchOn(): void {
    if (busy || lockedOn || editGone || !row) return
    setUnsavedAtStep(editState.dirty || editState.conflict)
    switchAction.clearError()
    setStep('switchOn')
  }
  function openSwitchOff(): void {
    if (busy || mode !== 'view' || viewGone || liveRow?.enabled !== true) return
    offAction.clearError()
    setStep('switchOff')
  }
  async function confirm(): Promise<void> {
    if (
      locked ||
      sending.current ||
      busy ||
      unsavedAtStep ||
      lockedOn ||
      editGone ||
      !row
    )
      return
    sending.current = true
    try {
      if (
        (await switchAction.run(
          switcher.switchOn(row.languageId, row.countryId, row.localeId!),
        )) &&
        mounted.current
      )
        onClose()
    } finally {
      sending.current = false
    }
  }
  async function confirmOff(): Promise<void> {
    if (locked || sending.current || busy || offVerdict.disabled || !liveRow)
      return
    sending.current = true
    try {
      if (
        (await offAction.run(
          offSwitcher.switchOff(
            row!.languageId,
            row!.countryId,
            row!.localeId!,
          ),
        )) &&
        mounted.current
      )
        onClose()
    } finally {
      sending.current = false
    }
  }
  const catalog = row?.catalogFormats != null
  const disabled =
    frozen ||
    (mode === 'edit' && editGone) ||
    (mode === 'add' && addGone) ||
    busy
  return (
    <HilosModal
      open
      title={title}
      onClose={close}
      confirmOnClose={mode === 'edit' && editState.dirty}
      busy={busy}
      initialFocus="dialog"
      header={
        <div className={mode !== 'add' ? 'hilos-stack flex-grow-1' : undefined}>
          {mode !== 'add' && (
            <div className="hilos-stack invisible" aria-hidden="true" inert>
              <h5 className="modal-title d-inline-flex align-items-center gap-2 mb-0">
                <span>{formTitle}</span>
                {mode === 'edit' && (
                  <span className="badge text-bg-danger">conflict</span>
                )}
              </h5>
              <h5 className="modal-title mb-0">
                {mode === 'edit' ? switchOnTitle : switchOffTitle}
              </h5>
            </div>
          )}
          <div data-id="i18n-locale-title">
            {mode === 'edit' && step === 'form' ? (
              <ConflictHeader title={title} conflict={editState.conflict} />
            ) : (
              <h5 className="modal-title mb-0">{title}</h5>
            )}
          </div>
        </div>
      }
      actions={({ requestClose }) => (
        <div className={mode === 'add' ? undefined : 'hilos-stack w-100 m-0'}>
          {mode !== 'add' && (
            <div className="hilos-stack invisible" aria-hidden="true" inert>
              <div className={FOOTER}>
                <div className="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap">
                  {mode === 'edit' && (
                    <div className="hilos-conflict-choices d-flex gap-2">
                      <span className="btn btn-outline-secondary">
                        Keep mine
                      </span>
                      <span className="btn btn-outline-secondary">
                        Take theirs
                      </span>
                    </div>
                  )}
                  <span className="btn btn-secondary">
                    {mode === 'edit' ? copy.cancel : copy.close}
                  </span>
                  <span
                    className={`btn ${mode === 'edit' ? 'btn-outline-primary' : 'btn-outline-danger'}`}
                  >
                    {mode === 'edit' ? copy.switchOn.open : copy.switchOff.open}
                  </span>
                  {mode === 'edit' && (
                    <span className="btn btn-primary">{editSaveLabel}</span>
                  )}
                </div>
              </div>
              <div className={FOOTER}>
                <span className="btn btn-secondary">
                  {mode === 'edit' ? copy.switchOn.back : copy.switchOff.back}
                </span>
                <span
                  className={`btn ${mode === 'edit' ? 'btn-primary' : 'btn-danger'}`}
                >
                  {mode === 'edit'
                    ? editGone
                      ? 'Deleted'
                      : copy.switchOn.confirm
                    : copy.switchOff.confirm}
                </span>
              </div>
            </div>
          )}
          <div className={FOOTER}>
            {step === 'switchOn' ? (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  data-id="i18n-locale-switch-on-back"
                  onClick={() => setStep('form')}
                >
                  {copy.switchOn.back}
                </button>
                <LoadingButton
                  className="btn-primary"
                  loading={switchAction.busy}
                  disabled={
                    addAction.busy ||
                    editAction.busy ||
                    offAction.busy ||
                    unsavedAtStep ||
                    lockedOn ||
                    editGone
                  }
                  data-id="i18n-locale-switch-on-confirm"
                  onClick={() => void confirm()}
                >
                  {editGone ? 'Deleted' : copy.switchOn.confirm}
                </LoadingButton>
              </>
            ) : step === 'switchOff' ? (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  data-id="i18n-locale-switch-off-back"
                  onClick={() => setStep('form')}
                >
                  {copy.switchOff.back}
                </button>
                <LoadingButton
                  className="btn-danger"
                  loading={offAction.busy}
                  disabled={
                    addAction.busy ||
                    editAction.busy ||
                    switchAction.busy ||
                    offVerdict.disabled
                  }
                  data-id="i18n-locale-switch-off-confirm"
                  onClick={() => void confirmOff()}
                >
                  {copy.switchOff.confirm}
                </LoadingButton>
              </>
            ) : mode === 'edit' ? (
              <ConflictActions
                conflict={editState.conflict}
                disableSave={!editSaveAvailable || editGone}
                saveLabel={editGone ? 'Deleted' : editSaveLabel}
                onSave={() => void submit()}
                onAcceptMine={editor.keepMine}
                onAcceptTheirs={editor.takeTheirs}
                cancelButton={
                  <>
                    <button
                      type="button"
                      className="btn btn-secondary"
                      disabled={busy}
                      data-id="i18n-locale-cancel"
                      onClick={requestClose}
                    >
                      {copy.cancel}
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-primary"
                      disabled={busy || editGone || lockedOn}
                      data-id="i18n-locale-switch-on"
                      onClick={openSwitchOn}
                    >
                      {copy.switchOn.open}
                    </button>
                  </>
                }
                saveButton={({ disabled: saveDisabled, onSave }) => (
                  <LoadingButton
                    className="btn-primary"
                    loading={editAction.busy}
                    disabled={saveDisabled}
                    data-id="i18n-locale-submit"
                    onClick={onSave}
                  >
                    {editGone ? 'Deleted' : editSaveLabel}
                  </LoadingButton>
                )}
              />
            ) : mode === 'add' ? (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  data-id="i18n-locale-cancel"
                  onClick={requestClose}
                >
                  {copy.cancel}
                </button>
                <LoadingButton
                  className="btn-primary"
                  loading={addAction.busy}
                  disabled={!addAvailable || addGone}
                  data-id="i18n-locale-submit"
                  onClick={() => void submit()}
                >
                  {addGone ? 'Deleted' : copy.add}
                </LoadingButton>
              </>
            ) : (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  data-id="i18n-locale-close"
                  onClick={requestClose}
                >
                  {copy.close}
                </button>
                <button
                  type="button"
                  className="btn btn-outline-danger"
                  disabled={busy || viewGone || liveRow?.enabled !== true}
                  data-id="i18n-locale-switch-off"
                  onClick={openSwitchOff}
                >
                  {copy.switchOff.open}
                </button>
              </>
            )}
          </div>
        </div>
      )}
    >
      <div ref={body} data-id="i18n-locale-window">
        <div className="d-flex flex-wrap gap-3 mb-2 small">
          <div>
            <span className="text-body-secondary">{copy.countryLabel}</span>
            <span className="fw-semibold">{country ?? copy.noCountry}</span>
          </div>
          <div>
            <span className="text-body-secondary">{copy.codeLabel}</span>
            <code data-id="i18n-locale-code">
              {row?.localeCode ??
                card?.code +
                  (row?.countryCode ? '-' + row.countryCode.toUpperCase() : '')}
            </code>
          </div>
        </div>
        <div className={mode !== 'add' ? 'hilos-stack' : undefined}>
          {mode !== 'add' && (
            <div className="hilos-stack invisible" aria-hidden="true" inert>
              <p className="small text-body-secondary">{copy.lead}</p>
              <p className="small text-body-secondary">
                {mode === 'edit' ? copy.switchOn.body : copy.switchOff.body}
              </p>
            </div>
          )}
          {step === 'switchOn' ? (
            <p
              className="small text-body-secondary"
              data-id="i18n-locale-switch-on-body"
            >
              {copy.switchOn.body}
            </p>
          ) : step === 'switchOff' ? (
            <p
              className="small text-body-secondary"
              data-id="i18n-locale-switch-off-body"
            >
              {copy.switchOff.body}
            </p>
          ) : (
            <p className="small text-body-secondary">{copy.lead}</p>
          )}
        </div>
        <div data-id="i18n-locale-error">
          <div
            data-id={
              step === 'switchOff' ? 'i18n-locale-switch-off-error' : undefined
            }
          >
            <HilosActionError
              action={activeAction}
              detailsTitle={
                step === 'switchOn'
                  ? copy.switchOn.refusalTitle
                  : step === 'switchOff'
                    ? copy.switchOff.refusalTitle
                    : mode === 'add'
                      ? copy.refusalTitle.add
                      : copy.refusalTitle.edit
              }
            />
          </div>
        </div>
        <HilosEditNotice
          kind={noticeKind}
          text={noticeText}
          dataId="i18n-locale-notice"
        />
        <div className={mode !== 'add' ? 'hilos-stack' : undefined}>
          {/* Inert twins hold every ordinary step's room without entering locators or focus. */}
          {mode !== 'add' && (
            <div className="hilos-stack invisible" aria-hidden="true" inert>
              <div>
                <div className="row g-2">
                  {FIELDS.map((field) => (
                    <div
                      key={field}
                      className={field === 'address' ? 'col-12' : 'col-6'}
                    >
                      <label className="form-label small fw-semibold">
                        {copy.labels[field]}
                      </label>
                      <div className="form-control form-control-sm">&nbsp;</div>
                    </div>
                  ))}
                </div>
                <div
                  className={`alert small py-2 mt-3 mb-0 ${frozen ? 'alert-warning' : 'alert-info'}`}
                >
                  <i
                    className={`bi ${frozen ? 'bi-lock' : 'bi-pencil'} me-1`}
                    aria-hidden="true"
                  />
                  {plate}
                </div>
              </div>
              <div>
                {catalog && (
                  <div className="alert alert-secondary small py-2 mb-0">
                    {mode === 'edit'
                      ? copy.switchOn.catalogNote
                      : copy.switchOff.catalogNote}
                  </div>
                )}
                {mode === 'edit' ? (
                  <div className="alert alert-warning small py-2 mb-0">
                    {copy.switchOn.unsaved}
                  </div>
                ) : (
                  <div className="hilos-stack">
                    <div className="alert alert-danger small py-2 mb-0">
                      {copy.switchOff.defaultReason}
                    </div>
                    <div className="alert alert-warning small py-2 mb-0">
                      {copy.switchOff.languageWarning}
                    </div>
                    <div className="alert alert-warning small py-2 mb-0">
                      {copy.switchOff.countryWarning}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}
          {step === 'switchOn' ? (
            <div>
              {catalog && (
                <div
                  className="alert alert-secondary small py-2 mb-0"
                  data-id="i18n-locale-switch-on-note"
                >
                  {copy.switchOn.catalogNote}
                </div>
              )}
              {unsavedAtStep && (
                <div
                  className="alert alert-warning small py-2 mb-0"
                  data-id="i18n-locale-switch-on-unsaved"
                >
                  {copy.switchOn.unsaved}
                </div>
              )}
            </div>
          ) : step === 'switchOff' ? (
            <div>
              {catalog && (
                <div
                  className="alert alert-secondary small py-2 mb-0"
                  data-id="i18n-locale-switch-off-note"
                >
                  {copy.switchOff.catalogNote}
                </div>
              )}
              {offVerdict.reason && (
                <div
                  className="alert alert-danger small py-2 mb-0"
                  data-id="i18n-locale-switch-off-reason"
                >
                  {offVerdict.reason}
                </div>
              )}
              {offVerdict.warning && (
                <div
                  className="alert alert-warning small py-2 mb-0"
                  data-id="i18n-locale-switch-off-warning"
                >
                  {offVerdict.warning}
                </div>
              )}
            </div>
          ) : (
            <div>
              <form
                onSubmit={(event) => {
                  event.preventDefault()
                  void submit()
                }}
              >
                <div className="row g-2">
                  {FIELDS.map((field) => (
                    <div
                      key={field}
                      className={field === 'address' ? 'col-12' : 'col-6'}
                    >
                      <label
                        className="form-label small fw-semibold"
                        htmlFor={`i18n-locale-${field}`}
                      >
                        {copy.labels[field]}
                      </label>
                      <select
                        id={`i18n-locale-${field}`}
                        className="form-select form-select-sm"
                        value={form[field]}
                        disabled={disabled}
                        data-id={`i18n-locale-field-${field}`}
                        onChange={(event) => {
                          if (mode === 'add')
                            creator.setField(field, event.target.value)
                          if (mode === 'edit')
                            editor.patchForm({
                              [field]: event.target.value,
                            } as Partial<HilosI18nLocaleFormats>)
                        }}
                      >
                        {form[field] === '' && (
                          <option value="" disabled>
                            {copy.choose}
                          </option>
                        )}
                        {(templates?.[field] ?? []).map((value) => (
                          <option key={value} value={value}>
                            {field === 'measurement' &&
                            (value === 'metric' || value === 'imperial')
                              ? copy.measurement[value]
                              : value}
                          </option>
                        ))}
                      </select>
                    </div>
                  ))}
                </div>
              </form>
              <div
                className={`alert small py-2 mt-3 mb-0 ${frozen ? 'alert-warning' : mode === 'edit' ? 'alert-info' : 'alert-secondary'}`}
                data-id="i18n-locale-plate"
              >
                {frozen ? (
                  <i className="bi bi-lock me-1" aria-hidden="true" />
                ) : mode === 'edit' ? (
                  <i className="bi bi-pencil me-1" aria-hidden="true" />
                ) : null}
                {plate}
              </div>
            </div>
          )}
        </div>
      </div>
    </HilosModal>
  )
}
