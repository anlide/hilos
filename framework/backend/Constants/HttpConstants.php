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
    /** @var string HTTP response key for body */
    public const string RESPONSE_KEY_BODY = 'body';

    /** @var string HttpRouter/HttpClient response key for status code */
    public const string RESPONSE_KEY_STATUS = 'status';

    /** @var string HttpRouter/HttpClient response key for headers */
    public const string RESPONSE_KEY_HEADERS = 'headers';

    /** @var string Parsed HTTP request key for method */
    public const string REQUEST_KEY_METHOD = 'method';

    /** @var string Parsed HTTP request key for path */
    public const string REQUEST_KEY_PATH = 'path';

    /** @var string Parsed HTTP request key for HTTP version */
    public const string REQUEST_KEY_VERSION = 'version';

    /** @var string Parsed HTTP request key for headers */
    public const string REQUEST_KEY_HEADERS = 'headers';

    /** @var string Parsed HTTP request key for body */
    public const string REQUEST_KEY_BODY = 'body';

    /** @var string Parsed HTTP request key for raw query string */
    public const string REQUEST_KEY_QUERY = 'query';

    /** @var string Parsed HTTP request key for typed query params */
    public const string REQUEST_KEY_QUERY_PARAMS = 'queryParams';

    /** @var int HTTP status code for success */
    public const int HTTP_OK = 200;

    /** @var int HTTP status code for switching protocols (WebSocket handshake) */
    public const int HTTP_STATUS_SWITCHING_PROTOCOLS = 101;

    /** @var string HTTP reason phrase for switching protocols */
    public const string HTTP_REASON_SWITCHING_PROTOCOLS = 'Switching Protocols';

    /** @var int HTTP status code for bad request */
    public const int HTTP_BAD_REQUEST = 400;

    /** @var int HTTP status code for unauthorized */
    public const int HTTP_UNAUTHORIZED = 401;

    /** @var int HTTP status code for forbidden */
    public const int HTTP_FORBIDDEN = 403;

    /** @var int HTTP status code for not found */
    public const int HTTP_NOT_FOUND = 404;

    /** @var int HTTP status code for conflict */
    public const int HTTP_CONFLICT = 409;

    /** @var int HTTP status code for gone */
    public const int HTTP_GONE = 410;

    /** @var int HTTP status code for internal server error */
    public const int HTTP_INTERNAL_ERROR = 500;

    /** @var string Fallback HTTP status reason phrase for unknown codes */
    public const string HTTP_STATUS_TEXT_UNKNOWN = 'Unknown';

    /** @var array<int, string> HTTP status code to reason phrase map */
    public const array HTTP_STATUS_TEXTS = [
        self::HTTP_OK => 'OK',
        self::HTTP_STATUS_SWITCHING_PROTOCOLS => self::HTTP_REASON_SWITCHING_PROTOCOLS,
        self::HTTP_UNAUTHORIZED => 'Unauthorized',
        self::HTTP_FORBIDDEN => 'Forbidden',
        self::HTTP_NOT_FOUND => 'Not Found',
        self::HTTP_INTERNAL_ERROR => 'Internal Server Error',
    ];

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

    /** @var string HTTP header Content-Disposition */
    public const string HEADER_CONTENT_DISPOSITION = 'Content-Disposition';

    /** @var string HTTP header Content-Length */
    public const string HEADER_CONTENT_LENGTH = 'Content-Length';

    /** @var string HTTP header Transfer-Encoding */
    public const string HEADER_TRANSFER_ENCODING = 'Transfer-Encoding';

    /** @var string HTTP header Host */
    public const string HEADER_HOST = 'Host';

    /** @var string HTTP header Connection */
    public const string HEADER_CONNECTION = 'Connection';

    /** @var string Connection header value for persistent connections */
    public const string CONNECTION_VALUE_KEEP_ALIVE = 'keep-alive';

    /** @var string Connection header value for closing after response */
    public const string CONNECTION_VALUE_CLOSE = 'close';

    /** @var string HTTP header Upgrade */
    public const string HEADER_UPGRADE = 'Upgrade';

    /** @var string HTTP header Cookie */
    public const string HEADER_COOKIE = 'Cookie';

    /** @var string HTTP header User-Agent */
    public const string HEADER_USER_AGENT = 'User-Agent';

    /** @var string HTTP header Accept-Language */
    public const string HEADER_ACCEPT_LANGUAGE = 'Accept-Language';

    /** @var string HTTP header Vary */
    public const string HEADER_VARY = 'Vary';

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

    /** @var string HTTP version number fragment used in version checks */
    public const string HTTP_VERSION_NUMBER = '1.1';

    /** @var string Root URL path */
    public const string PATH_ROOT = '/';

    /** @var string Query string separator in request paths */
    public const string QUERY_STRING_SEPARATOR = '?';

    /** @var string Separator between query string key-value pairs */
    public const string QUERY_PAIR_SEPARATOR = '&';

    /** @var string Separator between a query param key and its value */
    public const string QUERY_KEY_VALUE_SEPARATOR = '=';

    /** @var string Content type for JSON */
    public const string CONTENT_TYPE_JSON = 'application/json';

    /** @var string Content type for HTML */
    public const string CONTENT_TYPE_HTML = 'text/html';

    /** @var string Content type for plain text */
    public const string CONTENT_TYPE_TEXT = 'text/plain';

    /** @var string Content type for arbitrary binary data */
    public const string CONTENT_TYPE_OCTET_STREAM = 'application/octet-stream';

    /** @var string Content-Disposition type for inline rendering */
    public const string CONTENT_DISPOSITION_INLINE = 'inline';

    /** @var string Content-Disposition type for file downloads */
    public const string CONTENT_DISPOSITION_ATTACHMENT = 'attachment';

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
