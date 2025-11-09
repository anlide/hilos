<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * ErrorConstants - Error handling constants
 *
 * Defines limits and constraints for error handling, logging, and error message formatting.
 * These constants control the maximum length of error messages and stack traces
 * to prevent log overflow and ensure manageable log file sizes.
 */
class ErrorConstants
{
    /** @var int Maximum length of error message in characters */
    public const int ERROR_MESSAGE_MAX_LENGTH = 2000;

    /** @var int Maximum length of stack trace in characters */
    public const int ERROR_STACK_TRACE_MAX_LENGTH = 10000;

    // Error context array keys
    /** @var string Error context key for file path */
    public const string CONTEXT_KEY_FILE = 'file';

    /** @var string Error context key for line number */
    public const string CONTEXT_KEY_LINE = 'line';

    /** @var string Error context key for stack trace */
    public const string CONTEXT_KEY_TRACE = 'trace';
}

