// The injection token carrying the auth gate (HIL-165, graduated at HIL-409) to
// the SDK's sign-in surface. The gate is created in a project's boot module (it
// needs the navigator bootHilos returns) and provided at the app root next to
// HILOS_ROUTER; a live page injects it to pre-empt sign-in without a 401
// round-trip — a composer's register banner calls requireAuth() to open the
// surface for an anonymous visitor (HIL-360).
//
// It is the Angular peer of the Vue injection key (hilosAuthGateKey) and the
// React context (HilosAuthGateContext): HilosView renders the surface through
// ngComponentOutlet, which passes no inputs, so the surface fetches the gate
// itself — and does so optionally, because on a 401 it stands IN PLACE of the
// page, where there may be no gate at all.
import { InjectionToken } from '@angular/core'
import type { AuthGate } from '@hilos/core'

/** Provide/inject token for the application's {@link AuthGate}. */
export const HILOS_AUTH_GATE = new InjectionToken<AuthGate>('HilosAuthGate')
