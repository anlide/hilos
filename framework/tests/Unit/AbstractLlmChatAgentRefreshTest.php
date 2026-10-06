<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\LogicException;
use Hilos\Hilos;
use Hilos\LLM\Agent\AbstractLlmChatAgent;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use PHPUnit\Framework\TestCase;

/**
 * The client used by a live agent changes only between requests.
 */
final class AbstractLlmChatAgentRefreshTest extends TestCase
{
    private ?LlmRouter $previousRouter = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$llm;
    }

    protected function tearDown(): void
    {
        Hilos::$llm = $this->previousRouter;
    }

    public function testEveryProfileFieldParticipatesInRefreshAndEqualValuesReuseTheClient(): void
    {
        $initial = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', null, 10.0);
        $router = new MutableRefreshRouter($initial);
        Hilos::$llm = $router;
        $agent = new RefreshProbeAgent();
        $client = $agent->currentClient();

        $router->profile = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', null, 10.0);
        $agent->refresh();
        self::assertSame($client, $agent->currentClient());

        $profiles = [
            new LlmProfile('changed-key', LlmProvider::LOCAL, 'http://local', 'first', null, 10.0),
            new LlmProfile('test', LlmProvider::EXTERNAL, 'http://local', 'first', null, 10.0),
            new LlmProfile('test', LlmProvider::LOCAL, 'http://other', 'first', null, 10.0),
            new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'second', null, 10.0),
            new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', 'secret', 10.0),
            new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', null, 20.0),
            new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', null, 10.0, 'elsewhere'),
        ];
        foreach ($profiles as $profile) {
            $router->profile = $initial;
            $agent->refresh();
            $before = $agent->currentClient();
            $router->profile = $profile;
            $agent->refresh();
            self::assertNotSame($before, $agent->currentClient());
            self::assertSame($profile, $agent->currentProfile());
        }

        $router->profile = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', '01', null, 10.0);
        $agent->refresh();
        $before = $agent->currentClient();
        $router->profile = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', '1', null, 10.0);
        $agent->refresh();
        self::assertNotSame($before, $agent->currentClient());
    }

    public function testFailedCandidateAndBusyClientKeepTheActivePair(): void
    {
        $initial = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'first', null, 10.0);
        $router = new MutableRefreshRouter($initial);
        Hilos::$llm = $router;
        $agent = new RefreshProbeAgent();
        $client = $agent->currentClient();
        $router->profile = new LlmProfile('test', LlmProvider::LOCAL, 'http://local', 'second', null, 10.0);

        $client->busy = true;
        try {
            $agent->refresh();
            self::fail('A busy client must refuse refresh');
        } catch (LogicException) {
            self::assertSame($client, $agent->currentClient());
        }
        $client->busy = false;

        $agent->failNextBuild = true;
        try {
            $agent->refresh();
            self::fail('A failed candidate must refuse refresh');
        } catch (LLMConfigurationException) {
            self::assertSame($initial, $agent->currentProfile());
            self::assertSame($client, $agent->currentClient());
        }

        $agent->refresh();
        self::assertSame('second', $agent->currentProfile()->model);
        self::assertNotSame($client, $agent->currentClient());
    }
}

final class MutableRefreshRouter extends LlmRouter
{
    public function __construct(public LlmProfile $profile)
    {
    }

    public function resolve(string $profileKey): LlmProfile
    {
        return $this->profile;
    }
}

final class RefreshProbeAgent extends AbstractLlmChatAgent
{
    public bool $failNextBuild = false;

    public function onStop(): void
    {
    }

    public function refresh(): void
    {
        $this->refreshChatClientForNextRequest();
    }

    public function currentProfile(): LlmProfile
    {
        return $this->profile;
    }

    public function currentClient(): RefreshProbeClient
    {
        return $this->chatClient;
    }

    protected function profileKey(): string
    {
        return 'test';
    }

    protected function hasPendingWork(): bool
    {
        return false;
    }

    protected function startRequest(): void
    {
    }

    protected function handleResult(string $text): void
    {
    }

    protected function createChatClient(): AsyncChatLLMInterface
    {
        if ($this->failNextBuild) {
            $this->failNextBuild = false;
            throw new LLMConfigurationException('Candidate refused');
        }

        return new RefreshProbeClient();
    }
}

final class RefreshProbeClient implements AsyncChatLLMInterface
{
    public bool $busy = false;

    /** @param list<Message> $messages Messages to generate from */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
    }

    public function hasResult(): bool
    {
        return false;
    }

    public function consumeResult(): string
    {
        return '';
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->busy = false;
    }
}
