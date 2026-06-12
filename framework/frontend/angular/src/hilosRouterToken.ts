// The injection token carrying the core navigator (HilosRouter) to the SDK's
// navigation declarables. A project provides it once at the app root
// (`{ provide: HILOS_ROUTER, useValue: router }`); HilosLink and HilosView
// inject it.
import { InjectionToken } from '@angular/core'
import type { HilosRouter } from '@hilos/core'

/** Provide/inject token for the application's {@link HilosRouter}. */
export const HILOS_ROUTER = new InjectionToken<HilosRouter>('HilosRouter')
