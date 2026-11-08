<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use EventBufferEvent;
use EventSslContext;
use Hilos\Constants\EnvConstants;
use Hilos\Core\EventLoop\NativeEventRequirements;
use Hilos\Core\EventLoop\Exception\NativeEventUnavailableException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Certificate material is loaded once at server startup, before accepting connections. */
final readonly class TlsContext
{
    /**
     * @param EventSslContext $native Prepared certificate and trust store
     * @param int $state Native handshake role
     * @param bool $verifiesPeer Whether successful handshakes authenticate a peer
     * @param int $readBufferSize Maximum native decrypted input bytes
     */
    private function __construct(
        public EventSslContext $native,
        public int $state,
        public bool $verifiesPeer,
        public int $readBufferSize,
    ) {
    }

    /**
     * @param string $certificateFile Certificate chain and private key PEM
     * @param ?string $trustFile Client authorities, null for a public port
     * @return self Prepared server context
     * @throws EnvException When the socket input budget is invalid
     * @throws NativeEventUnavailableException When the required native TLS API is unavailable
     */
    public static function accepting(string $certificateFile, ?string $trustFile = null): self
    {
        return self::create($certificateFile, $trustFile, true);
    }

    /**
     * @param string $certificateFile Certificate chain and private key PEM
     * @param string $trustFile Server authorities
     * @return self Prepared mutually authenticated client context
     * @throws EnvException When the socket input budget is invalid
     * @throws NativeEventUnavailableException When the required native TLS API is unavailable
     */
    public static function dialing(string $certificateFile, string $trustFile): self
    {
        return self::create($certificateFile, $trustFile, false);
    }

    /**
     * @param string $certificateFile Certificate chain and key PEM
     * @param ?string $trustFile Authorities to verify against
     * @param bool $accepting Whether to prepare the server handshake role
     * @return self Context kept alive until its connections have closed
     * @throws EnvException When the socket input budget is invalid
     * @throws NativeEventUnavailableException When the required native TLS API is unavailable
     */
    private static function create(string $certificateFile, ?string $trustFile, bool $accepting): self
    {
        NativeEventRequirements::assertAvailable();
        $state = $accepting ? EventBufferEvent::SSL_ACCEPTING : EventBufferEvent::SSL_CONNECTING;
        return new self(new EventSslContext(
            $state === EventBufferEvent::SSL_ACCEPTING ? EventSslContext::TLS_SERVER_METHOD : EventSslContext::TLS_CLIENT_METHOD,
            [
                EventSslContext::OPT_LOCAL_CERT => $certificateFile,
                EventSslContext::OPT_LOCAL_PK => $certificateFile,
                EventSslContext::OPT_VERIFY_PEER => $trustFile !== null,
                EventSslContext::OPT_REQUIRE_CLIENT_CERT => $trustFile !== null && $state === EventBufferEvent::SSL_ACCEPTING,
                EventSslContext::OPT_ALLOW_SELF_SIGNED => false,
            ] + ($trustFile === null ? [] : [EventSslContext::OPT_CA_FILE => $trustFile]),
        ), $state, $trustFile !== null, Hilos::$env[EnvConstants::SOCKET_READ_BUFFER_SIZE]->int());
    }
}
