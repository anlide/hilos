// The shared profile identity and catalog navigation, with one photo window
// owned by this mount.
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
  createHilosProfileHeadStore,
  createHilosProfilePhotoFlow,
  HILOS_PROFILE_PHOTO_COPY,
  HILOS_PROFILE_ROOT_COPY,
  type HilosPageIdentity,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
  type HilosProfilePhotoFlow,
} from '@hilos/core/base'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosPageHead } from '../HilosPageHead.js'
import { HILOS_ROUTER } from '../hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from '../hilosSignal.js'
import { HilosProfilePhoto } from './HilosProfilePhoto.js'

/** The person's one heading, photo window and live catalog page links. */
@Component({
  selector: 'hilos-profile-head',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAvatar, HilosPageHead, HilosProfilePhoto],
  template: `
    <div data-id="profile-identity">
      <hilos-page-head
        [identity]="identity()"
        [page]="route().page ?? ''"
        [params]="route().params"
        [resolvePath]="router.resolvePath"
        kind="entity"
        tabsLabel="Profile pages"
        [visual]="visual"
        [description]="description"
      />
      <ng-template #visual>
        @if (photoEnabled()) {
          <button
            type="button"
            data-id="profile-photo-open"
            class="btn p-0 border-0 bg-transparent position-relative"
            [title]="photoCopy.open"
            [attr.aria-label]="photoCopy.open"
            (click)="photoFlow()?.open()"
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
      </ng-template>
      <ng-template #description>
        @if (passkeyOnly()) {
          <p
            class="small text-warning-emphasis mb-0"
            data-id="profile-sign-in-passkey-only-line"
          >
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i
            >{{ copy.passkeyOnly }}
          </p>
        } @else if (verifiedEmail()) {
          <p
            class="small text-body-secondary mb-0"
            data-id="profile-identity-email"
          >
            {{ verifiedEmail() }}
          </p>
        }
      </ng-template>
      @if (photoEnabled()) {
        @if (photoFlow(); as flow) {
          <hilos-profile-photo [flow]="flow" />
        }
      }
    </div>
  `,
})
export class HilosProfileHead {
  /** The profile page's connection, scopes and tracked actions. */
  readonly context = input.required<HilosProfilePageContext>()
  /** A started store borrowed from the root; its caller owns its lifetime. */
  readonly store = input<HilosProfileHeadStore>()

  protected readonly copy = HILOS_PROFILE_ROOT_COPY
  protected readonly photoCopy = HILOS_PROFILE_PHOTO_COPY
  protected readonly router = inject(HILOS_ROUTER)
  protected readonly route = hilosSignal(this.router.currentRoute)
  private readonly head = computed(
    () => this.store() ?? createHilosProfileHeadStore(this.context()),
  )
  protected readonly identity = signal<HilosPageIdentity | undefined>(undefined)
  protected readonly name = signal('')
  protected readonly photo = signal<string | null>(null)
  protected readonly photoEnabled = signal(false)
  protected readonly verifiedEmail = signal<string | null>(null)
  protected readonly passkeyOnly = signal(false)
  protected readonly photoFlow = signal<HilosProfilePhotoFlow | null>(null)

  constructor() {
    effect((onCleanup) => {
      const context = this.context()
      const head = this.head()
      const owned = this.store() === undefined
      if (owned) head.start()
      const flow = createHilosProfilePhotoFlow(context, head.photo)
      this.photoFlow.set(flow)
      const off = [
        mirrorHilosSignal(head.identity, this.identity),
        mirrorHilosSignal(head.name, this.name),
        mirrorHilosSignal(head.photo, this.photo),
        mirrorHilosSignal(head.photoEnabled, this.photoEnabled),
        mirrorHilosSignal(head.verifiedEmail, this.verifiedEmail),
        mirrorHilosSignal(head.passkeyOnly, this.passkeyOnly),
      ]
      onCleanup(() => {
        off.forEach((stop) => stop())
        flow.dispose()
        if (owned) head.dispose()
      })
    })
  }
}
