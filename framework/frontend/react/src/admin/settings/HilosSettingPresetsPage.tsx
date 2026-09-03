// HilosSettingPresetsPage — the framework setting-presets screen: a section's
// settings offered as a few named sets instead of a few dozen keys. The layout, the
// states and the behavior are here; every phrase is the section's, arriving through
// the vocabulary prop, so the screen knows nothing about the settings it is showing
// (hilosSettingPresets.ts). A section mounts it with its page key, its context, its
// signal name and its vocabulary.
//
// Two gestures, one action: choosing an unapplied mode, and putting the applied
// mode's values back when they have drifted. The applied card is never the gesture
// itself — with no drift there is nothing to press, and with drift the one button
// inside it says out loud what it will do. While the action is in flight every card
// is disabled, or two quick clicks would race and the later write would win.
//
// The outcome arrives by push, not as a reply: the backend answers the action with
// nothing and sends the new state to every open tab on its next tick. There is no
// optimistic drawing — a rewrite of several keys can be refused by the rule on any
// one of them. A refusal shows where the person acted (inside the confirmation when
// they came through it, above the cards when the click applied at once) and, by the
// driver's default, as a toast; a success is silent, because the card lighting up
// is the result itself (toasts.md). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  createHilosSettingPresets,
  createHilosSettingPresetsActions,
  differencesOf,
  hasDifferences,
  isPresetApplied,
  isSelectionUnknown,
  presetsOf,
  resolveHilosPath,
  selectedPresetOf,
} from '@hilos/core'
import type {
  HilosSettingPreset,
  HilosSettingPresetsContext,
  HilosSettingPresetsVocabulary,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSettingPresetsPage}. */
export interface HilosSettingPresetsPageProps {
  /** The page key the admin shell draws its breadcrumb, heading and lead from. */
  page: string
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  context: HilosSettingPresetsContext
  /** Server→client signal `type` this section's group frame arrives under. */
  signal: string
  /** Everything this section says about its own presets. */
  vocabulary: HilosSettingPresetsVocabulary
}

/**
 * A section's settings as a few named modes, with the confirmation that guards
 * hand-made edits.
 *
 * @param props The page key, the project context, the group signal and the
 *   section's vocabulary.
 */
export function HilosSettingPresetsPage({
  page,
  context,
  signal,
  vocabulary,
}: HilosSettingPresetsPageProps) {
  const presets = useMemo(
    () => createHilosSettingPresets(context, signal),
    [context, signal],
  )
  const { sendSettingPresetApply } = useMemo(
    () => createHilosSettingPresetsActions(context),
    [context],
  )
  const state = useSignal(presets.state)

  // Listen for the group frame on mount and stop on unmount; nothing is requested,
  // the page sends the first frame ahead of releasing the page.
  useEffect(() => {
    presets.start()

    return () => presets.dispose()
  }, [presets])

  const applyAction = useTrackedAction()

  const cards = presetsOf(state)
  const differences = differencesOf(state)
  const drifted = hasDifferences(state)
  const unknownSelection = isSelectionUnknown(state)
  const settingsPath = resolveHilosPath(vocabulary.generalSettingsPage)

  // Confirmation: only a click that would overwrite hand-made edits raises it, and
  // what it destroys is shown in a THIRD card — the applied one — so the ruin does
  // not happen where the person is looking.
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [confirmPreset, setConfirmPreset] = useState<string | null>(null)
  const confirmTitle = vocabulary.presetTitle(confirmPreset ?? '')

  /**
   * Whether this card is the applied one, which is what lights it.
   *
   * @param name The preset the card stands for.
   */
  function applied(name: string): boolean {
    return isPresetApplied(state, name)
  }

  /**
   * The frame of a card: lit blue when applied and clean, amber when applied and
   * drifted.
   *
   * @param name The preset the card stands for.
   */
  function cardClass(name: string): string {
    if (!applied(name)) {
      return ''
    }

    return drifted
      ? 'border-warning bg-warning-subtle'
      : 'border-primary bg-primary-subtle'
  }

  /**
   * Send the apply and, when it was confirmed, close the confirmation on success.
   *
   * @param name The preset to write.
   */
  async function apply(name: string): Promise<void> {
    const applyOk = await applyAction.run(sendSettingPresetApply(name))
    if (applyOk) {
      setConfirmOpen(false)
      setConfirmPreset(null)
    }
  }

  /**
   * Choose a mode: at once when nothing of the person's own is at stake, through
   * the confirmation when their hand-made edits would go with it.
   *
   * @param name The preset the person pressed.
   */
  function choose(name: string): void {
    applyAction.clearError()
    if (drifted) {
      setConfirmPreset(name)
      setConfirmOpen(true)

      return
    }
    void apply(name)
  }

  /**
   * Close the confirmation, taking the refusal it raised with it: the alert over the
   * cards is for a click that applied at once, and a failure left behind would surface
   * there, far from where it was answered.
   */
  function closeConfirm(): void {
    setConfirmOpen(false)
    setConfirmPreset(null)
    applyAction.clearError()
  }

  /** Confirm the overwrite the person was warned about. */
  function confirmApply(): void {
    if (confirmPreset !== null) {
      void apply(confirmPreset)
    }
  }

  /** Put the applied mode's values back, which needs no second question. */
  function revert(): void {
    const selected = selectedPresetOf(state)
    if (selected !== null) {
      applyAction.clearError()
      void apply(selected)
    }
  }

  /**
   * The lines a card lists, read out of the values the preset declares.
   *
   * @param preset The preset the card stands for.
   */
  function valueLines(preset: HilosSettingPreset): string[] {
    return vocabulary.valueLines(preset.values)
  }

  return (
    <HilosAdminPage page={page}>
      <p className="text-body-secondary">{vocabulary.intro}</p>

      <h2 className="h6 mb-2">{vocabulary.groupHeading}</h2>

      {unknownSelection ? (
        <p
          className="alert alert-warning py-2 small"
          data-id="hilos-setting-preset-unknown"
        >
          {vocabulary.unknownSelectionNote}
        </p>
      ) : null}

      {!confirmOpen ? <HilosActionError action={applyAction} /> : null}

      <div className="row row-cols-1 row-cols-md-3 g-3 mb-2">
        {cards.map((preset) => (
          <div key={preset.name} className="col">
            <div
              className={`h-100 border rounded-3 d-flex flex-column ${cardClass(preset.name)}`}
            >
              <button
                type="button"
                className="btn text-start border-0 rounded-0 rounded-top-3 p-3 flex-grow-1"
                disabled={applyAction.busy || applied(preset.name)}
                aria-current={applied(preset.name) ? 'true' : undefined}
                data-id={`hilos-setting-preset-${preset.name}`}
                onClick={() => choose(preset.name)}
              >
                <span className="d-flex align-items-center gap-2 mb-1">
                  <i className={`bi ${vocabulary.presetIcon(preset.name)}`} />
                  <span className="fw-semibold small flex-grow-1">
                    {vocabulary.presetTitle(preset.name)}
                  </span>
                  {applied(preset.name) ? (
                    <i
                      className={`bi bi-check-circle-fill ${drifted ? 'text-warning' : 'text-primary'}`}
                      aria-hidden="true"
                    />
                  ) : null}
                </span>
                <span className="d-block small text-body-secondary mb-2">
                  {vocabulary.presetSubtitle(preset.name)}
                </span>
                {valueLines(preset).map((line) => (
                  <span
                    key={line}
                    className="d-block small text-body-secondary"
                  >
                    {line}
                  </span>
                ))}
              </button>

              {applied(preset.name) && drifted ? (
                <div
                  className="border-top p-3"
                  data-id="hilos-setting-preset-differences"
                >
                  <div className="small fw-semibold text-warning-emphasis mb-1">
                    {vocabulary.differencesHeading}
                  </div>
                  <ul className="small text-warning-emphasis mb-2 ps-3">
                    {differences.map((difference) => (
                      <li key={difference.key}>
                        {vocabulary.differenceLine(difference)}
                      </li>
                    ))}
                  </ul>
                  <LoadingButton
                    className="btn-sm btn-outline-secondary w-100"
                    loading={applyAction.loading}
                    disabled={applyAction.busy}
                    data-id="hilos-setting-preset-revert"
                    onClick={revert}
                  >
                    {vocabulary.revertLabel}
                  </LoadingButton>
                </div>
              ) : null}
            </div>
          </div>
        ))}
      </div>

      <p className="small text-body-secondary">{vocabulary.footnote}</p>

      <div className="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mt-4">
        <i className="bi bi-sliders2 text-body-secondary" aria-hidden="true" />
        <div className="flex-grow-1">
          <div className="fw-semibold small">
            {vocabulary.generalSettingsTitle}
          </div>
          <div className="small text-body-secondary">
            {vocabulary.generalSettingsLead}
          </div>
        </div>
        <HilosLink
          to={settingsPath}
          className="btn btn-sm btn-outline-secondary text-nowrap"
          data-id="hilos-setting-preset-settings-link"
        >
          {vocabulary.generalSettingsLabel}
          <i className="bi bi-box-arrow-up-right ms-1" aria-hidden="true" />
        </HilosLink>
      </div>

      <HilosModal
        open={confirmOpen}
        title={vocabulary.confirmTitle}
        closeOnBackdrop={!applyAction.busy}
        closeOnEsc={!applyAction.busy}
        onClose={closeConfirm}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={applyAction.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={applyAction.loading}
              data-id="hilos-setting-preset-apply-confirm"
              onClick={confirmApply}
            >
              {vocabulary.confirmLabel(confirmTitle)}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={applyAction} />
        <p className="mb-0 text-body-secondary">
          {vocabulary.confirmBody(confirmTitle)}
        </p>
      </HilosModal>
    </HilosAdminPage>
  )
}
