import { createContext, useContext } from 'react'

/** Context carrying whether a component is mounted inside {@link HilosModal}. */
export const HilosModalContext = createContext<boolean>(false)

/** Hook returning true when mounted inside {@link HilosModal}, false otherwise. */
export function useHilosModal(): boolean {
  return useContext(HilosModalContext)
}
