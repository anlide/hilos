<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\CLI\DTO\CategoryDeleteCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Files\CatalogPicturePublications;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\NonProductionGate;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * Monopolistic library of the shop's categories - the one writer of the categories and their addresses.
 *
 * Creating, editing, moving and deleting a category happen here and nowhere else, and so does every
 * address a category's name gives it: whatever writes a category writes in the library's own process,
 * under its claim. One instance runs per cluster, and the placement policy chooses its node
 * (docs/agents/architecture/entity-libraries.md).
 *
 * The picture of a new category is published as soon as it is uploaded: the request waits in the
 * library's memory until the files door answers, and the answer acknowledges the browser action that
 * asked for it.
 *
 * It also answers the test-only category delete command, by which an e2e removes the category it added.
 */
final class CategoriesLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The categories and their addresses, both owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::categories => TruthSourceOperation::BY_KIND,
        EcommerceShopDbContext::categorySlugs => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The files registry: a new category's picture is checked to be a fresh
     *     publication of the person adding it before the category is written with it
     */
    public const array READS_DB = [
        HilosDbContext::files,
    ];

    public const string AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    public const array AGENT_SIGNALS = [
        EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED => FilesPublishedSignalData::class,
    ];

    public const array AGENT_COMMANDS = [
        EcommerceShopCommandConstants::CATEGORY_TEST_DELETE => CategoryDeleteCommandData::class,
    ];

    /** Category actions, publication answers and commands arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** @var CatalogPicturePublications New category pictures handed to the files door and not answered yet */
    private readonly CatalogPicturePublications $publications;

    /** Starts with no publication waiting. */
    public function __construct()
    {
        $this->publications = new CatalogPicturePublications(
            EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED,
            EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH,
        );
    }

    /**
     * Hands the picture of a new category to the files door and waits for its answer.
     *
     * @param string $acceptKey Accept key of the connection the upload belongs to
     * @param string $clientUploadId Id the browser gave the upload
     * @param ?string $requestId Browser request id to acknowledge, or null when the action is not tracked
     * @throws LogicException When the files door is missing
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is one the wire does not allow
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     */
    public function publishPicture(string $acceptKey, string $clientUploadId, ?string $requestId): void
    {
        $this->publications->ask($acceptKey, $clientUploadId, $requestId);
    }

    /**
     * Turns the answer of the files door into the acknowledgement of the publish action.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload
     * @param string $sender Sender in full, unused
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the acknowledgement cannot be named, or the preview variant is not declared
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED:
                $published = $data->data;
                if (!$published instanceof FilesPublishedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, FilesPublishedSignalData::class, $published);
                }
                $this->publications->answer($this, $published);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Answers the test-only category delete command.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws HilosException When categories cannot be read or deleted, or the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case EcommerceShopCommandConstants::CATEGORY_TEST_DELETE:
                if (!NonProductionGate::admitted()) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Category delete is test-only'));

                    return;
                }
                $this->deleteCategory($data, $data->parsedPayload);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Categories are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Removes the category of the given name together with its picture; a name no category carries is
     * answered too, since cleanup also runs after an add that never arrived.
     *
     * @param CommandRequestDTO $request Command to answer
     * @param CategoryDeleteCommandData $query Name of the category to remove
     * @throws HilosException When categories cannot be read or deleted, or the reply cannot be queued
     */
    private function deleteCategory(CommandRequestDTO $request, CategoryDeleteCommandData $query): void
    {
        $category = Hilos::$db->categories->whereColumnIs(EntityCategory::name, $query->name)->first();
        if ($category !== null) {
            $category->actions->delete();
        }
        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [EcommerceShopCommandConstants::deleted => $category !== null]));
    }
}
