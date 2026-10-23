// HilosAppearancePage — the framework Appearance page (HilosPages.APPEARANCE):
// the installation's two theme settings, Theme switching and Default theme, in
// the standard viewport table straight under the admin shell's header. The page
// writes no paragraph of its own: the header's caption already says what the
// section is (mockups/admin/appearance). The table, the frame it declares, the
// row view-model and the two writes are the core headless's
// (createHilosAppearanceTable / createHilosAppearanceActions); each row's pencil
// opens its own window over the core row-edit session
// (createHilosAppearanceSwitchingEdit / createHilosAppearanceDefaultThemeEdit,
// conflict-resolution.md), which holds the row in focus, merges against the live
// row and says what happened elsewhere on one line of room held in advance
// (HilosEditNotice). This view binds the switch, the radios and the text.
// Authoritative-backend: Save dispatches a tracked action and the window closes
// on its `::success` reply (useTrackedAction); a refusal leaves it open with the
// draft (toasts.md). A viewer of the admin view mode opens both windows and moves
// their fields, while Save — and Enter in the form — stands locked by the admin
// shell (useLookOnly) and the server refuses the write anyway. Bootstrap
// classes only (styling-rules.md).
import { useEffect, useMemo, type ReactNode } from 'react'
import {
  APPEARANCE_DEFAULT_VALUE_FIELD,
  APPEARANCE_ROW_KEY_FIELD,
  APPEARANCE_VALUE_FIELD,
  HilosAppearanceSettingKey,
  createHilosAppearanceActions,
  createHilosAppearanceDefaultThemeEdit,
  createHilosAppearanceSwitchingEdit,
  createHilosAppearanceTable,
} from '@hilos/core/admin/appearance'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  THEME_POSITIONS,
  isHiddenValue,
} from '@hilos/core/base'
import type {
  HilosAppearanceContext,
  HilosAppearanceSettingRow,
} from '@hilos/core/admin/appearance'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useLookOnly } from '../../hilosLookOnly.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosAppearancePage}. */
export interface HilosAppearancePageProps {
  /** The project context: connection, scope stores and the action lifecycle. */
  context: HilosAppearanceContext
}

/**
 * The words a setting key is named by on the screen.
 *
 * @param key The setting's stable key.
 */
function settingLabel(key: string): string {
  return key === HilosAppearanceSettingKey.switchingEnabled
    ? 'Theme switching'
    : key === HilosAppearanceSettingKey.defaultTheme
      ? 'Default theme'
      : key
}

/**
 * The words a setting value is shown by: On/Off for the switch, the position's
 * name for the default theme.
 *
 * @param value The value as the row carries it.
 */
function valueLabel(value: boolean | string): string {
  if (typeof value === 'boolean') {
    return value ? 'On' : 'Off'
  }

  return (
    THEME_POSITIONS.find((position) => position.value === value)?.label ?? value
  )
}

/**
 * The form of one window: Enter saves the way the Save button does, and stays
 * still wherever that button is locked (useLookOnly). It sits inside the admin
 * shell, which is what tells a viewer of the admin view mode apart.
 *
 * @param props The save to run on submit and the window's fields.
 */
function AppearanceForm({
  onSubmit,
  children,
}: {
  onSubmit: () => void
  children: ReactNode
}) {
  const { locked } = useLookOnly()

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        if (!locked) onSubmit()
      }}
    >
      {children}
    </form>
  )
}

/**
 * The framework Appearance admin page: the two theme settings with a window
 * each to edit them.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosAppearancePage({ context }: HilosAppearancePageProps) {
  const table = useMemo(() => createHilosAppearanceTable(context), [context])
  const actions = useMemo(
    () => createHilosAppearanceActions(context),
    [context],
  )
  const switchingEditor = useMemo(
    () => createHilosAppearanceSwitchingEdit(table.controller, actions),
    [table, actions],
  )
  const defaultThemeEditor = useMemo(
    () => createHilosAppearanceDefaultThemeEdit(table.controller, actions),
    [table, actions],
  )
  const switchingAction = useTrackedAction()
  const defaultThemeAction = useTrackedAction()

  // Bind the table to the connection on mount and unbind on unmount; both
  // windows listen for the merge's steps over the same span.
  useEffect(() => {
    table.start()
    switchingEditor.start()
    defaultThemeEditor.start()

    return () => {
      defaultThemeEditor.dispose()
      switchingEditor.dispose()
      table.dispose()
    }
  }, [table, switchingEditor, defaultThemeEditor])

  const switchingOpen = useSignal(switchingEditor.opened)
  const switchingRow = useSignal(switchingEditor.row)
  const switchingForm = useSignal(switchingEditor.form)
  const switchingState = useSignal(switchingEditor.state)
  const switchingNoticeText = useSignal(switchingEditor.noticeText)
  const switchingSaveLabel = useSignal(switchingEditor.saveLabel)
  const switchingSaveAvailable = useSignal(switchingEditor.saveAvailable)

  const defaultThemeOpen = useSignal(defaultThemeEditor.opened)
  const defaultThemeRow = useSignal(defaultThemeEditor.row)
  const defaultThemeForm = useSignal(defaultThemeEditor.form)
  const defaultThemeState = useSignal(defaultThemeEditor.state)
  const defaultThemeNoticeText = useSignal(defaultThemeEditor.noticeText)
  const defaultThemeSaveLabel = useSignal(defaultThemeEditor.saveLabel)
  const defaultThemeSaveAvailable = useSignal(defaultThemeEditor.saveAvailable)

  function openSwitching(row: HilosAppearanceSettingRow): void {
    switchingAction.clearError()
    switchingEditor.open(row.rowKey)
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its reply.
  function submitSwitching(): void {
    void switchingEditor.save(switchingAction.run)
  }

  function openDefaultTheme(row: HilosAppearanceSettingRow): void {
    defaultThemeAction.clearError()
    defaultThemeEditor.open(row.rowKey)
  }

  function submitDefaultTheme(): void {
    void defaultThemeEditor.save(defaultThemeAction.run)
  }

  return (
    <HilosAdminPage page={HilosPages.APPEARANCE}>
      <HilosViewportTable
        controller={table.controller}
        cells={{
          [APPEARANCE_ROW_KEY_FIELD]: (row) => (
            <strong>{settingLabel(row.rowKey)}</strong>
          ),
          [APPEARANCE_VALUE_FIELD]: (row) => (
            <span data-id={`appearance-setting-value-${row.rowKey}`}>
              <HilosHideable value={row.value}>{valueLabel}</HilosHideable>
            </span>
          ),
          [APPEARANCE_DEFAULT_VALUE_FIELD]: (row) => (
            <span data-id={`appearance-setting-default-${row.rowKey}`}>
              <HilosHideable value={row.defaultValue}>
                {valueLabel}
              </HilosHideable>
            </span>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <>
              {row.rowKey === HilosAppearanceSettingKey.switchingEnabled ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-primary"
                  title="Edit Theme switching"
                  aria-label="Edit Theme switching"
                  data-id="appearance-setting-edit-theme.switching_enabled"
                  onClick={() => openSwitching(row)}
                >
                  <i className="bi bi-pencil" aria-hidden="true" />
                  <span className="d-md-none ms-1">Edit</span>
                </button>
              ) : null}
              {row.rowKey === HilosAppearanceSettingKey.defaultTheme ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-primary"
                  title="Edit Default theme"
                  aria-label="Edit Default theme"
                  data-id="appearance-setting-edit-theme.default"
                  onClick={() => openDefaultTheme(row)}
                >
                  <i className="bi bi-pencil" aria-hidden="true" />
                  <span className="d-md-none ms-1">Edit</span>
                </button>
              ) : null}
            </>
          ),
        }}
      />

      <HilosModal
        open={switchingOpen}
        aria-label="Edit · Theme switching"
        confirmOnClose={switchingState.dirty}
        onClose={() => switchingEditor.close()}
        header={
          <ConflictHeader
            title="Edit · Theme switching"
            conflict={switchingState.conflict}
          />
        }
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={switchingState.conflict}
            disableSave={!switchingSaveAvailable}
            saveLabel={switchingSaveLabel}
            onSave={submitSwitching}
            onAcceptMine={() => switchingEditor.keepMine()}
            onAcceptTheirs={() => switchingEditor.takeTheirs()}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={switchingAction.busy}
                data-id="appearance-switching-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={switchingAction.busy}
                disabled={disabled}
                data-id="appearance-switching-save"
                onClick={onSave}
              >
                {switchingSaveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError
          action={switchingAction}
          detailsTitle="Couldn't save"
        />
        {switchingRow ? (
          <AppearanceForm onSubmit={submitSwitching}>
            {switchingForm.hidden ? (
              <HilosHiddenMark />
            ) : (
              <>
                <div className="form-check form-switch mb-2">
                  <input
                    id="appearance-switching-field"
                    className="form-check-input"
                    type="checkbox"
                    role="switch"
                    aria-describedby="appearance-switching-hint"
                    data-id="appearance-switching-input"
                    data-autofocus
                    checked={switchingForm.enabled}
                    onChange={(event) =>
                      switchingEditor.patchForm({
                        enabled: event.target.checked,
                      })
                    }
                  />
                  <label
                    className="form-check-label"
                    htmlFor="appearance-switching-field"
                  >
                    Let people choose a theme
                  </label>
                  <span className="badge rounded-pill bg-body-tertiary text-body-emphasis border ms-1">
                    <HilosHideable value={switchingRow.defaultValue}>
                      {(shown) => `default: ${valueLabel(shown)}`}
                    </HilosHideable>
                  </span>
                </div>
                <p
                  id="appearance-switching-hint"
                  className="small text-body-secondary mb-0"
                >
                  When off, the theme icon in the header and the Theme row in
                  the profile disappear for everyone, and everyone sees the
                  default theme. People&apos;s choices are kept and come back
                  when it is on again.
                </p>
              </>
            )}
            <HilosEditNotice
              kind={switchingState.notice?.kind ?? null}
              text={switchingNoticeText}
              dataId="appearance-switching-notice"
            />
          </AppearanceForm>
        ) : null}
      </HilosModal>

      <HilosModal
        open={defaultThemeOpen}
        aria-label="Edit · Default theme"
        confirmOnClose={defaultThemeState.dirty}
        onClose={() => defaultThemeEditor.close()}
        header={
          <ConflictHeader
            title="Edit · Default theme"
            conflict={defaultThemeState.conflict}
          />
        }
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={defaultThemeState.conflict}
            disableSave={!defaultThemeSaveAvailable}
            saveLabel={defaultThemeSaveLabel}
            onSave={submitDefaultTheme}
            onAcceptMine={() => defaultThemeEditor.keepMine()}
            onAcceptTheirs={() => defaultThemeEditor.takeTheirs()}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={defaultThemeAction.busy}
                data-id="appearance-default-theme-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={defaultThemeAction.busy}
                disabled={disabled}
                data-id="appearance-default-theme-save"
                onClick={onSave}
              >
                {defaultThemeSaveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError
          action={defaultThemeAction}
          detailsTitle="Couldn't save"
        />
        {defaultThemeRow ? (
          <AppearanceForm onSubmit={submitDefaultTheme}>
            {isHiddenValue(defaultThemeForm.defaultTheme) ? (
              <HilosHiddenMark />
            ) : (
              <>
                <fieldset
                  className="mb-2"
                  aria-describedby="appearance-default-theme-hint"
                >
                  <legend className="form-label fs-6 mb-2">
                    Default theme
                  </legend>
                  {THEME_POSITIONS.map((position) => (
                    <div key={position.value} className="form-check mb-1">
                      <input
                        id={`appearance-default-theme-${position.value}-field`}
                        className="form-check-input"
                        type="radio"
                        name="appearance-default-theme"
                        value={position.value}
                        data-id={`appearance-default-theme-${position.value}`}
                        data-autofocus
                        checked={
                          defaultThemeForm.defaultTheme === position.value
                        }
                        onChange={() =>
                          defaultThemeEditor.patchForm({
                            defaultTheme: position.value,
                          })
                        }
                      />
                      <label
                        className="form-check-label d-inline-flex align-items-center gap-2"
                        htmlFor={`appearance-default-theme-${position.value}-field`}
                      >
                        <i
                          className={`bi ${position.icon}`}
                          aria-hidden="true"
                        />
                        {position.label}
                        {position.value === defaultThemeRow.defaultValue ? (
                          <span className="badge rounded-pill bg-body-tertiary text-body-emphasis border">
                            default
                          </span>
                        ) : null}
                      </label>
                    </div>
                  ))}
                </fieldset>
                <p
                  id="appearance-default-theme-hint"
                  className="small text-body-secondary mb-0"
                >
                  Everyone who has not chosen a theme switches at once, in every
                  open tab. People who chose one keep it.
                </p>
              </>
            )}
            <HilosEditNotice
              kind={defaultThemeState.notice?.kind ?? null}
              text={defaultThemeNoticeText}
              dataId="appearance-default-theme-notice"
            />
          </AppearanceForm>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
