<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * MailForwarder - turns one caught channel message into a letter in the stand's Mailpit.
 *
 * The whole point of the gateway's shape (HIL-653). Mail already had an inbox a person
 * opens in a browser; every other channel had nothing, and a code sent to a phone was
 * readable only by digging through a container's filesystem - or not at all. Rather
 * than building a second viewer, a caught SMS or Telegram message is re-addressed as
 * mail: the channel and the recipient live in the address ({@see recipientAddress()}),
 * so "my code" is told from somebody else's by who it was sent to rather than by
 * position in a list. A channel added later needs a stand endpoint here, not a new way
 * to read what it delivered.
 *
 * The forward happens BEFORE the gateway answers its caller: on a stand "delivered"
 * has to mean "readable", so a relay that refuses becomes a refusal to the daemon
 * rather than a success nobody can check.
 *
 * SMTP is spoken by hand over a plain socket, unauthenticated and unencrypted, exactly
 * as the daemon reaches the same Mailpit (MAIL_SMTP_SECURITY=none). The framework's own
 * transport is a non-blocking state machine tied to the event loop, and this container
 * mounts no framework at all - it is one image with no dependencies, on purpose.
 */
final class MailForwarder
{
    /** Env variable naming the stand's SMTP relay host. */
    private const string ENV_HOST = 'MAILPIT_SMTP_HOST';

    /** Env variable naming the stand's SMTP relay port. */
    private const string ENV_PORT = 'MAILPIT_SMTP_PORT';

    /** Domain every stand address ends in: `<channel>@stand`, `<number>@<channel>.stand`. */
    private const string STAND_DOMAIN = 'stand';

    /** Longest subject that still reads whole in a mailbox list; the body always carries the full text. */
    private const int SUBJECT_MAX_CHARACTERS = 120;

    /** Seconds to wait for the relay's connection and for each of its replies. */
    private const int TIMEOUT_SECONDS = 5;

    /** Line terminator SMTP ends every command, header and body line with. */
    private const string CRLF = "\r\n";

    /**
     * Forwards one caught message as a letter, returning once the relay has taken it.
     *
     * @param string $channel Channel that caught the message (`sms`, `telegram`)
     * @param string $recipient Recipient as the channel was given it, in E.164 with its leading plus
     * @param string $text Message text as the recipient would have read it
     * @throws MailForwardException When the relay is unconfigured, unreachable, or refuses the letter
     */
    public static function forward(string $channel, string $recipient, string $text): void
    {
        self::deliver(
            self::senderAddress($channel),
            self::recipientAddress($channel, $recipient),
            self::letter($channel, $recipient, $text),
        );
    }

    /**
     * The address a channel's mail comes from.
     *
     * @param string $channel Channel that caught the message
     * @return string Sender address
     */
    private static function senderAddress(string $channel): string
    {
        return $channel . '@' . self::STAND_DOMAIN;
    }

    /**
     * The address a caught message is re-addressed to.
     *
     * Channel and number both ride the address, because that is what a mailbox filters
     * and searches on: a spec asks for one number's letters, and a person reading by
     * hand sees at a glance whose code and over which channel.
     *
     * @param string $channel Channel that caught the message
     * @param string $recipient Recipient as the channel was given it
     * @return string Recipient address
     */
    private static function recipientAddress(string $channel, string $recipient): string
    {
        return $recipient . '@' . $channel . '.' . self::STAND_DOMAIN;
    }

    /**
     * Renders the whole letter, headers and body.
     *
     * Date and Message-ID are written rather than left to the relay: Mailpit orders its
     * list by Date, and a letter without one sorts wherever it likes - which on a stand
     * where the newest message is the one being looked for is the same as being lost.
     *
     * @param string $channel Channel that caught the message
     * @param string $recipient Recipient as the channel was given it
     * @param string $text Message text
     * @return string RFC 5322 letter with CRLF line endings, ready for DATA
     */
    private static function letter(string $channel, string $recipient, string $text): string
    {
        $headers = [
            'From: ' . self::senderAddress($channel),
            'To: ' . self::recipientAddress($channel, $recipient),
            'Subject: ' . self::subject($text),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::STAND_DOMAIN . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
        ];

        $body = [
            'Channel: ' . $channel,
            'To: ' . $recipient,
            'Sent: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            '---',
            $text,
        ];

        return self::crlf(implode("\n", $headers) . "\n\n" . self::dotStuffed(implode("\n", $body)));
    }

    /**
     * The subject line: the message itself, on one line and cut to a readable length.
     *
     * The text is the subject because that is what makes the mailbox list answer the
     * question without a click - a verification code is short, and a person scanning
     * for theirs reads it straight off the row.
     *
     * The value is written as UTF-8 rather than as an RFC 2047 encoded word: a caught
     * text is normally ASCII, Mailpit reads the raw header either way, and building
     * (and splitting) encoded words correctly is a chunk of machinery a stand mock has
     * no use for.
     *
     * @param string $text Message text, which may carry line breaks
     * @return string One-line subject of at most {@see SUBJECT_MAX_CHARACTERS} characters
     */
    private static function subject(string $text): string
    {
        $oneLine = (string)preg_replace('~[\r\n]+~', ' ', $text);
        preg_match('~^.{0,' . self::SUBJECT_MAX_CHARACTERS . '}~us', $oneLine, $matches);

        return $matches[0] ?? '';
    }

    /**
     * Doubles a leading dot on every line, as DATA requires.
     *
     * @param string $body Body with newline line endings
     * @return string Body whose lines cannot end the DATA block early
     */
    private static function dotStuffed(string $body): string
    {
        return (string)preg_replace('~^\.~m', '..', $body);
    }

    /**
     * Rewrites every line ending as CRLF, whatever the text arrived with.
     *
     * @param string $letter Letter with mixed line endings
     * @return string The same letter with SMTP line endings
     */
    private static function crlf(string $letter): string
    {
        return (string)preg_replace('~\r\n|\r|\n~', self::CRLF, $letter);
    }

    /**
     * Hands one letter to the relay and waits for it to be taken.
     *
     * @param string $from Envelope sender
     * @param string $to Envelope recipient
     * @param string $letter Rendered letter with CRLF line endings
     * @throws MailForwardException When the relay is unconfigured, unreachable, or refuses the letter
     */
    private static function deliver(string $from, string $to, string $letter): void
    {
        $host = self::requiredEnv(self::ENV_HOST);
        $port = (int)self::requiredEnv(self::ENV_PORT);

        // warning-suppressed: a relay that is not there comes back as the false handle read below
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            throw new MailForwardException(
                "stand relay {$host}:{$port} refused the connection: {$errorMessage} ({$errorCode})",
            );
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            self::expect($socket, 220);
            self::command($socket, 'EHLO stand-gateway', 250);
            self::command($socket, "MAIL FROM:<{$from}>", 250);
            self::command($socket, "RCPT TO:<{$to}>", 250);
            self::command($socket, 'DATA', 354);
            self::command($socket, $letter . self::CRLF . '.', 250);
            self::command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Writes one command and reads the reply it is expected to draw.
     *
     * @param resource $socket Open relay socket
     * @param string $command Command line, without its terminator
     * @param int $expected Reply code the relay owes this command
     * @throws MailForwardException When the write fails or the reply is not the expected one
     */
    private static function command($socket, string $command, int $expected): void
    {
        if (fwrite($socket, $command . self::CRLF) === false) {
            throw new MailForwardException('stand relay closed the connection mid-command');
        }

        self::expect($socket, $expected);
    }

    /**
     * Reads one reply, following its continuation lines, and checks its code.
     *
     * @param resource $socket Open relay socket
     * @param int $expected Reply code the relay owes
     * @throws MailForwardException When the relay says nothing or answers another code
     */
    private static function expect($socket, int $expected): void
    {
        do {
            $line = fgets($socket);
            if ($line === false) {
                throw new MailForwardException("stand relay went silent where {$expected} was expected");
            }
        } while (substr($line, 3, 1) === '-');

        $code = (int)substr($line, 0, 3);
        if ($code !== $expected) {
            throw new MailForwardException(
                "stand relay answered {$code} where {$expected} was expected: " . trim($line),
            );
        }
    }

    /**
     * Reads an env value the forward cannot happen without.
     *
     * @param string $name Env variable name
     * @return string Its non-empty value
     * @throws MailForwardException When the variable is unset or empty
     */
    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new MailForwardException("stand gateway has no {$name}, so it cannot forward what it caught");
        }

        return $value;
    }
}
