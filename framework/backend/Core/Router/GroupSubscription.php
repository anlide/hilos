<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/** One local membership incarnation, even when a later join carries equal params. */
final readonly class GroupSubscription
{
    /** @param array<string, mixed> $params Membership route params */
    public function __construct(public array $params)
    {
    }

    /**
     * @param array<string, mixed> $params Params to merge
     * @return self Replacement membership record
     */
    public function withMergedParams(array $params): self
    {
        return new self(array_merge($this->params, $params));
    }
}
