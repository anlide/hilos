<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp\Fixtures;

use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationDirectory;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Framework operations plus one project operation for step-up unit tests.
 */
final class StepUpTestDirectory extends StepUpOperationDirectory
{
    public const string PROJECT_OPERATION = 'project_operation';

    /**
     * @return array<string, StepUpOperation> Operations in administration order
     * @throws InvalidArgumentException When an operation key is malformed
     */
    protected static function operations(): array
    {
        return [
            ...parent::operations(),
            self::PROJECT_OPERATION => new StepUpOperation(
                self::PROJECT_OPERATION,
                'Project operation',
                'run the project operation',
                false,
            ),
        ];
    }
}
