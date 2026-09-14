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
import type { ReactNode } from 'react'

/** Props for {@link HilosStaticPage}. */
export interface HilosStaticPageProps {
  /** The page heading. */
  title: string
  /** The page content. */
  children?: ReactNode
}

/**
 * The frame for a static content page: a centered reading column with a heading
 * above the project-supplied content.
 *
 * @param props The page title and its content.
 */
export function HilosStaticPage({ title, children }: HilosStaticPageProps) {
  return (
    <article className="row justify-content-center" data-id="static-page">
      <div className="col-12 col-lg-8">
        <h1 className="h3 mb-4" data-id="static-page-title">
          {title}
        </h1>
        {children}
      </div>
    </article>
  )
}
