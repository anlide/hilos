// React context carrying the auth gate (HIL-165, graduated at HIL-409) to the
// SDK's sign-in surface. The gate is created in a project's boot module (it
// needs the navigator bootHilos returns) and provided at the app root next to
// HilosRouterContext; a live page reads it to pre-empt sign-in without a 401
// round-trip — a composer's register banner calls requireAuth() to open the
// surface for an anonymous visitor (HIL-360).
//
// It is the React peer of the Vue injection key (hilosAuthGateKey): HilosView
// takes the surface as a bare ComponentType and passes it no props, so the
// surface fetches the gate itself. The default is null because the surface must
// work with no provider — on a 401 it stands IN PLACE of the page, where there
// may be no gate at all.
import { createContext } from 'react'
import type { AuthGate } from '@hilos/core'

/** Provides the application's {@link AuthGate} to the sign-in surface. */
export const HilosAuthGateContext = createContext<AuthGate | null>(null)
