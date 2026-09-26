import { useEffect } from 'react'
import {
  startHilosNotificationPreferences,
  type HilosProfileNotificationsContext,
} from '@hilos/core'
import { HilosNotificationPreferences } from '../HilosNotificationPreferences.js'
import { HilosPageHeading } from '../HilosPageHeading.js'

/** The notification channels section of the profile. */
export function HilosProfileNotificationsPage({
  context,
}: {
  context: HilosProfileNotificationsContext
}) {
  useEffect(() => startHilosNotificationPreferences(context), [context])
  return (
    <section data-id="profile-notifications-view">
      <HilosPageHeading />
      <HilosNotificationPreferences connection={context.connection} />
    </section>
  )
}
