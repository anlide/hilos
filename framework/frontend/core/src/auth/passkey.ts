// The WebAuthn/passkey browser-API wrapper (HIL-284): the thin, transport-free
// adapter between the backend's base64url `publicKey` options wire shape and the
// native `navigator.credentials` create/get calls, plus the base64url codec both
// sides speak. It lives in @hilos/core so every demo (and HIL-361's framework
// default surface) drives passkeys through one implementation.
//
// The backend mints spec-shaped PublicKeyCredential{Creation,Request}Options with
// their binary fields base64url-encoded (a JSON boundary cannot carry
// ArrayBuffers). This module decodes those fields back to ArrayBuffers for the
// browser, runs the ceremony, and re-encodes the authenticator's binary response
// fields to base64url for the confirm action — so the flow driver never touches a
// buffer. It owns no connection or flow state; the project ceremony driver
// (`auth/passkeyCeremony`) dispatches the actions and awaits the options signal.

/**
 * A WebAuthn credential descriptor in the backend wire shape: the credential id
 * is base64url (decoded to an ArrayBuffer before the ceremony). Used for both the
 * register `excludeCredentials` and the login `allowCredentials` lists.
 */
export interface PasskeyDescriptor {
  /** Always `public-key`. */
  readonly type: 'public-key'
  /** base64url credential id. */
  readonly id: string
  /** Authenticator transports hint, e.g. `['internal']`; may be absent. */
  readonly transports?: readonly string[]
}

/**
 * The register-ceremony `publicKey` options in the backend wire shape (binary
 * fields base64url). Mirrors PublicKeyCredentialCreationOptions; the `challenge`
 * and `user.id` are decoded to ArrayBuffers before {@link createPasskey} calls
 * `navigator.credentials.create`.
 */
export interface PasskeyCreationOptions {
  /** base64url challenge. */
  readonly challenge: string
  /** Relying party. */
  readonly rp: { readonly id?: string; readonly name: string }
  /** User handle (base64url `id`) and display fields. */
  readonly user: {
    readonly id: string
    readonly name: string
    readonly displayName: string
  }
  /** Accepted credential algorithms, e.g. ES256. */
  readonly pubKeyCredParams: readonly PublicKeyCredentialParameters[]
  /** Authenticator selection criteria (resident key, user verification). */
  readonly authenticatorSelection?: AuthenticatorSelectionCriteria
  /** Attestation conveyance; the backend uses `none`. */
  readonly attestation?: AttestationConveyancePreference
  /** The user's existing passkeys, excluded from re-registration. */
  readonly excludeCredentials?: readonly PasskeyDescriptor[]
  /** Ceremony timeout in milliseconds. */
  readonly timeout?: number
}

/**
 * The login-ceremony `publicKey` options in the backend wire shape (binary fields
 * base64url). Mirrors PublicKeyCredentialRequestOptions; the `challenge` and each
 * `allowCredentials` id are decoded before {@link getPasskey} calls
 * `navigator.credentials.get`.
 */
export interface PasskeyRequestOptions {
  /** base64url challenge. */
  readonly challenge: string
  /** Relying party id the assertion is scoped to. */
  readonly rpId?: string
  /** The candidate credentials (base64url ids); a single fabricated entry for an unknown account. */
  readonly allowCredentials?: readonly PasskeyDescriptor[]
  /** User verification requirement. */
  readonly userVerification?: UserVerificationRequirement
  /** Ceremony timeout in milliseconds. */
  readonly timeout?: number
}

/**
 * The register confirm payload the backend `passkey_register_confirm` action
 * expects, all binary fields re-encoded base64url.
 */
export interface PasskeyRegistrationResponse {
  /** base64url CBOR attestation object. */
  readonly attestationObject: string
  /** base64url client data JSON. */
  readonly clientDataJson: string
  /** The authenticator's reported transports, e.g. `['internal']`. */
  readonly transports: readonly string[]
}

/**
 * The login confirm payload the backend `passkey_login_confirm` action expects,
 * all binary fields re-encoded base64url.
 */
export interface PasskeyAssertionResponse {
  /** base64url credential id (the authenticator's raw id). */
  readonly credentialId: string
  /** base64url authenticator data. */
  readonly authenticatorData: string
  /** base64url client data JSON. */
  readonly clientDataJson: string
  /** base64url assertion signature. */
  readonly signature: string
  /**
   * base64url WebAuthn user handle a discoverable (resident-key) assertion
   * carries, or null for a non-discoverable one (HIL-400). It resolves the
   * account server-side when the login named none.
   */
  readonly userHandle: string | null
}

/**
 * Encode bytes as an unpadded base64url string — the wire encoding the backend
 * uses for every binary WebAuthn field.
 *
 * @param buffer The bytes to encode.
 */
export function base64UrlEncode(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode an unpadded base64url string back to bytes — the inverse of
 * {@link base64UrlEncode}, tolerating standard-alphabet and padded input.
 *
 * @param value The base64url string to decode.
 */
export function base64UrlDecode(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=')
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i)
  }

  return bytes.buffer
}

/**
 * Whether the browser exposes the WebAuthn credentials API. The ceremony driver
 * gates on this to surface a clear "not supported" message instead of a raw
 * TypeError from calling `navigator.credentials.create` where it is absent.
 */
export function isPasskeySupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    navigator.credentials !== undefined &&
    typeof PublicKeyCredential !== 'undefined'
  )
}

/**
 * Decode one wire descriptor to the native shape (its base64url id to an
 * ArrayBuffer), preserving the type and transports hint.
 *
 * @param descriptor The wire credential descriptor.
 */
function decodeDescriptor(
  descriptor: PasskeyDescriptor,
): PublicKeyCredentialDescriptor {
  return {
    type: descriptor.type,
    id: base64UrlDecode(descriptor.id),
    transports: descriptor.transports as AuthenticatorTransport[] | undefined,
  }
}

/**
 * Run the register (attestation) ceremony: decode the options' binary fields,
 * call `navigator.credentials.create`, and re-encode the attestation response for
 * the confirm action.
 *
 * @param options The register options in the backend wire shape.
 * @param signal Aborts the ceremony and closes the device dialog (HIL-418).
 * @returns The base64url-encoded attestation response for `passkey_register_confirm`.
 * @throws DOMException When the user cancels or aborts it, the ceremony times out, or the authenticator rejects it.
 * @throws Error When the browser returns no credential.
 */
export async function createPasskey(
  options: PasskeyCreationOptions,
  signal?: AbortSignal,
): Promise<PasskeyRegistrationResponse> {
  const credential = (await navigator.credentials.create({
    signal,
    publicKey: {
      challenge: base64UrlDecode(options.challenge),
      rp: options.rp,
      user: {
        id: base64UrlDecode(options.user.id),
        name: options.user.name,
        displayName: options.user.displayName,
      },
      pubKeyCredParams:
        options.pubKeyCredParams as PublicKeyCredentialParameters[],
      authenticatorSelection: options.authenticatorSelection,
      attestation: options.attestation,
      excludeCredentials: options.excludeCredentials?.map(decodeDescriptor),
      timeout: options.timeout,
    },
  })) as PublicKeyCredential | null
  if (credential === null) {
    throw new Error('The passkey registration returned no credential.')
  }
  const response = credential.response as AuthenticatorAttestationResponse

  return {
    attestationObject: base64UrlEncode(response.attestationObject),
    clientDataJson: base64UrlEncode(response.clientDataJSON),
    transports: response.getTransports?.() ?? [],
  }
}

/**
 * Run the login (assertion) ceremony: decode the options' binary fields, call
 * `navigator.credentials.get`, and re-encode the assertion response for the
 * confirm action.
 *
 * @param options The login options in the backend wire shape.
 * @param signal Aborts the ceremony and closes the OS picker (HIL-418).
 * @returns The base64url-encoded assertion response for `passkey_login_confirm`.
 * @throws DOMException When the user cancels or aborts it, the ceremony times out, or no credential matches.
 * @throws Error When the browser returns no credential.
 */
export async function getPasskey(
  options: PasskeyRequestOptions,
  signal?: AbortSignal,
): Promise<PasskeyAssertionResponse> {
  const credential = (await navigator.credentials.get({
    signal,
    publicKey: {
      challenge: base64UrlDecode(options.challenge),
      rpId: options.rpId,
      allowCredentials: options.allowCredentials?.map(decodeDescriptor),
      userVerification: options.userVerification,
      timeout: options.timeout,
    },
  })) as PublicKeyCredential | null
  if (credential === null) {
    throw new Error('The passkey assertion returned no credential.')
  }
  const response = credential.response as AuthenticatorAssertionResponse

  return {
    credentialId: base64UrlEncode(credential.rawId),
    authenticatorData: base64UrlEncode(response.authenticatorData),
    clientDataJson: base64UrlEncode(response.clientDataJSON),
    signature: base64UrlEncode(response.signature),
    userHandle:
      response.userHandle !== null
        ? base64UrlEncode(response.userHandle)
        : null,
  }
}
