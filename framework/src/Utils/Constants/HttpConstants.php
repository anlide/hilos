<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * HttpConstants - HTTP related constants
 *
 * Contains constants for HTTP operations, response keys, and status codes.
 */
class HttpConstants
{
    // AsyncHttpClient response array keys
    public const RESPONSE_KEY_SUCCESS = 'success';
    public const RESPONSE_KEY_BODY = 'body';

    // HttpRouter/HttpClient response array keys
    public const RESPONSE_KEY_STATUS = 'status';
    public const RESPONSE_KEY_HEADERS = 'headers';

    // HTTP Status Codes
    public const HTTP_OK = 200;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_INTERNAL_ERROR = 500;

    // HTTP Methods
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';

    // HTTP Headers
    public const HEADER_CONTENT_TYPE = 'Content-Type';
    public const HEADER_CONTENT_LENGTH = 'Content-Length';
    public const HEADER_HOST = 'Host';
    public const HEADER_CONNECTION = 'Connection';
    public const HEADER_UPGRADE = 'Upgrade';
    public const HEADER_COOKIE = 'Cookie';
    public const HEADER_SEC_WEBSOCKET_KEY = 'Sec-WebSocket-Key';
    public const HEADER_SEC_WEBSOCKET_ACCEPT = 'Sec-WebSocket-Accept';
    public const HEADER_SEC_WEBSOCKET_VERSION = 'Sec-WebSocket-Version';

    // HTTP Protocol Constants
    public const HTTP_DELIMITER = "\r\n\r\n";           // HTTP request/response delimiter
    public const HTTP_LINE_SEPARATOR = "\r\n";          // HTTP line separator
    public const HTTP_VERSION = 'HTTP/1.1';             // HTTP version

    // Content Types
    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_HTML = 'text/html';
    public const CONTENT_TYPE_TEXT = 'text/plain';

    // WebSocket Protocol Values
    public const WEBSOCKET_PROTOCOL = 'websocket';
}


