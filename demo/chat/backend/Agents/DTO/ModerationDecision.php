<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Utils\Helpers\JsonHelper;
use JsonException;
use stdClass;

/**
 * Parsed moderation model decision and public refusal phrasing.
 */
final readonly class ModerationDecision
{
    public const string KEY_ALLOW = 'allow';
    public const string KEY_REASON = 'reason';

    /** Default reason label when the model omits one for an allowed message. */
    public const string REASON_ALLOWED = 'ok';

    /** Default reason label when the model omits one for a blocked message. */
    public const string REASON_BLOCKED = 'blocked';

    /** Moderator reason when a picture copy cannot be drawn. The model is not offered this label. */
    public const string REASON_UNREADABLE = 'unreadable';

    public const string SUBJECT_NAME = 'name';
    public const string SUBJECT_MESSAGE = 'message';

    public const string TEXT_NAME_UNAVAILABLE = 'Names cannot be checked right now.';
    public const string TEXT_MESSAGE_UNAVAILABLE = 'Moderation is unavailable right now. Your message is still here.';
    public const string TEXT_NAME_GENERIC = 'This name was not accepted.';
    public const string TEXT_MESSAGE_GENERIC = 'Message rejected.';

    public const string PREFIX_NAME_BECAUSE = 'This name was not accepted because ';
    public const string PREFIX_NAME_COLON = 'This name was not accepted: ';
    public const string PREFIX_MESSAGE_COLON = 'Message rejected: ';

    public const string TAIL_INSULT = 'it appears to contain an insult.';
    public const string TAIL_THREAT = 'it appears to contain a threat.';
    public const string TAIL_HATE_SPEECH = 'it appears to contain hate speech.';
    public const string TAIL_SEXUAL = 'it appears to contain sexual content.';
    public const string TAIL_SPAM = 'it appears to be spam.';
    public const string TAIL_IMPERSONATION = 'it appears to impersonate someone else.';

    public const string TAIL_PICTURE_NUDITY = ' looks like nudity or sexual content.';
    public const string TAIL_PICTURE_VIOLENCE = ' looks violent.';
    public const string TAIL_PICTURE_HATE = ' looks like hate imagery.';
    public const string TAIL_PICTURE_UNREADABLE = ' cannot be checked. Send pictures as JPEG, PNG, WebP or GIF.';
    public const string TAIL_PICTURE_GENERIC = ' was not accepted.';

    /** File name kept in a picture refusal, counting the ellipsis when the name is shortened. */
    public const int PICTURE_NAME_MAX_LENGTH = 60;

    private const int MAX_NATURAL_PHRASE_LENGTH = 160;

    /**
     * @var array<string, string>
     */
    private const array NAME_CATEGORY_TAILS = [
        'insult' => self::TAIL_INSULT,
        'threat' => self::TAIL_THREAT,
        'hate_speech' => self::TAIL_HATE_SPEECH,
        'sexual' => self::TAIL_SEXUAL,
        'spam' => self::TAIL_SPAM,
        'impersonation' => self::TAIL_IMPERSONATION,
    ];

    /**
     * @var array<string, string>
     */
    private const array MESSAGE_CATEGORY_TAILS = [
        'insult' => self::TAIL_INSULT,
        'threat' => self::TAIL_THREAT,
        'hate_speech' => self::TAIL_HATE_SPEECH,
        'sexual' => self::TAIL_SEXUAL,
        'spam' => self::TAIL_SPAM,
    ];

    /**
     * @var array<string, string>
     */
    private const array PICTURE_CATEGORY_TAILS = [
        'nudity' => self::TAIL_PICTURE_NUDITY,
        'violence' => self::TAIL_PICTURE_VIOLENCE,
        'hate' => self::TAIL_PICTURE_HATE,
        self::REASON_UNREADABLE => self::TAIL_PICTURE_UNREADABLE,
    ];

    public function __construct(
        public bool $allow,
        public string $reason,
    ) {
    }

    /**
     * Parses a moderation model output object; the boolean allow decision is
     * authoritative and a missing or blank reason is defaulted to a label.
     *
     * @param string $text Raw model output
     * @throws InvalidArgumentException When output does not contain a valid moderation decision
     */
    public static function fromModelOutput(string $text): self
    {
        $json = JsonHelper::extractJsonObject($text);
        if ($json === null) {
            throw new InvalidArgumentException('Moderation response did not contain a JSON object');
        }

        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Moderation response JSON is invalid', previous: $e);
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Moderation response JSON must be an object');
        }

        if (!property_exists($decoded, self::KEY_ALLOW)) {
            throw new InvalidArgumentException('Moderation response is missing allow decision');
        }

        $allow = $decoded->{self::KEY_ALLOW};
        if (!is_bool($allow)) {
            throw new InvalidArgumentException('Moderation response allow decision must be boolean');
        }

        // The allow decision is authoritative. The reason is only a label and
        // small models often omit it for clear-cut cases, so default it rather
        // than rejecting the whole decision (which would block benign messages).
        $reason = property_exists($decoded, self::KEY_REASON) && is_string($decoded->{self::KEY_REASON})
            ? trim($decoded->{self::KEY_REASON})
            : null;
        if ($reason === null || $reason === '') {
            $reason = $allow ? self::REASON_ALLOWED : self::REASON_BLOCKED;
        }

        return new self(
            allow: $allow,
            reason: $reason,
        );
    }

    /**
     * Translates a raw moderation reason and phase into a public name refusal sentence.
     *
     * @param ?string $rawReason Raw model reason or error code
     * @param ?string $phase Runtime moderation phase if known
     * @return string Human-readable refusal sentence
     */
    public static function publicNameReason(?string $rawReason = null, ?string $phase = null): string
    {
        return self::publicReason(self::SUBJECT_NAME, $rawReason, $phase);
    }

    /**
     * Translates a raw moderation reason and phase into a public message refusal sentence.
     *
     * @param ?string $rawReason Raw model reason or error code
     * @param ?string $phase Runtime moderation phase if known
     * @return string Human-readable refusal sentence
     */
    public static function publicMessageReason(?string $rawReason = null, ?string $phase = null): string
    {
        return self::publicReason(self::SUBJECT_MESSAGE, $rawReason, $phase);
    }

    /**
     * Translates a raw moderation reason and phase into a human-readable sentence for the given subject.
     *
     * @param string $subject Moderation subject ('name' or 'message')
     * @param ?string $rawReason Raw model reason or error code
     * @param ?string $phase Runtime moderation phase if known
     * @return string Human-readable sentence
     */
    public static function publicReason(string $subject, ?string $rawReason = null, ?string $phase = null): string
    {
        $trimmed = $rawReason !== null ? trim($rawReason) : null;

        if (
            $phase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
            || $phase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
            || ($trimmed !== null && in_array($trimmed, ['service_unavailable', 'unknown'], true))
        ) {
            return $subject === self::SUBJECT_NAME
                ? self::TEXT_NAME_UNAVAILABLE
                : self::TEXT_MESSAGE_UNAVAILABLE;
        }

        if ($trimmed === null || $trimmed === '' || $trimmed === self::REASON_BLOCKED) {
            return $subject === self::SUBJECT_NAME
                ? self::TEXT_NAME_GENERIC
                : self::TEXT_MESSAGE_GENERIC;
        }

        if ($subject === self::SUBJECT_NAME) {
            if (isset(self::NAME_CATEGORY_TAILS[$trimmed])) {
                return self::PREFIX_NAME_BECAUSE . self::NAME_CATEGORY_TAILS[$trimmed];
            }

            if (self::isNaturalLanguagePhrase($trimmed)) {
                return self::PREFIX_NAME_COLON . $trimmed;
            }

            return self::TEXT_NAME_GENERIC;
        }

        if (isset(self::MESSAGE_CATEGORY_TAILS[$trimmed])) {
            return self::PREFIX_MESSAGE_COLON . self::MESSAGE_CATEGORY_TAILS[$trimmed];
        }

        if (self::isNaturalLanguagePhrase($trimmed)) {
            return self::PREFIX_MESSAGE_COLON . $trimmed;
        }

        return self::TEXT_MESSAGE_GENERIC;
    }

    /**
     * Names one attachment for a picture refusal: its place among every file, and its name.
     *
     * @param int $ordinal 1-based place of the picture among every attachment
     * @param ?string $filename Upload file name, or null when the upload is already gone
     * @return string Label such as `attachment 2 (name)`
     */
    public static function pictureLabel(int $ordinal, ?string $filename): string
    {
        if ($filename === null) {
            return 'attachment ' . $ordinal;
        }

        $shown = mb_strlen($filename) > self::PICTURE_NAME_MAX_LENGTH
            ? mb_substr($filename, 0, self::PICTURE_NAME_MAX_LENGTH - 1) . '…'
            : $filename;

        return 'attachment ' . $ordinal . ' (' . $shown . ')';
    }

    /**
     * Translates a raw picture refusal into a sentence that names the attachment.
     *
     * @param ?string $rawReason Raw model reason or error code
     * @param ?string $phase Runtime moderation phase if known
     * @param int $ordinal 1-based place of the picture among every attachment
     * @param ?string $filename Upload file name, or null when the upload is already gone
     * @return string Human-readable refusal sentence
     */
    public static function publicPictureReason(
        ?string $rawReason,
        ?string $phase,
        int $ordinal,
        ?string $filename,
    ): string {
        $trimmed = $rawReason !== null ? trim($rawReason) : null;

        if (
            $phase === ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE
            || $phase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
            || ($trimmed !== null && in_array($trimmed, ['service_unavailable', 'unknown'], true))
        ) {
            return self::TEXT_MESSAGE_UNAVAILABLE;
        }

        $label = self::pictureLabel($ordinal, $filename);
        if ($trimmed !== null && isset(self::PICTURE_CATEGORY_TAILS[$trimmed])) {
            return self::PREFIX_MESSAGE_COLON . $label . self::PICTURE_CATEGORY_TAILS[$trimmed];
        }

        if ($trimmed !== null && self::isNaturalLanguagePhrase($trimmed)) {
            return self::PREFIX_MESSAGE_COLON . $label . ' — ' . $trimmed;
        }

        return self::PREFIX_MESSAGE_COLON . $label . self::TAIL_PICTURE_GENERIC;
    }

    /**
     * Whether a refusal is about what the person sent, rather than a check that could not run.
     *
     * @param string $reason Raw moderation reason
     * @return bool False for an unreadable picture and for an unavailable moderator
     */
    public static function isAboutTheContent(string $reason): bool
    {
        return !in_array($reason, [self::REASON_UNREADABLE, 'service_unavailable', 'unknown'], true);
    }

    /**
     * Checks whether the reason looks like a coherent single-line natural language phrase.
     *
     * Rules from spec:
     * - Single line (no newlines)
     * - No control characters
     * - Up to 160 characters
     * - Not a code-like label (must contain whitespace, no underscores)
     *
     * @param string $reason Trimmed reason string
     * @return bool True when string is a natural language sentence/phrase
     */
    public static function isNaturalLanguagePhrase(string $reason): bool
    {
        if ($reason === '' || mb_strlen($reason) > self::MAX_NATURAL_PHRASE_LENGTH) {
            return false;
        }

        if (str_contains($reason, "\n") || str_contains($reason, "\r")) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            return false;
        }

        // Must not contain underscore (code identifier/snake_case)
        if (str_contains($reason, '_')) {
            return false;
        }

        // Must have at least two words (separated by space) to not be a single-word code label
        if (!str_contains($reason, ' ')) {
            return false;
        }

        // Must start with an alphanumeric character
        if (!preg_match('/^[a-zA-Z0-9]/', $reason)) {
            return false;
        }

        // Only natural text characters (letters, digits, common punctuation and spaces)
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9 ,.\'"?!;:-]*$/', $reason) === 1;
    }
}
