// The injection key for the auth gate (HIL-165, graduated at HIL-409). The gate is
// created in a project's boot module (it needs the navigator bootHilos returns) and
// provided at the app root next to the router; a live page injects it to pre-empt
// sign-in without a 401 round-trip — a composer's register banner calls
// requireAuth() to open the surface for an anonymous visitor (HIL-360).
//
// The Symbol description is unchanged from the chat demo's own key, so a project
// that provided it before the graduation provides the same key now.
import { type AuthGate } from '@hilos/core'
import type { InjectionKey } from 'vue'

export const hilosAuthGateKey: InjectionKey<AuthGate> = Symbol('hilos.authGate')
