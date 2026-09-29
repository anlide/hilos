// The injection key carrying whether a viewer of the admin view mode stands on
// this page (HIL-1261). The admin page shell provides it — true while the admin
// section is 'view' to this browser (hilosAdminAccess, HIL-1253) — and the
// controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the bulk
// operations of a table) read it to stand plainly disabled.
//
// Why not hilosAdminAccess itself: it is global. A signed-in non-admin on a
// node in the view mode is 'view' on every screen, and a control reading it
// directly would lock that viewer's own profile and the shell's controls — the
// deletion strip's "Keep my account", the impersonation strip's Stop
// (HilosLayout.vue); under a takeover of a non-admin the session carries that
// person's identity. Outside the admin page shell useAdminViewMode() is always
// false.
import { inject, readonly, ref, type InjectionKey, type Ref } from 'vue'

/** Provide/inject key for whether a viewer of the admin view mode stands on this page. */
export const hilosAdminViewModeKey: InjectionKey<Readonly<Ref<boolean>>> =
  Symbol('hilosAdminViewMode')

/** What a control outside the admin page shell reads: never the view mode. */
const outsideAdminPage: Readonly<Ref<boolean>> = readonly(ref(false))

/**
 * Whether a viewer of the admin view mode stands on the admin page around this
 * component; false outside the admin page shell.
 */
export function useAdminViewMode(): Readonly<Ref<boolean>> {
  return inject(hilosAdminViewModeKey, outsideAdminPage)
}
