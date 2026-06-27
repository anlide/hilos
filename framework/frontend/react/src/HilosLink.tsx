// HilosLink — the in-place navigation link. It renders a real `<a href>`, so it
// keeps every native affordance (open-in-new-tab, copy address, keyboard focus)
// and degrades to a hard navigation when no router is in context. Only the
// plain primary click is intercepted and handed to the core navigator
// (HilosRouter), turning it into a no-refresh page transition that leaves the
// WebSocket connection untouched. It marks itself aria-current="page" when it
// points at the active route.
import { useContext } from 'react'
import { createSignal } from '@hilos/core'
import type { HilosRouter } from '@hilos/core'
import type { AnchorHTMLAttributes, MouseEvent, ReactNode } from 'react'

import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosLink}: the target path plus any anchor attributes. */
export interface HilosLinkProps extends Omit<
  AnchorHTMLAttributes<HTMLAnchorElement>,
  'href'
> {
  /** The target location pathname. */
  to: string
  children?: ReactNode
}

// A stable empty fallback so useSignal is always called with a real signal even
// without a router (the hard-link fallback); aria-current then stays off.
const NO_PATH = createSignal('')

/**
 * An anchor that navigates in place through the provided {@link HilosRouter}.
 *
 * @param props The target path, link content, and pass-through anchor props.
 */
export function HilosLink({ to, children, onClick, ...rest }: HilosLinkProps) {
  const router: HilosRouter | null = useContext(HilosRouterContext)
  const currentPath = useSignal(router?.currentPath ?? NO_PATH)
  const ariaCurrent = router && currentPath === to ? 'page' : undefined

  function handleClick(event: MouseEvent<HTMLAnchorElement>): void {
    onClick?.(event)
    // Leave modified clicks and non-primary buttons to the browser (new tab,
    // new window); without a router the anchor stays a plain hard link.
    if (
      !router ||
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return
    }
    event.preventDefault()
    router.navigate(to)
  }

  return (
    <a href={to} aria-current={ariaCurrent} onClick={handleClick} {...rest}>
      {children}
    </a>
  )
}
