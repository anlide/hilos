// HilosStaticPage — the frame for a static page: a centered reading column
// with a heading, the project filling the body. It frames a project's own static
// pages, and it is what the four public framework pages render inside themselves;
// it is neither their component nor widened for their behavior. A project
// supplies the content as children, so the look stays uniform while the content
// stays a project concern. Long content scrolls within the shell's main region
// (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
// (styling-rules.md). No demo carries a static page of its own, because all four
// static pages a demo shows belong to the framework; the frame stays exported for
// a project that has one.
import { useId } from 'react'
import type { ReactNode } from 'react'
import type {
  HilosPageIdentity,
  HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosPageHead } from './HilosPageHead.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'

/** Props for {@link HilosStaticPage}. */
export interface HilosStaticPageProps {
  /** The page heading. */
  title: string
  /** A decorative Bootstrap icon beside the heading. */
  icon?: string
  /** The line under the heading. */
  subtitle?: string
  /** The page's optional section navigation. */
  navigation?: HilosStaticPageNavigation
  /** The page content. */
  children?: ReactNode
}

/**
 * The frame for a static content page: a centered reading column with a heading
 * above the project-supplied content.
 *
 * @param props The page presentation, navigation and content.
 */
export function HilosStaticPage({
  title,
  icon,
  subtitle,
  navigation,
  children,
}: HilosStaticPageProps) {
  const headingId = useId()
  const page = navigation?.page ?? ''
  const identity: HilosPageIdentity = {
    label: title,
    lead: subtitle ?? '',
    breadcrumb: [],
    children: [],
    pageHeadOwner: {
      page: navigation?.tabs[0]?.page ?? page,
      label: title,
      lead: subtitle ?? '',
      icon: icon ?? null,
    },
    pageTabs: [...(navigation?.tabs ?? [])],
  }

  return (
    <HilosPageHeadingIdContext.Provider value={headingId}>
      <article className="row justify-content-center" data-id="static-page">
        <div className="col-12 col-lg-8">
          <HilosPageHead
            identity={identity}
            page={page}
            params={navigation?.params ?? {}}
            resolvePath={navigation?.resolvePath ?? (() => undefined)}
            headingId={headingId}
            kind="section"
            tabsLabel={navigation?.label ?? 'Page sections'}
          />
          {children}
        </div>
      </article>
    </HilosPageHeadingIdContext.Provider>
  )
}
