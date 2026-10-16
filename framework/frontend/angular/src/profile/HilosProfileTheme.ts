// The account's live theme choice (HIL-1441, the Angular port of HIL-1437).
// Each click is one tracked write, with no draft or Save. Only the server
// handshake moves the mark.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  model,
  signal,
  untracked,
} from '@angular/core'
import {
  hilosImpersonation,
  hilosSignedIn,
  hilosThemeChoice,
  sendHilosThemePick,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePick,
} from '@hilos/core'

import { HilosActionError } from '../HilosActionError.js'
import { HilosModal } from '../HilosModal.js'
import { hilosSignal } from '../hilosSignal.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

/**
 * Whether an account choice can be sent at this moment, read from the live
 * signals rather than from the last change detection.
 */
function available(): boolean {
  return (
    hilosThemeChoice.get() !== null &&
    hilosSignedIn.get() &&
    hilosImpersonation.get() === null
  )
}

/**
 * The Theme window of the profile: three live positions and the reset.
 */
@Component({
  selector: 'hilos-profile-theme',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosModal],
  template: `
    <hilos-modal
      [open]="shown()"
      [title]="copy.menu"
      initialFocus="dialog"
      (openChange)="open.set($event)"
    >
      <div data-id="profile-theme-modal">
        <hilos-action-error
          [action]="action"
          detailsTitle="Couldn't change theme"
        />
        <div class="list-group">
          @for (row of positions; track row.value) {
            <button
              type="button"
              class="list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative"
              [class.active]="row.value === choice()?.position"
              [attr.aria-current]="
                row.value === choice()?.position ? 'true' : null
              "
              [disabled]="busy()"
              [attr.aria-busy]="
                busy() && submitted() === row.value ? true : null
              "
              [attr.data-id]="'profile-theme-option-' + row.value"
              (click)="choose(row.value)"
            >
              <i class="bi" [class]="row.icon" aria-hidden="true"></i>
              <span class="flex-grow-1">{{ row.label }}</span>
              @if (row.value === choice()?.position && choice()?.isDefault) {
                <span
                  class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
                  >{{ copy.default }}</span
                >
              }
              <i
                class="bi bi-check2"
                [class.invisible]="
                  row.value !== choice()?.position ||
                  (loading() && submitted() === row.value)
                "
                aria-hidden="true"
              ></i>
              @if (loading() && submitted() === row.value) {
                <span
                  class="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
                  role="status"
                  data-id="profile-theme-loading"
                  ><span class="visually-hidden">Loading…</span></span
                >
              }
            </button>
          }
        </div>
        <hr />
        <button
          type="button"
          class="btn btn-outline-secondary w-100 position-relative"
          [disabled]="busy()"
          [attr.aria-busy]="busy() && submitted() === null ? true : null"
          data-id="profile-theme-default"
          (click)="choose(null)"
        >
          <span [class.invisible]="loading() && submitted() === null">{{
            copy.siteDefault
          }}</span>
          @if (loading() && submitted() === null) {
            <span
              class="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
              role="status"
              data-id="profile-theme-loading"
              ><span class="visually-hidden">Loading…</span></span
            >
          }
        </button>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-theme-close"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfileTheme {
  /** Whether the profile's Change asked for the window (two-way `[(open)]`). */
  readonly open = model(false)

  protected readonly choice = hilosSignal(hilosThemeChoice)
  private readonly signedIn = hilosSignal(hilosSignedIn)
  private readonly impersonation = hilosSignal(hilosImpersonation)
  private readonly allowed = computed(
    () =>
      this.choice() !== null &&
      this.signedIn() &&
      this.impersonation() === null,
  )
  protected readonly shown = computed(() => this.open() && this.allowed())
  protected readonly action = createHilosTrackedAction()
  protected readonly busy = this.action.busy
  protected readonly loading = this.action.loading
  protected readonly submitted = signal<ThemePick>(null)
  protected readonly positions = THEME_POSITIONS
  protected readonly copy = THEME_COPY
  // An answer belongs to the opening that sent it. Closing and reopening while
  // the write is pending must not let its success close the new opening.
  private opening = 0

  constructor() {
    effect(() => {
      const asked = this.open()
      const allowed = this.allowed()
      untracked(() => {
        this.opening++
        if (asked) {
          this.action.clearError()
          if (!allowed) this.open.set(false)
        }
      })
    })
    inject(DestroyRef).onDestroy(() => this.opening++)
  }

  protected async choose(pick: ThemePick): Promise<void> {
    if (!this.open() || this.action.busy() || !available()) {
      return
    }
    const sentFrom = this.opening
    this.submitted.set(pick)
    const success = await this.action.run(sendHilosThemePick(pick))
    if (success && this.opening === sentFrom && this.open() && available()) {
      this.open.set(false)
    }
  }
}
