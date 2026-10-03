import { useContext, useEffect, useMemo } from 'react'
import {
  hilosNotificationAddressSection,
  startHilosNotificationPreferences,
  type HilosProfileNotificationsContext,
} from '@hilos/core'
import { HilosNotificationPreferences } from '../HilosNotificationPreferences.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosRouterContext } from '../hilosRouterContext.js'
import { useSignal } from '../useSignal.js'

/** The notification channels section of the profile. */
export function HilosProfileNotificationsPage({
  context,
}: {
  context: HilosProfileNotificationsContext
}) {
  useEffect(() => startHilosNotificationPreferences(context), [context])
  const router = useContext(HilosRouterContext)
  const sectionSignal = useMemo(
    () => hilosNotificationAddressSection(context.scopes),
    [context.scopes],
  )
  const addressSection = useSignal(sectionSignal)
  const addressTo = addressSection
    ? router?.resolvePath(addressSection.page)
    : undefined
  return (
    <section data-id="profile-notifications-view">
      <HilosPageHeading />
      <HilosNotificationPreferences
        connection={context.connection}
        addressSection={addressSection}
        addressTo={addressTo}
      />
    </section>
  )
}
