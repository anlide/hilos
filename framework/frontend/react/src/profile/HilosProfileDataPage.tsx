import { useEffect, useState } from 'react'
import {
  createHilosProfileDataExport,
  HILOS_DATA_EXPORT_COPY,
  type HilosDataExportContext,
} from '@hilos/core'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosDataExport } from './HilosDataExport.js'

/** The profile's personal-copy section, over the shared export flow. */
export function HilosProfileDataPage({
  context,
}: {
  context: HilosDataExportContext
}) {
  const [copy, setCopy] = useState<ReturnType<
    typeof createHilosProfileDataExport
  > | null>(null)
  useEffect(() => {
    const next = createHilosProfileDataExport(context)
    next.store.start()
    setCopy(next)
    return () => {
      next.flow.dispose()
      next.store.dispose()
    }
  }, [context])
  return (
    <section data-id="profile-data-view">
      <HilosPageHeading />
      {copy ? (
        <HilosDataExport
          store={copy.store}
          flow={copy.flow}
          lead={HILOS_DATA_EXPORT_COPY.sectionLead}
          titled={false}
        />
      ) : null}
    </section>
  )
}
