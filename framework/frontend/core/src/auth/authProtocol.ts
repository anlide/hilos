// The wire vocabulary of the sign-in surface: every client→server command an auth
// flow can send, declared once for all three view frameworks (HIL-409). The names
// are byte-equal to their PHP counterparts in `HilosSignalConstants`, and each
// constant names the one it mirrors.
//
// Names only, no behavior and no imports: the modules that drive these commands
// read them from here, and so does a project that sends one itself. Which of them
// a deployment can actually reach is not decided here either — that is the method
// registry the project composes and the handlers its backend mounts.

/** Client→server: look an identifier up while it is typed (PHP `HilosSignalConstants::HILOS_DETECT_IDENTIFIER`). */
export const AUTH_ACTION_DETECT_IDENTIFIER = 'hilos_detect_identifier'

/** Client→server: email+password login (PHP `HilosSignalConstants::HILOS_LOGIN`). */
export const AUTH_ACTION_LOGIN = 'hilos_login'

/** Client→server: email+password registration (PHP `HilosSignalConstants::HILOS_REGISTER`). */
export const AUTH_ACTION_REGISTER = 'hilos_register'

/** Client→server: submit the confirmation code that creates the reserved account (PHP `HilosSignalConstants::HILOS_CONFIRM_REGISTER`). */
export const AUTH_ACTION_CONFIRM_REGISTER = 'hilos_confirm_register'

/** Client→server: re-send a pending registration's confirmation code (PHP `HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM`). */
export const AUTH_ACTION_REQUEST_REGISTER_CONFIRM =
  'hilos_request_register_confirm'

/** Client→server: ask for a password-reset code (PHP `HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET`). */
export const AUTH_ACTION_REQUEST_PASSWORD_RESET = 'hilos_request_password_reset'

/** Client→server: submit a password-reset code, without the new password (PHP `HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET`). */
export const AUTH_ACTION_CONFIRM_PASSWORD_RESET = 'hilos_confirm_password_reset'

/** Client→server: save the new password of an accepted recovery (PHP `HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET`). */
export const AUTH_ACTION_COMPLETE_PASSWORD_RESET =
  'hilos_complete_password_reset'

/** Client→server: send a one-time login code to a phone over a chosen channel (PHP `HilosSignalConstants::HILOS_REQUEST_PHONE_CODE`). */
export const AUTH_ACTION_REQUEST_PHONE_CODE = 'hilos_request_phone_code'

/** Client→server: submit a one-time login code for a phone (PHP `HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE`). */
export const AUTH_ACTION_CONFIRM_PHONE_CODE = 'hilos_confirm_phone_code'

/** Client→server: request an email magic-link sign-in token (PHP `HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK`). */
export const AUTH_ACTION_REQUEST_MAGIC_LINK = 'hilos_request_magic_link'

/** Client→server: submit an email magic-link sign-in token (PHP `HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK`). */
export const AUTH_ACTION_CONFIRM_MAGIC_LINK = 'hilos_confirm_magic_link'

/** Client→server: submit the code that rode in the magic-link letter (PHP `HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE`). */
export const AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE =
  'hilos_confirm_magic_link_code'

/** Client→server: give up the registration this session was waiting on (PHP `HilosSignalConstants::HILOS_ABANDON_REGISTRATION`). */
export const AUTH_ACTION_ABANDON_REGISTRATION = 'hilos_abandon_registration'

/** Client→server: begin an OAuth login by minting the provider authorize URL (PHP `HilosSignalConstants::HILOS_OAUTH_START`). */
export const AUTH_ACTION_OAUTH_START = 'hilos_oauth_start'

/** Client→server: hand back the OAuth provider code+state after the redirect (PHP `HilosSignalConstants::HILOS_OAUTH_CALLBACK`). */
export const AUTH_ACTION_OAUTH_CALLBACK = 'hilos_oauth_callback'

/** Client→server: clear the success announcement an auth flow left on this session (PHP `HilosSignalConstants::HILOS_DISMISS_SESSION_ACK`). */
export const AUTH_ACTION_DISMISS_SESSION_ACK = 'hilos_dismiss_session_ack'

/** Client→server: mint usernameless (discoverable) login-ceremony options (PHP `HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS`). */
export const AUTH_ACTION_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS =
  'hilos_passkey_discoverable_login_options'

/** Client→server: verify a WebAuthn login assertion (PHP `HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM`). */
export const AUTH_ACTION_PASSKEY_LOGIN_CONFIRM = 'hilos_passkey_login_confirm'

/** Client→server: mint register-ceremony options for the signed-in user (PHP `HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS`). */
export const AUTH_ACTION_PASSKEY_REGISTER_OPTIONS =
  'hilos_passkey_register_options'

/** Client→server: verify a WebAuthn register attestation (PHP `HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM`). */
export const AUTH_ACTION_PASSKEY_REGISTER_CONFIRM =
  'hilos_passkey_register_confirm'

/** Client→server: begin linking an OAuth provider to the signed-in account (PHP `HilosSignalConstants::HILOS_LINK_OAUTH_START`). */
export const AUTH_ACTION_LINK_OAUTH_START = 'hilos_link_oauth_start'

/** Client→server: redeem an OAuth link token after re-auth (PHP `HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH`). */
export const AUTH_ACTION_LINK_OAUTH_AFTER_REAUTH =
  'hilos_link_oauth_after_reauth'
