<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\LLM;

use Hilos\Constants\LLMConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\DTO\ChatGenerateOptions;
use PHPUnit\Framework\TestCase;

final class ChatGenerateOptionsTest extends TestCase
{
    /**
     * When temperature is omitted, toArray() does not emit the key and fromArray() restores null.
     */
    public function testRoundTripOmitsTemperatureWhenNull(): void
    {
        $options = new ChatGenerateOptions(
            model: 'gpt-4o-mini',
            timeoutSec: 15.0,
            maxTokens: 500,
        );

        $this->assertNull($options->temperature);

        $payload = $options->toArray();
        $this->assertArrayNotHasKey(LLMApiConstants::KEY_TEMPERATURE, $payload);
        $this->assertSame('gpt-4o-mini', $payload[LLMApiConstants::KEY_MODEL]);
        $this->assertSame(15.0, $payload[LLMApiConstants::KEY_TIMEOUT_SEC]);
        $this->assertSame(500, $payload[LLMApiConstants::KEY_MAX_TOKENS_CAMEL]);

        $restored = ChatGenerateOptions::fromArray($payload);
        $this->assertNull($restored->temperature);
        $this->assertSame('gpt-4o-mini', $restored->model);
        $this->assertSame(15.0, $restored->timeoutSec);
        $this->assertSame(500, $restored->maxTokens);
    }

    /**
     * When temperature is explicitly 0.0, toArray() retains the key and fromArray() restores 0.0.
     */
    public function testRoundTripRetainsExplicitZeroTemperature(): void
    {
        $options = new ChatGenerateOptions(
            model: 'qwen2.5:3b',
            temperature: 0.0,
            timeoutSec: 30.0,
        );

        $this->assertSame(0.0, $options->temperature);

        $payload = $options->toArray();
        $this->assertArrayHasKey(LLMApiConstants::KEY_TEMPERATURE, $payload);
        $this->assertSame(0.0, $payload[LLMApiConstants::KEY_TEMPERATURE]);

        $restored = ChatGenerateOptions::fromArray($payload);
        $this->assertSame(0.0, $restored->temperature);
        $this->assertSame('qwen2.5:3b', $restored->model);
        $this->assertSame(30.0, $restored->timeoutSec);
    }

    /**
     * Explicit non-zero temperature is preserved through toArray() and fromArray().
     */
    public function testRoundTripRetainsExplicitNonZeroTemperature(): void
    {
        $options = new ChatGenerateOptions(
            model: 'gpt-4o',
            temperature: 0.7,
            timeoutSec: 20.0,
        );

        $payload = $options->toArray();
        $this->assertSame(0.7, $payload[LLMApiConstants::KEY_TEMPERATURE]);

        $restored = ChatGenerateOptions::fromArray($payload);
        $this->assertSame(0.7, $restored->temperature);
    }

    /**
     * Missing timeoutSec in fromArray() payload throws InvalidFormatException.
     */
    public function testFromArrayRequiresTimeoutSec(): void
    {
        $this->expectException(InvalidFormatException::class);

        ChatGenerateOptions::fromArray([
            LLMApiConstants::KEY_MODEL => 'test-model',
        ]);
    }

    /**
     * Non-numeric temperature throws InvalidFormatException.
     */
    public function testFromArrayRejectsNonNumericTemperature(): void
    {
        $this->expectException(InvalidFormatException::class);

        ChatGenerateOptions::fromArray([
            LLMApiConstants::KEY_TIMEOUT_SEC => 10.0,
            LLMApiConstants::KEY_TEMPERATURE => 'cold',
        ]);
    }

    /**
     * Default constructor values set null temperature and default timeout.
     */
    public function testDefaultConstructorValues(): void
    {
        $options = new ChatGenerateOptions();

        $this->assertNull($options->model);
        $this->assertNull($options->temperature);
        $this->assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $options->timeoutSec);
        $this->assertNull($options->maxTokens);
        $this->assertNull($options->responseFormat);
    }
}
