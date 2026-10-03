import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  createHilosProfileEmailChangeFlow,
  createHilosProfilePhotoFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  hilosChildLinks,
  HilosPages,
  HILOS_PROFILE_ROOT_COPY,
  HILOS_PROFILE_PHOTO_COPY,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  subscribeSignal,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosLink } from '../HilosLink.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { LoadingButton } from '../LoadingButton.js'
import { HILOS_ROUTER } from '../hilosRouterToken.js'
import { hilosSignal } from '../hilosSignal.js'
import { HilosAccountDeletion } from './HilosAccountDeletion.js'
import { HilosProfileEmailChange } from './HilosProfileEmailChange.js'
import { HilosProfileRename } from './HilosProfileRename.js'
import { HilosProfilePhoto } from './HilosProfilePhoto.js'

/**
 * The profile root (HIL-1169): the person's own line, the Account rows, a row
 * per catalog section with its live summary, and the danger zone.
 */
@Component({
  selector: 'hilos-profile-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAccountDeletion,
    HilosAvatar,
    HilosLink,
    HilosPageHeading,
    HilosProfileEmailChange,
    HilosProfileRename,
    HilosProfilePhoto,
    LoadingButton,
  ],
  template: `
    @if (signedIn()) {
      <section data-id="profile-view">
        <hilos-page-heading />
        @if (name()) {
          <div
            class="d-flex align-items-center gap-3 mt-3"
            data-id="profile-identity"
          >
            @if (photoEnabled()) {
              <button
                type="button"
                data-id="profile-photo-open"
                class="btn p-0 border-0 bg-transparent position-relative"
                [title]="photoCopy.open"
                [attr.aria-label]="photoCopy.open"
                (click)="photoFlow().open()"
              >
                <hilos-avatar [name]="name()" [photo]="photo()" size="lg" />
                <span
                  class="position-absolute hilos-avatar-camera bg-body border rounded-circle d-inline-flex align-items-center justify-content-center text-body-emphasis"
                  aria-hidden="true"
                  ><i class="bi bi-camera"></i
                ></span>
              </button>
            } @else {
              <hilos-avatar [name]="name()" [photo]="photo()" size="lg" />
            }
            <div class="flex-grow-1 text-break">
              <div class="h5 mb-0" data-id="profile-identity-name">
                {{ name() }}
              </div>
              @if (verifiedEmail(); as address) {
                <div
                  class="small text-body-secondary"
                  data-id="profile-identity-email"
                >
                  {{ address }}
                </div>
              }
            </div>
          </div>
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
          </div>
        } @else {
          <p class="text-body-secondary" data-id="profile-loading">
            {{ copy.loading }}
          </p>
        }
        <section class="mt-4" aria-labelledby="profile-sections-heading">
          <h2
            id="profile-sections-heading"
            class="h6 text-uppercase text-body-secondary mb-2"
          >
            {{ copy.sections }}
          </h2>
          @for (section of sections(); track section.page) {
            <div
              class="d-flex align-items-center gap-3 py-3 border-bottom"
              data-id="profile-section"
            >
              <i
                class="bi fs-5 text-body-secondary"
                [class]="sectionIcon(section.page)"
                aria-hidden="true"
              ></i>
              <div class="flex-grow-1 text-break">
                <div class="fw-semibold small">{{ section.label }}</div>
                <div
                  class="small text-body-secondary"
                  [attr.data-id]="sectionId(section.page) + '-summary'"
                >
                  {{ summaries()[section.page] }}
                </div>
                @if (section.page === signInPage && passkeyOnly()) {
                  <div
                    class="small text-warning-emphasis"
                    data-id="profile-sign-in-passkey-only-line"
                  >
                    <i
                      class="bi bi-exclamation-triangle me-1"
                      aria-hidden="true"
                    ></i
                    >{{ copy.passkeyOnly }}
                  </div>
                }
              </div>
              <a
                [hilosLink]="section.to"
                class="btn btn-sm btn-outline-secondary"
                [attr.data-id]="sectionId(section.page) + '-open'"
                >{{ copy.open }}</a
              >
            </div>
          }
        </section>
        <hilos-account-deletion [context]="context()" />
        @if (rename(); as flow) {
          <hilos-profile-rename [flow]="flow" />
        }
        @if (photoEnabled()) {
          <hilos-profile-photo [flow]="photoFlow()" />
        }
        <hilos-profile-email-change [flow]="email()" />
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
  /** What only the project knows: the name, its rename, its lists. */
  readonly binding = input.required<HilosProfileBinding>()
  protected readonly copy = HILOS_PROFILE_ROOT_COPY
  protected readonly photoCopy = HILOS_PROFILE_PHOTO_COPY
  private readonly router = inject(HILOS_ROUTER)
  private readonly identity = hilosSignal(this.router.pageIdentity)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly sections = computed(() =>
    hilosChildLinks(
      this.identity()?.children ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
  private readonly root = computed(() =>
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
        )
  })
  protected readonly email = computed(() =>
    createHilosProfileEmailChangeFlow(this.context()),
  )
  protected readonly photoFlow = computed(() =>
    createHilosProfilePhotoFlow(this.context(), this.root().photo),
  )
  protected readonly signedIn = signal(false)
  protected readonly photoEnabled = signal(false)
  protected readonly photo = signal<string | null>(null)
  protected readonly name = signal('')
  protected readonly summaries = signal<Readonly<Record<string, string>>>({})
  protected readonly verifiedEmail = signal<string | null>(null)
  protected readonly passkeyOnly = signal(false)
  protected readonly signInPage = HilosPages.PROFILE_SIGN_IN
  protected readonly renameOpening = signal(false)
  protected readonly emailOpening = signal(false)
  protected readonly sectionIcon = hilosProfileSectionIcon
  protected readonly sectionId = hilosProfileSectionId

  constructor() {
    // Take the sections once the inputs are bound; let them go on destroy.
    effect((onCleanup) => {
      const root = this.root()
      root.start()
      this.signedIn.set(root.signedIn.get())
      this.photoEnabled.set(root.photoEnabled.get())
      this.photo.set(root.photo.get())
      this.summaries.set(root.summaries.get())
      this.verifiedEmail.set(root.verifiedEmail.get())
      this.passkeyOnly.set(root.passkeyOnly.get())
      const off = [
        subscribeSignal(root.signedIn, (value) => this.signedIn.set(value)),
        subscribeSignal(root.photoEnabled, (value) =>
          this.photoEnabled.set(value),
        ),
        subscribeSignal(root.photo, (value) => this.photo.set(value)),
        subscribeSignal(root.summaries, (value) => this.summaries.set(value)),
        subscribeSignal(root.verifiedEmail, (value) =>
          this.verifiedEmail.set(value),
        ),
        subscribeSignal(root.passkeyOnly, (value) =>
          this.passkeyOnly.set(value),
        ),
      ]
      onCleanup(() => {
        off.forEach((stop) => stop())
        root.dispose()
      })
    })
    effect((onCleanup) => {
      const name = this.binding().name
      this.name.set(name.get())
      onCleanup(subscribeSignal(name, (value) => this.name.set(value)))
    })
    // Change waits while the window asks whether the confirmation is needed.
    effect((onCleanup) => {
      const flow = this.rename()
      if (flow === null) return
      this.renameOpening.set(flow.stepUp.busy.get())
      const off = subscribeSignal(flow.stepUp.busy, (value) =>
        this.renameOpening.set(value),
      )
      onCleanup(() => {
        off()
        flow.dispose()
      })
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
    effect((onCleanup) => {
      const flow = this.photoFlow()
      onCleanup(() => flow.dispose())
    })
  }
}
