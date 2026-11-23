<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\Commands;

use Demo\EcommerceShop\Testing\VoucherFixture;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/** Sends a fixture command and prints the daemon's confirmed payload as JSON. */
trait VoucherFixtureCommandChannel
{
    /**
     * @param SignalDataInterface $payload Typed fixture request
     * @return int CLI exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the confirmed reply cannot be encoded
     */
    private function sendVoucherFixture(SignalDataInterface $payload): int
    {
        if (!VoucherFixture::isAllowed()) {
            return $this->printToStandardError(VoucherFixture::ENVIRONMENT_REFUSAL);
        }

        try {
            $result = $this->sendCommand($this->getName(), $payload->toArray());
        } catch (EnvException $failure) {
            $this->writeToStandardError($failure->getMessage());

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        echo json_encode($result->reply->payload, JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
