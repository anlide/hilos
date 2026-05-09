<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Failure codes exposed through main-page binary upload runtime state.
 */
final class ChatFileUploadConstants
{
    public const string FILE_UPLOAD_FAILURE_CODE_INVALID_PAYLOAD = 'invalid_payload';
    public const string FILE_UPLOAD_FAILURE_CODE_SIZE_LIMIT = 'size_limit';
    public const string FILE_UPLOAD_FAILURE_CODE_TOTAL_LIMIT = 'total_limit';
    public const string FILE_UPLOAD_FAILURE_CODE_DUPLICATE_FILENAME = 'duplicate_filename';
    public const string FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR = 'storage_error';
    public const string FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD = 'no_active_upload';
    public const string FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW = 'size_overflow';
    public const string FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR = 'write_error';
}
