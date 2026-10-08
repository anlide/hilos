<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Environment\Exception\EnvInvalidValueException;

/** One environment key's source and value before the optional-missing fallback. */
final readonly class EnvResolution
{
    /**
     * @param EnvSource $source Chosen source
     * @param mixed $value Source value, null exactly when source is missing
     * @throws EnvInvalidValueException When source and value disagree or a present value is not scalar
     */
    public function __construct(
        public EnvSource $source,
        public mixed $value,
    ) {
        if (($source === EnvSource::MISSING) !== ($value === null)
            || ($value !== null && !is_scalar($value))) {
            throw new EnvInvalidValueException('Environment resolution must have a scalar value, or null only when missing');
        }
    }
}
