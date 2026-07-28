<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * MailMessageEncoder - renders an {@see EmailMessage} to an RFC 5322 wire message (HIL-197).
 *
 * The one place the on-the-wire form is built, shared by every transport: an SMTP
 * transport streams the result as its DATA payload, a file transport writes it verbatim
 * as a .eml artifact. The output is UTF-8 throughout — non-ASCII header values become
 * RFC 2047 encoded-words, bodies are base64 with CRLF line folding, and an HTML message
 * is a multipart/alternative with the text part as the plain fallback. The sender
 * identity is supplied by the transport (MAIL_FROM_*), not carried on the message.
 *
 * Encoding is deterministic (no Date or Message-ID header): those are added by the
 * transport or the receiving relay, keeping the encoded form stable for testing.
 */
final class MailMessageEncoder
{
    /** Line separator required between header and body lines (RFC 5322). */
    private const string CRLF = "\r\n";

    /** Column at which a base64 body line folds. */
    private const int BASE64_WRAP = 76;

    /**
     * Encodes a recipient message into its RFC 5322 wire form.
     *
     * @param EmailMessage $message The recipient message to encode
     * @param string $fromAddress Sender email address
     * @param ?string $fromName Sender display name, or null for the bare address
     * @return string The encoded message, CRLF-delimited
     */
    public function encode(EmailMessage $message, string $fromAddress, ?string $fromName = null): string
    {
        $headers = [
            'From: ' . $this->formatAddress($fromAddress, $fromName),
            'To: ' . $this->formatAddress($message->to, $message->toName),
        ];
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo;
        }
        $headers[] = 'Subject: ' . $this->encodeHeaderValue($message->subject);
        $headers[] = 'MIME-Version: 1.0';

        if ($message->html === null) {
            return $this->joinTextPart($headers, $message->text);
        }

        return $this->joinAlternativeParts($headers, $message->text, $message->html);
    }

    /**
     * Builds a single-part text/plain message.
     *
     * @param list<string> $headers Address and subject headers built so far
     * @param string $text Plain-text body
     * @return string The encoded text-only message
     */
    private function joinTextPart(array $headers, string $text): string
    {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $this->encodeBody($text);
    }

    /**
     * Builds a multipart/alternative message with a text fallback and an HTML part.
     *
     * @param list<string> $headers Address and subject headers built so far
     * @param string $text Plain-text fallback body
     * @param string $html HTML body
     * @return string The encoded multipart message
     */
    private function joinAlternativeParts(array $headers, string $text, string $html): string
    {
        $boundary = $this->boundary($text, $html);
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [
            implode(self::CRLF, $headers),
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($text),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($html),
            '--' . $boundary . '--',
        ];

        return implode(self::CRLF, $parts);
    }

    /**
     * Formats an address header value, encoding a non-ASCII display name.
     *
     * @param string $address Email address
     * @param ?string $name Display name, or null for the bare address
     * @return string Formatted `Name <address>` or bare address
     */
    private function formatAddress(string $address, ?string $name): string
    {
        if ($name === null || $name === '') {
            return $address;
        }

        return $this->encodeHeaderValue($name) . ' <' . $address . '>';
    }

    /**
     * Encodes a header value as an RFC 2047 encoded-word when it is not printable ASCII.
     *
     * @param string $value Raw header value
     * @return string Original value, or a UTF-8 base64 encoded-word
     */
    private function encodeHeaderValue(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Base64-encodes a body with CRLF line folding.
     *
     * @param string $body Raw body text
     * @return string Folded base64 body
     */
    private function encodeBody(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), self::BASE64_WRAP, self::CRLF), self::CRLF);
    }

    /**
     * Derives a deterministic multipart boundary from the body content.
     *
     * @param string $text Plain-text body
     * @param string $html HTML body
     * @return string Boundary token that cannot appear inside the base64 bodies
     */
    private function boundary(string $text, string $html): string
    {
        return '=_HilosPart_' . substr(hash('sha256', $text . '|' . $html), 0, 24);
    }
}
