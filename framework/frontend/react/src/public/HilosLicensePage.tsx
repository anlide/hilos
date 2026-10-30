// HilosLicensePage is the public License document. The project snapshot carries
// its exact license text; this page preserves it as plain text inside the shared
// static-page frame.
import type {
  HilosLicenseInventory,
  HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosStaticPage } from '../HilosStaticPage.js'

/** Props for {@link HilosLicensePage}. */
export interface HilosLicensePageProps {
  /** The build-time snapshot, including the project's exact license text. */
  inventory: HilosLicenseInventory
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}

/**
 * Render the project's License document without parsing or formatting it.
 *
 * @param props The build-time snapshot and optional public-page navigation.
 */
export function HilosLicensePage({
  inventory,
  navigation,
}: HilosLicensePageProps) {
  return (
    <HilosStaticPage
      title="License"
      icon="bi-journal-code"
      subtitle="The license of this project, and of everything it is built on."
      navigation={navigation}
    >
      <pre
        className="small text-body mb-3 text-break hilos-pre-wrap"
        data-id="license-project-text"
      >
        {inventory.projectLicenseText}
      </pre>
    </HilosStaticPage>
  )
}
