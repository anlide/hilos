<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HttpConstants - HTTP related constants
 *
 * Contains constants for HTTP operations, response keys, and status codes.
 */
class HttpConstants
{
    // AsyncHttpClient response array keys
    public const string RESPONSE_KEY_SUCCESS = 'success';
    public const string RESPONSE_KEY_BODY = 'body';

    // HttpRouter/HttpClient response array keys
    public const string RESPONSE_KEY_STATUS = 'status';
    public const string RESPONSE_KEY_HEADERS = 'headers';

    // HTTP Status Codes
    public const int HTTP_OK = 200;
    public const int HTTP_NOT_FOUND = 404;
    public const int HTTP_INTERNAL_ERROR = 500;

    // HTTP Methods
    public const string METHOD_GET = 'GET';
    public const string METHOD_POST = 'POST';
    public const string METHOD_PUT = 'PUT';
    public const string METHOD_DELETE = 'DELETE';

    // HTTP Headers
    public const string HEADER_CONTENT_TYPE = 'Content-Type';
    public const string HEADER_CONTENT_LENGTH = 'Content-Length';
    public const string HEADER_HOST = 'Host';
    public const string HEADER_CONNECTION = 'Connection';
    public const string HEADER_UPGRADE = 'Upgrade';
    public const string HEADER_COOKIE = 'Cookie';
    public const string HEADER_SEC_WEBSOCKET_KEY = 'Sec-WebSocket-Key';
    public const string HEADER_SEC_WEBSOCKET_ACCEPT = 'Sec-WebSocket-Accept';
    public const string HEADER_SEC_WEBSOCKET_VERSION = 'Sec-WebSocket-Version';

    // HTTP Protocol Constants
    public const string HTTP_DELIMITER = "\r\n\r\n";           // HTTP request/response delimiter
    public const string HTTP_LINE_SEPARATOR = "\r\n";          // HTTP line separator
    public const string HTTP_VERSION = 'HTTP/1.1';             // HTTP version

    // Content Types
    public const string CONTENT_TYPE_JSON = 'application/json';
    public const string CONTENT_TYPE_HTML = 'text/html';
    public const string CONTENT_TYPE_TEXT = 'text/plain';

    // WebSocket Protocol Values
    public const string WEBSOCKET_PROTOCOL = 'websocket';

    /** Stream context wrapper key for HTTP */
    public const string STREAM_CONTEXT_HTTP = 'http';

    /** Stream context option: HTTP method */
    public const string STREAM_OPT_METHOD = 'method';

    /** Stream context option: HTTP headers */
    public const string STREAM_OPT_HEADER = 'header';

    /** Stream context option: Request body */
    public const string STREAM_OPT_CONTENT = 'content';

    /** Stream context option: Timeout in seconds */
    public const string STREAM_OPT_TIMEOUT = 'timeout';

    /** Stream context option: Ignore HTTP errors */
    public const string STREAM_OPT_IGNORE_ERRORS = 'ignore_errors';
}
