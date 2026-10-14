/** The bounded snapshot returned by the non-production analytics probe. */
export interface AnalyticsBrowserSession {
  readonly browserSessionId: number | null
  readonly actionNames: readonly string[]
}

/**
 * Read the latest loaded browser session of a user in one command round trip.
 * The caller binds the stand address and polls until the writer has caught up.
 *
 * @param sendCommand The stand's command-channel request function.
 * @param userId The last signed-in user's positive integer id.
 */
export async function readAnalyticsBrowserSession(
  sendCommand: (
    command: string,
    payload: Record<string, unknown>,
  ) => Promise<Record<string, unknown>>,
  userId: number,
): Promise<AnalyticsBrowserSession> {
  if (!Number.isSafeInteger(userId) || userId <= 0) {
    throw new Error('Analytics probe needs a positive integer userId')
  }

  const { browserSessionId, actionNames } = await sendCommand(
    'test:analytics:browser-session',
    { userId },
  )
  if (
    !(
      browserSessionId === null ||
      (typeof browserSessionId === 'number' &&
        Number.isSafeInteger(browserSessionId) &&
        browserSessionId > 0)
    ) ||
    !Array.isArray(actionNames) ||
    actionNames.length > 50 ||
    !actionNames.every((name): name is string => typeof name === 'string') ||
    (browserSessionId === null && actionNames.length !== 0)
  ) {
    throw new Error('Invalid analytics browser-session probe reply')
  }

  return { browserSessionId, actionNames }
}
