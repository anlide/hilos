// The ecommerce-shop demo's sign-in surface: the framework default (@hilos/react
// HilosAuthSurface) with this project's context closed over it. HilosView mounts
// the surface without props, so the context cannot be passed from outside — this
// wrapper is what closes it. The framework owns the machine, the wire, the
// screens and the copy; the project owns only what hilosAuthContext declares.
import { HilosAuthSurface } from '@hilos/react'

import { hilosAuthContext } from './hilosAuthContext.js'

export default function AuthSurface() {
  return <HilosAuthSurface context={hilosAuthContext} />
}
