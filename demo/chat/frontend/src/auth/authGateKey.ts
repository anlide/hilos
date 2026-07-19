// The project injection key for the auth gate (HIL-165). The gate is created in
// bootstrap/main (it needs the navigator bootHilos returns) and provided at the
// app root next to the router; a live page injects it to pre-empt sign-in without
// a 401 round-trip — the main composer's register banner calls requireAuth() to
// open the surface for an anonymous visitor (HIL-360).
import type { InjectionKey } from 'vue'
import type { AuthGate } from '@hilos/core'

export const authGateKey: InjectionKey<AuthGate> = Symbol('hilos.authGate')
