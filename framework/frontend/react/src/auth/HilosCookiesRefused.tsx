// HilosCookiesRefused — the card a browser that refuses cookies sees in place of
// sign-in (HIL-1074). Every sign-in rotates the session token through a cookie,
// so no method can work in such a browser, and a live form would only let the
// person sign in for one second. The card says why and what to do; it has no
// buttons of its own — the frame it stands in closes as it always does.
// Internal to the two auth screens that draw it (HilosAuthSurface and
// HilosMagicLinkPage), not exported from the package. The words are the core's
// COOKIES_REFUSED_COPY, one set for the three view packages.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { COOKIES_REFUSED_COPY } from '@hilos/core'

/** Props for {@link HilosCookiesRefused}. */
export interface HilosCookiesRefusedProps {
  /**
   * The id the heading carries, so the frame around the card is named by it;
   * none — no id at all.
   */
  headingId?: string
  /** Head the card with the line that the sign-in link was not spent. */
  linkKept?: boolean
}

/**
 * The card that tells a cookie-refusing browser why sign-in cannot work here.
 *
 * @param props The heading's id, and whether the link-kept line heads the card.
 */
export function HilosCookiesRefused({
  headingId,
  linkKept = false,
}: HilosCookiesRefusedProps) {
  return (
    <div className="text-center" data-id="cookies-refused">
      {linkKept ? (
        <p className="small mb-3" data-id="cookies-refused-link-kept">
          {COOKIES_REFUSED_COPY.linkKept}
        </p>
      ) : null}
      <span className="bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-3 lh-1 mb-3">
        <i className="bi bi-cookie" aria-hidden="true" />
      </span>
      <h2 id={headingId} className="h5 mb-2" data-id="cookies-refused-heading">
        {COOKIES_REFUSED_COPY.title}
      </h2>
      <p className="small text-body-secondary mb-2">
        {COOKIES_REFUSED_COPY.message}
      </p>
      <p className="small text-body-secondary mb-0">
        {COOKIES_REFUSED_COPY.remedy}
      </p>
    </div>
  )
}
