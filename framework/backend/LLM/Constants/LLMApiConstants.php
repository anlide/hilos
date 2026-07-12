<?php

declare(strict_types=1);

namespace Hilos\LLM\Constants;

/**
 * LLMApiConstants - API payload and response keys for LLM providers.
 *
 * Centralized keys for Ollama, OpenAI and compatible APIs.
 */
class LLMApiConstants
{
    // ── Message / Chat format ───────────────────────────────────────────────

    /** Message role key */
    public const string KEY_ROLE = 'role';

    /** Message content key */
    public const string KEY_CONTENT = 'content';

    // ── Common request keys ──────────────────────────────────────────────────

    /** Model name key */
    public const string KEY_MODEL = 'model';

    /** Prompt key */
    public const string KEY_PROMPT = 'prompt';

    /** Stream key */
    public const string KEY_STREAM = 'stream';

    /** Options key (Ollama) */
    public const string KEY_OPTIONS = 'options';

    /** Temperature key */
    public const string KEY_TEMPERATURE = 'temperature';

    /** Messages key (OpenAI chat) */
    public const string KEY_MESSAGES = 'messages';

    /** Max tokens key */
    public const string KEY_MAX_TOKENS = 'max_tokens';

    /** Ollama num_predict key */
    public const string KEY_NUM_PREDICT = 'num_predict';

    /** Response format key */
    public const string KEY_RESPONSE_FORMAT = 'response_format';

    // ── Ollama response keys ─────────────────────────────────────────────────

    /** Ollama response text key */
    public const string KEY_RESPONSE = 'response';

    // ── OpenAI chat response keys ─────────────────────────────────────────────

    /** Choices array key */
    public const string KEY_CHOICES = 'choices';

    /** Message object key */
    public const string KEY_MESSAGE = 'message';

    /** Timeout seconds key (DTO) */
    public const string KEY_TIMEOUT_SEC = 'timeoutSec';

    /** Max tokens key (DTO camelCase) */
    public const string KEY_MAX_TOKENS_CAMEL = 'maxTokens';

    // ── Values ───────────────────────────────────────────────────────────────

    /** Response format: base64 JSON */
    public const string RESPONSE_FORMAT_B64_JSON = 'b64_json';

    /** Default image size */
    public const string DEFAULT_IMAGE_SIZE = '1024x1024';

    // ── Truncation ───────────────────────────────────────────────────────────

    /** Default truncation limit for log output */
    public const int TRUNCATE_LIMIT_DEFAULT = 1200;

    /** Suffix appended when text is truncated */
    public const string TRUNCATE_SUFFIX = '...<truncated>';

    // ── Log messages ────────────────────────────────────────────────────────

    /** Log: failed to encode request payload */
    public const string LOG_FAILED_ENCODE_PAYLOAD = 'Failed to encode request payload';

    /** Log: local LLM unavailable (append error message) */
    public const string LOG_LOCAL_LLM_UNAVAILABLE = 'Local LLM unavailable: ';

    /** Log: external LLM unavailable (append error message) */
    public const string LOG_EXTERNAL_LLM_UNAVAILABLE = 'External LLM unavailable: ';

    /** Fallback when error message is unknown */
    public const string LOG_UNKNOWN = 'unknown';

    /** Log: model required for Ollama */
    public const string LOG_MODEL_REQUIRED_OLLAMA = 'Model is required for Ollama';

    /** Log: model required for OpenAI */
    public const string LOG_MODEL_REQUIRED_OPENAI = 'Model is required for OpenAI';

    /** Log: model required for image generation */
    public const string LOG_MODEL_REQUIRED_IMAGE = 'Model is required for image generation';

    /** Log: Ollama response invalid format */
    public const string LOG_OLLAMA_RESPONSE_INVALID = 'Ollama response invalid format: ';

    /** Log: OpenAI response invalid format */
    public const string LOG_OPENAI_RESPONSE_INVALID = 'OpenAI response invalid format: ';

    /** Log: OpenAI image response invalid */
    public const string LOG_OPENAI_IMAGE_RESPONSE_INVALID = 'OpenAI image response invalid: ';

    /** Log: OpenAI image response has no url or b64_json */
    public const string LOG_OPENAI_IMAGE_NO_URL_OR_B64 = 'OpenAI image response has no url or b64_json';

    /** Authorization Bearer header prefix */
    public const string AUTH_BEARER_PREFIX = 'Authorization: Bearer ';

    // ── Ollama prompt format ─────────────────────────────────────────────────

    /** Ollama prompt prefix for system role */
    public const string OLLAMA_PREFIX_SYSTEM = 'System: ';

    /** Ollama prompt prefix for user role */
    public const string OLLAMA_PREFIX_USER = 'User: ';

    /** Ollama prompt prefix for assistant role */
    public const string OLLAMA_PREFIX_ASSISTANT = 'Assistant: ';

    /** Ollama prompt separator between messages */
    public const string OLLAMA_SEPARATOR = "\n\n";

    /** Ollama prompt suffix before assistant response */
    public const string OLLAMA_ASSISTANT_SUFFIX = "\n\nAssistant: ";
}
