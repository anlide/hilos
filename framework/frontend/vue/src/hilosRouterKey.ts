// The injection key carrying the core navigator (HilosRouter) to the SDK's
// navigation components. A project provides it once at the app root
// (`app.provide(hilosRouterKey, router)`); HilosLink and HilosView inject it.
import type { InjectionKey } from 'vue'
import type { HilosRouter } from '@hilos/core'

/** Provide/inject key for the application's {@link HilosRouter}. */
export const hilosRouterKey: InjectionKey<HilosRouter> = Symbol('hilosRouter')
