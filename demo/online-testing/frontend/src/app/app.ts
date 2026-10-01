// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import { ChangeDetectionStrategy, Component, inject } from '@angular/core'
import type { Type } from '@angular/core'
import {
  HILOS_AUTH_GATE,
  HilosAvatar,
  HilosLayout,
  HilosView,
  hilosAdminViews,
  hilosSignal,
} from '@hilos/angular'
import { HilosPages, hilosSessionAvatarMark } from '@hilos/core'

import { AuthSurface } from './auth/authSurface.js'
import { currentUserIsAdmin, currentUserName } from './bootstrap/session.js'
import { connection } from './bootstrap/connection.js'
import { PAGE_MAIN } from './pages/keys.js'
import { About } from './views/about/about.js'
import { License } from './views/license/license.js'
import { Main } from './views/main/main.js'
import { MainSkeleton } from './views/main/main-skeleton.js'
import { Privacy } from './views/privacy/privacy.js'
import { Terms } from './views/terms/terms.js'

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAvatar, HilosLayout, HilosView],
  // The user region is ONE projected node, not two: the shell's slot is
  // `<ng-content select="[user]">`, which matches the root nodes of what is
  // projected, and a conditional block sitting on that boundary is the known
  // Angular trap. ngProjectAs names the slot explicitly and the branches live
  // safely inside it.
  template: `<hilos-layout [connection]="connection" [isAdmin]="isAdmin()">
    <span brand>
      <i class="bi bi-mortarboard" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there (mockups/framework/layout, "На узком экране"). -->
      <span class="d-none d-md-inline ms-1">Hilos Testing</span>
      <span class="visually-hidden d-md-none">Hilos Testing</span>
    </span>
    <ng-container ngProjectAs="[user]">
      @if (userName()) {
        <!-- The avatar is not a link: the demo has no profile of its own, and
        signing out is the shell's own button. -->
        <span class="small" data-id="nav-profile-name" [title]="userName()">
          <hilos-avatar [name]="userName()" [mark]="avatarMark()" />
          <span class="visually-hidden">{{ userName() }}</span>
        </span>
      } @else {
        <!-- A visitor gets no gear — there is nothing to show — and one button
        that opens the surface over the page they are standing on
        (mockups/framework/layout, the "guest" tile). -->
        <button
          type="button"
          class="btn btn-sm btn-primary"
          data-id="nav-signin"
          (click)="authGate.requireAuth()"
        >
          Sign in
        </button>
      }
    </ng-container>
    <hilos-view
      [pages]="pages"
      [pageSkeletons]="pageSkeletons"
      [authSurface]="authSurfaceType"
      [authGate]="authGate"
    />
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  protected readonly isAdmin = hilosSignal(currentUserIsAdmin)

  protected readonly userName = hilosSignal(currentUserName)

  // The standing mark by the avatar (HIL-945): a takeover, or the session's own
  // scheduled deletion, in the color of the strip that says it in words.
  protected readonly avatarMark = hilosSignal(hilosSessionAvatarMark)

  /**
   * The application's auth gate. Injected and not taken as an input: the root
   * component of `bootstrapApplication` accepts none, and the shell's Sign in
   * button calls the gate directly while HilosView needs it to open the sign-in
   * modal over a live page.
   */
  protected readonly authGate = inject(HILOS_AUTH_GATE)

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

  // The page-key → view map HilosView renders from. A page with no mapped view
  // renders nothing; a page the backend does not register is refused by the
  // server, and the outlet draws that refusal instead.
  protected readonly pages: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: Main,
    // The Hilos admin section. The framework ships a real default page for every
    // admin key (hilosAdminViews), the dashboard included, and this demo registers
    // the dashboard alone: no admin section is switched on here yet.
    ...hilosAdminViews(),
    [HilosPages.ABOUT]: About,
    [HilosPages.TERMS]: Terms,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.LICENSE]: License,
  }

  // The pages that draw a skeleton of their own shape while they wait for their
  // first answer (HIL-983); every other page gets the outlet's default skeleton.
  protected readonly pageSkeletons: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: MainSkeleton,
  }
}
