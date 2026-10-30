import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  untracked,
} from '@angular/core'
import {
  createHilosProfileEmailChangeFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  createHilosTimeZonePicker,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PROFILE_ROOT_COPY,
  hilosImpersonation,
  hilosSignedIn,
  subscribeSignal,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core/base'
import { HilosProfileHead } from './HilosProfileHead.js'
import { LoadingButton } from '../LoadingButton.js'
import { hilosSignal } from '../hilosSignal.js'
import { HilosAccountDeletion } from './HilosAccountDeletion.js'
import { HilosProfileEmailChange } from './HilosProfileEmailChange.js'
import { HilosProfileRename } from './HilosProfileRename.js'
import { HilosProfileTheme } from './HilosProfileTheme.js'
import { HilosProfileTimeZone } from './HilosProfileTimeZone.js'

/**
 * The profile root (HIL-1169): the person's own line, the Account rows (Name,
 * Email, Time zone, Theme), the shared profile head and the danger zone.
 */
@Component({
  selector: 'hilos-profile-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAccountDeletion,
    HilosProfileHead,
    HilosProfileEmailChange,
    HilosProfileRename,
    HilosProfileTheme,
    HilosProfileTimeZone,
    LoadingButton,
  ],
  template: `
    @if (signedIn()) {
      <section data-id="profile-view">
        @if (name()) {
          <hilos-profile-head [context]="context()" [store]="root().head" />
        }
        <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-2">
          {{ copy.account }}
        </h2>
        @if (name()) {
          <div data-id="profile-detail">
            <div class="d-flex align-items-center gap-3 py-3 border-bottom">
              <i
                class="bi bi-person fs-5 text-body-secondary"
                aria-hidden="true"
              ></i>
              <div class="flex-grow-1 text-break">
                <div class="fw-semibold small">{{ copy.name }}</div>
                <div class="small text-body-secondary" data-id="profile-name">
                  {{ name() }}
                </div>
              </div>
              @if (rename(); as flow) {
                <button
                  hilosLoadingButton
                  class="btn-outline-secondary btn-sm"
                  [loading]="renameOpening()"
                  data-id="profile-edit"
                  (click)="flow.open()"
                >
                  {{ copy.change }}
                </button>
              }
            </div>
            @if (verifiedEmail(); as address) {
              <div class="d-flex align-items-center gap-3 py-3 border-bottom">
                <i
                  class="bi bi-envelope fs-5 text-body-secondary"
                  aria-hidden="true"
                ></i>
                <div class="flex-grow-1 text-break">
                  <div class="fw-semibold small">{{ copy.email }}</div>
                  <div
                    class="small text-body-secondary"
                    data-id="profile-email"
                  >
                    {{ copy.verified.replace('{address}', address) }}
                  </div>
                </div>
                <button
                  hilosLoadingButton
                  class="btn-outline-secondary btn-sm"
                  [loading]="emailOpening()"
                  data-id="profile-email-change"
                  (click)="email().open(address)"
                >
                  {{ copy.change }}
                </button>
              </div>
            }
            <div
              class="d-flex align-items-center gap-3 py-3 border-bottom"
              data-id="profile-time-zone-row"
            >
              <i
                class="bi bi-clock fs-5 text-body-secondary"
                aria-hidden="true"
              ></i>
              <div class="flex-grow-1 text-break">
                <div class="fw-semibold small">{{ copy.timeZone }}</div>
                <div
                  class="small text-body-secondary"
                  data-id="profile-time-zone"
                >
                  {{ timeZone() }}
                </div>
              </div>
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                [disabled]="impersonation() !== null"
                [attr.aria-describedby]="impersonation() ? stripTextId : null"
                data-id="profile-time-zone-change"
                (click)="openTimeZone()"
              >
                {{ copy.change }}
              </button>
            </div>
            @if (theme() !== null) {
              <div class="d-flex align-items-center gap-3 py-3 border-bottom">
                <i
                  class="bi bi-circle-half fs-5 text-body-secondary"
                  aria-hidden="true"
                ></i>
                <div class="flex-grow-1 text-break">
                  <div class="fw-semibold small">{{ copy.theme }}</div>
                  <div
                    class="small text-body-secondary"
                    data-id="profile-theme"
                  >
                    {{ theme() }}
                  </div>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm"
                  [disabled]="impersonation() !== null"
                  [attr.aria-describedby]="impersonation() ? stripTextId : null"
                  data-id="profile-theme-change"
                  (click)="themeOpen.set(true)"
                >
                  {{ copy.change }}
                </button>
              </div>
            }
          </div>
        } @else {
          <p class="text-body-secondary" data-id="profile-loading">
            {{ copy.loading }}
          </p>
        }
        <hilos-account-deletion [context]="context()" />
        @if (rename(); as flow) {
          <hilos-profile-rename [flow]="flow" />
        }
        <hilos-profile-email-change [flow]="email()" />
        <hilos-profile-theme [(open)]="themeOpen" />
        <hilos-profile-time-zone
          [picker]="timeZonePicker()"
          [(open)]="timeZoneOpen"
        />
      </section>
    } @else {
      <!-- Nobody signed in, or the session is not known yet: a placeholder,
      never page content. The shell's auth gate mounts the sign-in surface in
      place once the AUTHENTICATED guard answers 401. -->
      <p class="text-body-secondary" data-id="profile-loading">
        {{ copy.loading }}
      </p>
    }
  `,
})
export class HilosProfilePage {
  /** The project's connection, scopes and action lifecycle. */
  readonly context = input.required<HilosProfilePageContext>()
  /** What only the project knows: the name and its rename. */
  readonly binding = input.required<HilosProfileBinding>()
  protected readonly copy = HILOS_PROFILE_ROOT_COPY
  protected readonly root = computed(() =>
    createHilosProfileRootStore(this.context(), this.binding()),
  )
  protected readonly rename = computed(() => {
    const binding = this.binding()
    return binding.rename === null
      ? null
      : createHilosProfileRenameFlow(
          this.context(),
          binding.name,
          binding.rename,
          { remember: true },
        )
  })
  protected readonly email = computed(() =>
    createHilosProfileEmailChangeFlow(this.context()),
  )
  protected readonly timeZonePicker = computed(() =>
    createHilosTimeZonePicker(this.context()),
  )
  protected readonly signedIn = signal(false)
  protected readonly name = signal('')
  protected readonly verifiedEmail = signal<string | null>(null)
  protected readonly theme = signal<string | null>(null)
  protected readonly timeZone = signal('')
  protected readonly impersonation = hilosSignal(hilosImpersonation)
  protected readonly stripTextId = HILOS_IMPERSONATION_STRIP_TEXT_ID
  protected readonly themeOpen = signal(false)
  protected readonly timeZoneOpen = signal(false)
  protected readonly renameOpening = signal(false)
  protected readonly emailOpening = signal(false)

  constructor() {
    // Take the sections once the inputs are bound; let them go on destroy.
    effect((onCleanup) => {
      const root = this.root()
      const rename = this.rename()
      root.start()
      if (rename === null) this.renameOpening.set(false)
      else {
        rename.start()
        this.renameOpening.set(rename.stepUp.busy.get())
      }
      this.signedIn.set(root.signedIn.get())
      this.verifiedEmail.set(root.verifiedEmail.get())
      this.theme.set(root.theme.get())
      this.timeZone.set(root.timeZone.get())
      const off = [
        subscribeSignal(root.signedIn, (value) => this.signedIn.set(value)),
        subscribeSignal(root.verifiedEmail, (value) =>
          this.verifiedEmail.set(value),
        ),
        subscribeSignal(root.theme, (value) => this.theme.set(value)),
        subscribeSignal(root.timeZone, (value) => this.timeZone.set(value)),
      ]
      const stopRenameOpening =
        rename === null
          ? () => {}
          : subscribeSignal(rename.stepUp.busy, (value) =>
              this.renameOpening.set(value),
            )
      onCleanup(() => {
        stopRenameOpening()
        rename?.dispose()
        off.forEach((stop) => stop())
        root.dispose()
      })
    })
    // A sign-out takes the Theme window away with the page; it must not come
    // back by itself with the next sign-in.
    effect(() => {
      if (!this.signedIn()) {
        untracked(() => {
          this.themeOpen.set(false)
          this.timeZoneOpen.set(false)
        })
      }
    })
    effect((onCleanup) => {
      const picker = this.timeZonePicker()
      onCleanup(() => picker.dispose())
    })
    effect((onCleanup) => {
      const name = this.binding().name
      this.name.set(name.get())
      onCleanup(subscribeSignal(name, (value) => this.name.set(value)))
    })
    effect((onCleanup) => {
      const flow = this.email()
      this.emailOpening.set(flow.busy.get())
      const off = subscribeSignal(flow.busy, (value) =>
        this.emailOpening.set(value),
      )
      onCleanup(() => {
        off()
        flow.dispose()
      })
    })
  }

  protected openTimeZone(): void {
    if (hilosSignedIn.get() && hilosImpersonation.get() === null) {
      this.timeZoneOpen.set(true)
    }
  }
}
