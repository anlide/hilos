/**
 * Stub data for Hilos Security Center admin section (demo).
 */

export type HilosSecurityTwoFactorStub = {
  enrolledUsers: number
  totalUsers: number
  requiredForAdmins: boolean
  recoveryCodesPolicy: string
  trustedDevicesCount: number
  lastPolicyChangeAt: string
}

export const hilosSecurityTwoFactorStub: HilosSecurityTwoFactorStub = {
  enrolledUsers: 128,
  totalUsers: 512,
  requiredForAdmins: true,
  recoveryCodesPolicy: '10 codes per user, single-use',
  trustedDevicesCount: 340,
  lastPolicyChangeAt: '2025-02-01 09:00:00',
}

export type HilosSecurityOAuthProviderId =
  | 'google'
  | 'github'
  | 'keycloak'
  | 'okta'
  | 'microsoft'

export type HilosSecurityOAuthProviderRow = {
  providerId: HilosSecurityOAuthProviderId
  label: string
  enabled: boolean
  clientIdHint: string
  callbackUrl: string
  scopes: string
  allowLogin: boolean
}

export const hilosSecurityOAuthProvidersStub: HilosSecurityOAuthProviderRow[] = [
  {
    providerId: 'google',
    label: 'Google',
    enabled: true,
    clientIdHint: '1234567890-abc…apps.googleusercontent.com',
    callbackUrl: 'https://app.example.com/auth/callback/google',
    scopes: 'openid email profile',
    allowLogin: true,
  },
  {
    providerId: 'github',
    label: 'GitHub',
    enabled: true,
    clientIdHint: 'Iv1.0a1b2c3d4e5',
    callbackUrl: 'https://app.example.com/auth/callback/github',
    scopes: 'read:user user:email',
    allowLogin: true,
  },
  {
    providerId: 'keycloak',
    label: 'Keycloak',
    enabled: false,
    clientIdHint: 'hilos-web',
    callbackUrl: 'https://app.example.com/auth/callback/keycloak',
    scopes: 'openid profile email',
    allowLogin: false,
  },
  {
    providerId: 'okta',
    label: 'Okta',
    enabled: false,
    clientIdHint: '0oa…',
    callbackUrl: 'https://app.example.com/auth/callback/okta',
    scopes: 'openid profile email',
    allowLogin: false,
  },
  {
    providerId: 'microsoft',
    label: 'Microsoft',
    enabled: true,
    clientIdHint: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    callbackUrl: 'https://app.example.com/auth/callback/microsoft',
    scopes: 'openid profile email',
    allowLogin: true,
  },
]

export type HilosSecurityOverviewStub = {
  securityScore: number
  openIncidents: number
  lastScanAt: string
  ssoEnabledCount: number
  twoFaCoveragePercent: number
}

export const hilosSecurityOverviewStub: HilosSecurityOverviewStub = {
  securityScore: 82,
  openIncidents: 0,
  lastScanAt: '2025-03-22 06:00:00',
  ssoEnabledCount: 3,
  twoFaCoveragePercent: 25,
}
