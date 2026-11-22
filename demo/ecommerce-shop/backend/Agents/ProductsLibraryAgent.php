<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\CLI\DTO\ProductTestDeleteCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Files\CatalogPicturePublications;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/**
 * Monopolistic library of the shop's products - the one writer of the products and their addresses.
 *
 * Creating and editing a product happen here and nowhere else, and so does every address a
 * product's name gives it: whatever writes a product - a page subscribed to the library
 * included - writes in the library's own process, under its claim. One instance runs per
 * cluster, and the placement policy chooses its node. The catalog grows, so the library alone
 * holds the products and their addresses whole; everybody else reads one by key or queries the
 * database (docs/agents/architecture/entity-libraries.md).
 */
final class ProductsLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The products and their addresses, both owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::products => TruthSourceOperation::BY_KIND,
        EcommerceShopDbContext::productSlugs => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The categories a product may be put in and the registry of published pictures.
     *     Creation checks that the chosen category exists and the picture is a fresh publication.
     */
    public const array READS_DB = [
        EcommerceShopDbContext::categories,
        HilosDbContext::files,
    ];

    public const string AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    public const array AGENT_SIGNALS = [
        EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED => FilesPublishedSignalData::class,
    ];

    public const array AGENT_COMMANDS = [
        EcommerceShopCommandConstants::PRODUCT_TEST_DELETE => ProductTestDeleteCommandData::class,
    ];

    /** Product actions, publication answers and commands arrive in frames. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** Picture uploads waiting for a reply from the files door. */
    private readonly CatalogPicturePublications $publications;

    /** Starts with no product picture publications waiting. */
    public function __construct()
    {
        $this->publications = new CatalogPicturePublications(
            EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED,
            EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH,
        );
    }

    /**
     * Declares the products and their addresses complete in this process.
     *
     * Both load lazily by key everywhere. The holder checks addresses against the whole set
     * and looks up products by exact fixture name, so it reads them in once here. The declaration
     * outlives a db-reset and a restore: a collection that was whole reads itself whole again
     * ({@see Objects::reHydrate()}).
     *
     * @throws LogicException When either collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading either collection from the database fails
     */
    public function onStart(): void
    {
        (Hilos::$db->products->getObjectCollection() ?? throw new LogicException('The products collection is not mounted'))
            ->preloadAll();
        (Hilos::$db->productSlugs->getObjectCollection() ?? throw new LogicException('The product addresses collection is not mounted'))
            ->preloadAll();
    }

    /**
     * Products are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Asks the files door to publish a product picture.
     *
     * @param string $acceptKey Connection the upload belongs to
     * @param string $clientUploadId Id the browser gave the upload
     * @param ?string $requestId Browser request id to acknowledge, or null for an untracked action
     * @throws LogicException When the files door is missing
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is invalid
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     * @throws ValidationException When another request is already publishing this upload
     */
    public function publishPicture(string $acceptKey, string $clientUploadId, ?string $requestId): void
    {
        $this->publications->ask($acceptKey, $clientUploadId, $requestId);
    }

    /**
     * Acknowledges the action waiting on a publication answer.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload
     * @param string $sender Sender in full, unused
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When this agent does not own the signal
     * @throws InvalidAgentSignalPayloadException When the payload differs from the declaration
     * @throws InvalidArgumentException When the acknowledgement cannot be named or the preview variant is unavailable
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED:
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
     * Dispatches the fixture command admitted by the command socket.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws AgentException When the payload is wrong or the named fixture cannot be deleted
     * @throws InvalidArgumentException When the command reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case EcommerceShopCommandConstants::PRODUCT_TEST_DELETE:
                $query = $data->parsedPayload;
                if (!$query instanceof ProductTestDeleteCommandData) {
                    throw new AgentException('Product cleanup needs a fixture name');
                }
                $this->deleteFixture($data, $query);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Deletes one exact fixture match, or acknowledges that the fixture is already absent.
     *
     * @param CommandRequestDTO $request Command to answer
     * @param ProductTestDeleteCommandData $query Validated fixture name
     * @throws AgentException When more than one product has the exact name or fixture cleanup fails
     * @throws InvalidArgumentException When the command reply cannot be queued
     */
    private function deleteFixture(CommandRequestDTO $request, ProductTestDeleteCommandData $query): void
    {
        try {
            $matches = Hilos::$db->products->whereColumnIs(EntityProduct::name, $query->name);
            if ($matches->count() > 1) {
                throw new AgentException('Multiple products have that fixture name');
            }
            $product = $matches->first();
            $product?->actions->deleteFixture();
        } catch (AgentException $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new AgentException('The product fixture could not be deleted', previous: $failure);
        }

        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [EcommerceShopCommandConstants::deleted => $product !== null]));
    }
}
