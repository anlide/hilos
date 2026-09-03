// HilosSettingPresetsPage — the framework setting-presets screen: a section's
// settings offered as a few named sets instead of a few dozen keys. The layout, the
// states and the behavior are here; every phrase is the section's, arriving through
// the vocabulary input, so the screen knows nothing about the settings it is showing
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
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
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
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosSettingPreset,
  HilosSettingPresetsContext,
  HilosSettingPresetsState,
  HilosSettingPresetsVocabulary,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework setting-presets screen: a section's settings as a few named sets. */
@Component({
  selector: 'hilos-setting-presets-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosLink,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page()">
      <p class="text-body-secondary">{{ vocabulary().intro }}</p>

      <h2 class="h6 mb-2">{{ vocabulary().groupHeading }}</h2>

      @if (unknownSelection()) {
        <p
          class="alert alert-warning py-2 small"
          data-id="hilos-setting-preset-unknown"
        >
          {{ vocabulary().unknownSelectionNote }}
        </p>
      }

      @if (!confirmOpen()) {
        <hilos-action-error [action]="applyAction" />
      }

      <div class="row row-cols-1 row-cols-md-3 g-3 mb-2">
        @for (preset of cards(); track preset.name) {
          <div class="col">
            <div
              class="h-100 border rounded-3 d-flex flex-column"
              [class]="cardClass(preset.name)"
            >
              <button
                type="button"
                class="btn text-start border-0 rounded-0 rounded-top-3 p-3 flex-grow-1"
                [disabled]="applyAction.busy() || applied(preset.name)"
                [attr.aria-current]="applied(preset.name) ? 'true' : null"
                [attr.data-id]="'hilos-setting-preset-' + preset.name"
                (click)="choose(preset.name)"
              >
                <span class="d-flex align-items-center gap-2 mb-1">
                  <i [class]="'bi ' + vocabulary().presetIcon(preset.name)"></i>
                  <span class="fw-semibold small flex-grow-1">
                    {{ vocabulary().presetTitle(preset.name) }}
                  </span>
                  @if (applied(preset.name)) {
                    <i
                      [class]="
                        'bi bi-check-circle-fill ' +
                        (drifted() ? 'text-warning' : 'text-primary')
                      "
                      aria-hidden="true"
                    ></i>
                  }
                </span>
                <span class="d-block small text-body-secondary mb-2">
                  {{ vocabulary().presetSubtitle(preset.name) }}
                </span>
                @for (line of valueLines(preset); track line) {
                  <span class="d-block small text-body-secondary">
                    {{ line }}
                  </span>
                }
              </button>

              @if (applied(preset.name) && drifted()) {
                <div
                  class="border-top p-3"
                  data-id="hilos-setting-preset-differences"
                >
                  <div class="small fw-semibold text-warning-emphasis mb-1">
                    {{ vocabulary().differencesHeading }}
                  </div>
                  <ul class="small text-warning-emphasis mb-2 ps-3">
                    @for (difference of differences(); track difference.key) {
                      <li>{{ vocabulary().differenceLine(difference) }}</li>
                    }
                  </ul>
                  <button
                    hilosLoadingButton
                    class="btn-sm btn-outline-secondary w-100"
                    [loading]="applyAction.loading()"
                    [disabled]="applyAction.busy()"
                    data-id="hilos-setting-preset-revert"
                    (click)="revert()"
                  >
                    {{ vocabulary().revertLabel }}
                  </button>
                </div>
              }
            </div>
          </div>
        }
      </div>

      <p class="small text-body-secondary">{{ vocabulary().footnote }}</p>

      <div
        class="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mt-4"
      >
        <i class="bi bi-sliders2 text-body-secondary" aria-hidden="true"></i>
        <div class="flex-grow-1">
          <div class="fw-semibold small">
            {{ vocabulary().generalSettingsTitle }}
          </div>
          <div class="small text-body-secondary">
            {{ vocabulary().generalSettingsLead }}
          </div>
        </div>
        <a
          [hilosLink]="settingsPath()"
          class="btn btn-sm btn-outline-secondary text-nowrap"
          data-id="hilos-setting-preset-settings-link"
        >
          {{ vocabulary().generalSettingsLabel }}
          <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
        </a>
      </div>

      <hilos-modal
        [(open)]="confirmOpen"
        [title]="vocabulary().confirmTitle"
        [closeOnBackdrop]="!applyAction.busy()"
        [closeOnEsc]="!applyAction.busy()"
        (cancel)="closeConfirm()"
      >
        <hilos-action-error [action]="applyAction" />
        <p class="mb-0 text-body-secondary">
          {{ vocabulary().confirmBody(confirmTitle()) }}
        </p>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="applyAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="applyAction.loading()"
            data-id="hilos-setting-preset-apply-confirm"
            (click)="confirmApply()"
          >
            {{ vocabulary().confirmLabel(confirmTitle()) }}
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSettingPresetsPage {
  /** The page key the admin shell draws its breadcrumb, heading and lead from. */
  readonly page = input.required<string>()
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  readonly context = input.required<HilosSettingPresetsContext>()
  /** Server→client signal `type` this section's group frame arrives under. */
  readonly signal = input.required<string>()
  /** Everything this section says about its own presets. */
  readonly vocabulary = input.required<HilosSettingPresetsVocabulary>()

  protected readonly applyAction = createHilosTrackedAction()

  private readonly presets = computed(() =>
    createHilosSettingPresets(this.context(), this.signal()),
  )
  private readonly actions = computed(() =>
    createHilosSettingPresetsActions(this.context()),
  )

  // The group frame, mirrored from the (per-context) core signal into an Angular
  // signal so the template re-renders on every push.
  private readonly state = signal<HilosSettingPresetsState | null>(null)

  protected readonly cards = computed(() => presetsOf(this.state()))
  protected readonly differences = computed(() => differencesOf(this.state()))
  protected readonly drifted = computed(() => hasDifferences(this.state()))
  protected readonly unknownSelection = computed(() =>
    isSelectionUnknown(this.state()),
  )
  protected readonly settingsPath = computed(() =>
    resolveHilosPath(this.vocabulary().generalSettingsPage),
  )

  // Confirmation: only a click that would overwrite hand-made edits raises it, and
  // what it destroys is shown in a THIRD card — the applied one — so the ruin does
  // not happen where the person is looking.
  protected readonly confirmOpen = signal(false)
  private readonly confirmPreset = signal<string | null>(null)
  protected readonly confirmTitle = computed(() =>
    this.vocabulary().presetTitle(this.confirmPreset() ?? ''),
  )

  constructor() {
    // Listen for the group frame once the context input is bound and stop on destroy;
    // nothing is requested, the page sends the first frame ahead of releasing the page.
    effect((onCleanup) => {
      const presets = this.presets()
      presets.start()
      this.state.set(presets.state.get())
      const unsubscribe = subscribeSignal(presets.state, (next) => {
        this.state.set(next)
      })
      onCleanup(() => {
        unsubscribe()
        presets.dispose()
      })
    })
  }

  /** Whether this card is the applied one, which is what lights it. */
  protected applied(name: string): boolean {
    return isPresetApplied(this.state(), name)
  }

  /** The frame of a card: lit blue when applied and clean, amber when applied and drifted. */
  protected cardClass(name: string): string {
    if (!this.applied(name)) {
      return ''
    }

    return this.drifted()
      ? 'border-warning bg-warning-subtle'
      : 'border-primary bg-primary-subtle'
  }

  /**
   * Choose a mode: at once when nothing of the person's own is at stake, through
   * the confirmation when their hand-made edits would go with it.
   */
  protected choose(name: string): void {
    this.applyAction.clearError()
    if (this.drifted()) {
      this.confirmPreset.set(name)
      this.confirmOpen.set(true)

      return
    }
    void this.apply(name)
  }

  /**
   * Close the confirmation, taking the refusal it raised with it: the alert over the
   * cards is for a click that applied at once, and a failure left behind would surface
   * there, far from where it was answered.
   */
  protected closeConfirm(): void {
    this.confirmOpen.set(false)
    this.confirmPreset.set(null)
    this.applyAction.clearError()
  }

  /** Confirm the overwrite the person was warned about. */
  protected confirmApply(): void {
    const preset = this.confirmPreset()
    if (preset !== null) {
      void this.apply(preset)
    }
  }

  /** Put the applied mode's values back, which needs no second question. */
  protected revert(): void {
    const selected = selectedPresetOf(this.state())
    if (selected !== null) {
      this.applyAction.clearError()
      void this.apply(selected)
    }
  }

  /** The lines a card lists, read out of the values the preset declares. */
  protected valueLines(preset: HilosSettingPreset): string[] {
    return this.vocabulary().valueLines(preset.values)
  }

  /** Send the apply and, when it was confirmed, close the confirmation on success. */
  private async apply(name: string): Promise<void> {
    const applyOk = await this.applyAction.run(
      this.actions().sendSettingPresetApply(name),
    )
    if (applyOk) {
      this.confirmOpen.set(false)
      this.confirmPreset.set(null)
    }
  }
}
