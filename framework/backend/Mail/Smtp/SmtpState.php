<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

/**
 * SmtpState - the awaiting-reply step of {@see SmtpDialog} (HIL-197).
 *
 * Each case names the reply the dialog is currently waiting for. The nominal flow is
 * GREETING -> EHLO -> [STARTTLS -> EHLO_TLS] -> [AUTH...] -> MAIL_FROM -> RCPT_TO -> DATA
 * -> DATA_BODY -> QUIT -> DONE; the bracketed steps depend on the configured security and
 * credentials.
 */
enum SmtpState: string
{
    /** @var string Awaiting the connection greeting. */
    case GREETING = 'greeting';

    /** @var string Awaiting the response to the first EHLO. */
    case EHLO = 'ehlo';

    /** @var string Awaiting the response to STARTTLS. */
    case STARTTLS = 'starttls';

    /** @var string Awaiting the response to the post-upgrade EHLO. */
    case EHLO_TLS = 'ehlo_tls';

    /** @var string Awaiting the response to AUTH PLAIN. */
    case AUTH_PLAIN = 'auth_plain';

    /** @var string Awaiting the AUTH LOGIN username prompt. */
    case AUTH_LOGIN = 'auth_login';

    /** @var string Awaiting the AUTH LOGIN password prompt. */
    case AUTH_LOGIN_USERNAME = 'auth_login_username';

    /** @var string Awaiting the AUTH LOGIN result. */
    case AUTH_LOGIN_PASSWORD = 'auth_login_password';

    /** @var string Awaiting the response to MAIL FROM. */
    case MAIL_FROM = 'mail_from';

    /** @var string Awaiting the response to RCPT TO. */
    case RCPT_TO = 'rcpt_to';

    /** @var string Awaiting the response to DATA. */
    case DATA = 'data';

    /** @var string Awaiting the response to the message body. */
    case DATA_BODY = 'data_body';

    /** @var string Awaiting the response to QUIT after a delivered message. */
    case QUIT = 'quit';

    /** @var string Conversation finished; no further replies expected. */
    case DONE = 'done';
}
