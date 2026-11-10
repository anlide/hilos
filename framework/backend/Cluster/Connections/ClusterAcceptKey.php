<?php

declare(strict_types=1);

namespace Hilos\Cluster\Connections;

/** Connection identity carrying the node that minted it. */
final class ClusterAcceptKey
{
    public const string NODE_SEPARATOR = '.';

    public const int RANDOM_LENGTH = 22;

    /**
     * @param string $nodeId Minting node id
     * @param string $random Base64url random suffix
     * @return string Node-addressed connection key
     */
    public static function compose(string $nodeId, string $random): string
    {
        return $nodeId . self::NODE_SEPARATOR . $random;
    }

    /**
     * @param string $acceptKey Connection key to inspect
     * @return ?string Minting node id, or null for another key form
     */
    public static function nodeOf(string $acceptKey): ?string
    {
        $separator = strrpos($acceptKey, self::NODE_SEPARATOR);
        if ($separator === false || $separator === 0) {
            return null;
        }

        if (preg_match('/\A[A-Za-z0-9_-]{' . self::RANDOM_LENGTH . '}\z/', substr($acceptKey, $separator + 1)) !== 1) {
            return null;
        }

        return substr($acceptKey, 0, $separator);
    }
}
