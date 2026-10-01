// The injection keys carrying whether the controls around a component may only
// look. Two places say so, each about its own region of the screen:
//
// - The admin page shell provides whether a viewer of the admin view mode
//   stands on this page (HIL-1261) — true while the admin section is 'view' to
//   this browser (hilosAdminAccess, HIL-1253).
// - The application shell provides, around the page's area only, whether this
//   session is inside a takeover where the administrator only looks (HIL-1170,
//   hilosTakeoverViewOnly).
//
// The controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the
// bulk operations of a table) read both through useLookOnly() to stand plainly
// disabled, described by the strip that says why.
//
// Why not hilosAdminAccess or hilosTakeoverViewOnly themselves: both are global.
// A signed-in non-admin on a node in the view mode is 'view' on every screen,
// and a control reading it directly would lock that viewer's own profile and the
// shell's controls — the deletion strip's "Keep my account", the impersonation
// strip's Stop (HilosLayout.vue); under a takeover of a non-admin the session
// carries that person's identity. The takeover's flag is provided around the
// page's area for the same reason: Stop must stay live inside a takeover that
// only looks. Outside the admin page shell useAdminViewMode() is always false,
// and outside the page's area the takeover never locks anything.
import {
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
} from '@hilos/core'
import {
  computed,
  inject,
  readonly,
  ref,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue'

/** Provide/inject key for whether a viewer of the admin view mode stands on this page. */
export const hilosAdminViewModeKey: InjectionKey<Readonly<Ref<boolean>>> =
  Symbol('hilosAdminViewMode')

/**
 * Provide/inject key for whether the page's area stands inside a takeover where
 * the administrator only looks (HIL-1170).
 */
export const hilosTakeoverViewOnlyKey: InjectionKey<Readonly<Ref<boolean>>> =
  Symbol('hilosTakeoverViewOnly')

/** What a control outside the region a key is provided around reads: never locked. */
const outsideRegion: Readonly<Ref<boolean>> = readonly(ref(false))

/**
 * Whether a viewer of the admin view mode stands on the admin page around this
 * component; false outside the admin page shell.
 */
export function useAdminViewMode(): Readonly<Ref<boolean>> {
  return inject(hilosAdminViewModeKey, outsideRegion)
}

/** Whether a control may only look, and the strip that says why. */
export interface LookOnly {
  /** Whether the control stands plainly disabled. */
  readonly locked: ComputedRef<boolean>
  /**
   * The id of the strip text the locked control names in `aria-describedby` —
   * both, space-separated, when both say so — or undefined while it is live.
   */
  readonly describedBy: ComputedRef<string | undefined>
}

/**
 * Whether the controls around this component may only look: a viewer of the
 * admin view mode on an admin page (described by the view-mode strip), or the
 * page's area of a takeover where the administrator only looks (described by
 * the impersonation strip).
 */
export function useLookOnly(): LookOnly {
  const viewMode = useAdminViewMode()
  const takeover = inject(hilosTakeoverViewOnlyKey, outsideRegion)

  return {
    locked: computed(() => viewMode.value || takeover.value),
    describedBy: computed(() => {
      const ids = [
        ...(viewMode.value ? [HILOS_VIEW_MODE_STRIP_TEXT_ID] : []),
        ...(takeover.value ? [HILOS_IMPERSONATION_STRIP_TEXT_ID] : []),
      ]

      return ids.length > 0 ? ids.join(' ') : undefined
    }),
  }
}
