import type { Page } from '@playwright/test'

/** Page-side record of the options handed to the native creation ceremony. */
const PASSKEY_CREATIONS_HOOK = '__hilosE2ePasskeyCreations'
const COSE_ES256 = -7
const COSE_RS256 = -257

/** Creation options without challenges, user handles or credential ids. */
export interface PasskeyCreation {
  algorithms: number[]
  residentKey: string | null
  userVerification: string | null
  attestation: string | null
  timeout: number | null
  excluded: number
}

/** The signing algorithms a promised authenticator can answer with. */
export interface PasskeyPlatform {
  name: string
  algorithms: readonly number[]
}

/**
 * Compatibility knowledge, not measurements of the devices in the current run.
 * Hybrid uses the phone's authenticator, so it needs no separate algorithm row.
 */
export const PASSKEY_PLATFORMS: readonly PasskeyPlatform[] = [
  /** Many TPM-backed Windows Hello devices sign only with RS256 (HIL-658). */
  { name: 'Windows Hello (TPM)', algorithms: [COSE_RS256] },
  // ES256 is supported by each phone and key below; see backend PasskeyAlgorithm.
  { name: 'iCloud Keychain (iPhone, Mac)', algorithms: [COSE_ES256] },
  { name: 'Google Password Manager (Android)', algorithms: [COSE_ES256] },
  { name: 'FIDO2 security key', algorithms: [COSE_ES256] },
]

/**
 * Records creation requests and passes them unchanged to the native API.
 * Install before loading the page; the virtual authenticator still performs
 * the ceremony, with the original promise and errors reaching the caller.
 *
 * @param page the page whose creation requests are to be judged.
 */
export async function watchPasskeyCreation(page: Page): Promise<void> {
  await page.addInitScript((hook: string) => {
    const creations: PasskeyCreation[] = []
    const nativeCreate = navigator.credentials.create
    navigator.credentials.create = function (options) {
      if (options?.publicKey) {
        const publicKey = options.publicKey
        creations.push({
          algorithms: publicKey.pubKeyCredParams.map(({ alg }) => alg),
          residentKey: publicKey.authenticatorSelection?.residentKey ?? null,
          userVerification:
            publicKey.authenticatorSelection?.userVerification ?? null,
          attestation: publicKey.attestation ?? null,
          timeout: publicKey.timeout ?? null,
          excluded: publicKey.excludeCredentials?.length ?? 0,
        })
      }
      return nativeCreate.call(this, options)
    }
    Object.defineProperty(window, hook, { value: creations })
  }, PASSKEY_CREATIONS_HOOK)
}

/**
 * Reads this document's recorded requests without consuming them.
 *
 * @param page a page armed with {@link watchPasskeyCreation} before it loaded.
 */
export function readPasskeyCreations(page: Page): Promise<PasskeyCreation[]> {
  return page.evaluate(
    (hook) => (window as unknown as Record<string, PasskeyCreation[]>)[hook],
    PASSKEY_CREATIONS_HOOK,
  )
}

/**
 * Names every promised platform left without a usable signing algorithm.
 *
 * @param creation the options actually handed to the browser.
 */
export function platformsLeftOut(creation: PasskeyCreation): string[] {
  return PASSKEY_PLATFORMS.filter(
    (platform) =>
      !platform.algorithms.some((algorithm) =>
        creation.algorithms.includes(algorithm),
      ),
  ).map(
    (platform) =>
      `${platform.name}: signs only with ${platform.algorithms.join(', ')}, the request offers ${creation.algorithms.join(', ')}`,
  )
}
