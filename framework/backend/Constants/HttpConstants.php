<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HttpConstants - HTTP related constants.
 *
 * Contains constants for HTTP operations, response keys, and status codes.
 */
class HttpConstants
{
    /** @var string AsyncHttpClient response key for success flag */
    public const string RESPONSE_KEY_SUCCESS = 'success';

    /** @var string AsyncHttpClient response key for body */
    public const string RESPONSE_KEY_BODY = 'body';

    /** @var string HttpRouter/HttpClient response key for status code */
    public const string RESPONSE_KEY_STATUS = 'status';

    /** @var string HttpRouter/HttpClient response key for headers */
    public const string RESPONSE_KEY_HEADERS = 'headers';

    /** @var int HTTP status code for success */
    public const int HTTP_OK = 200;

    /** @var int HTTP status code for not found */
    public const int HTTP_NOT_FOUND = 404;

    /** @var int HTTP status code for internal server error */
    public const int HTTP_INTERNAL_ERROR = 500;

    /** @var string HTTP method GET */
    public const string METHOD_GET = 'GET';

    /** @var string HTTP method POST */
    public const string METHOD_POST = 'POST';

    /** @var string HTTP method PUT */
    public const string METHOD_PUT = 'PUT';

    /** @var string HTTP method DELETE */
    public const string METHOD_DELETE = 'DELETE';

    /** @var string HTTP header Content-Type */
    public const string HEADER_CONTENT_TYPE = 'Content-Type';

    /** @var string HTTP header Content-Length */
    public const string HEADER_CONTENT_LENGTH = 'Content-Length';

    /** @var string HTTP header Transfer-Encoding */
    public const string HEADER_TRANSFER_ENCODING = 'Transfer-Encoding';

    /** @var string HTTP header Host */
    public const string HEADER_HOST = 'Host';

    /** @var string HTTP header Connection */
    public const string HEADER_CONNECTION = 'Connection';

    /** @var string HTTP header Upgrade */
    public const string HEADER_UPGRADE = 'Upgrade';

    /** @var string HTTP header Cookie */
    public const string HEADER_COOKIE = 'Cookie';

    /** @var string HTTP header Sec-WebSocket-Key */
    public const string HEADER_SEC_WEBSOCKET_KEY = 'Sec-WebSocket-Key';

    /** @var string HTTP header Sec-WebSocket-Accept */
    public const string HEADER_SEC_WEBSOCKET_ACCEPT = 'Sec-WebSocket-Accept';

    /** @var string HTTP header Sec-WebSocket-Version */
    public const string HEADER_SEC_WEBSOCKET_VERSION = 'Sec-WebSocket-Version';

    /** @var string HTTP request/response delimiter */
    public const string HTTP_DELIMITER = "\r\n\r\n";

    /** @var string HTTP line separator */
    public const string HTTP_LINE_SEPARATOR = "\r\n";

    /** @var string HTTP version */
    public const string HTTP_VERSION = 'HTTP/1.1';

    /** @var string Content type for JSON */
    public const string CONTENT_TYPE_JSON = 'application/json';

    /** @var string Content type for HTML */
    public const string CONTENT_TYPE_HTML = 'text/html';

    /** @var string Content type for plain text */
    public const string CONTENT_TYPE_TEXT = 'text/plain';

    /** @var string WebSocket protocol value */
    public const string WEBSOCKET_PROTOCOL = 'websocket';

    /** @var string Stream context wrapper key for HTTP */
    public const string STREAM_CONTEXT_HTTP = 'http';

    /** @var string Stream context option key for HTTP method */
    public const string STREAM_OPT_METHOD = 'method';

    /** @var string Stream context option key for HTTP headers */
    public const string STREAM_OPT_HEADER = 'header';

    /** @var string Stream context option key for request body */
    public const string STREAM_OPT_CONTENT = 'content';

    /** @var string Stream context option key for timeout in seconds */
    public const string STREAM_OPT_TIMEOUT = 'timeout';

    /** @var string Stream context option key for ignore_errors flag */
    public const string STREAM_OPT_IGNORE_ERRORS = 'ignore_errors';
}
