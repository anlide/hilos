// HilosStaticPage — the tier-1 wrapper for a static, content-only framework page
// (About / Terms / Privacy / License and the like). The framework owns the page
// frame — a centered single reading column with a heading — and a project
// supplies the content as children, so the look stays uniform while the content
// stays a project concern. Long content scrolls within the shell's main region
// (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
// (styling-rules.md).
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
