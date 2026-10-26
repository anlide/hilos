<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosAnalyticsPage;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsPersonPageSubscribeParams;

/**
 * ADMIN subscription naming one person in its opening response; their browser sessions arrive as the page's table.
 *
 * The name is read afresh on every subscription, never kept on the page: a rename shows on the next
 * one. A person whose account is gone keeps a page while analytics still names them as the last to
 * sign in somewhere, under their number alone.
 */
abstract class AbstractHilosAnalyticsPersonPage extends AbstractHilosAnalyticsPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS_PERSON;
    public const string NAME = 'name';
    public const string PHOTO = 'photo';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PERSON,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::users, HilosDbContext::userPhotos];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When the account number is absent or empty
     * @throws InvalidPageRouteParamException When the account number is not a positive integer
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        HilosAnalyticsPersonPageSubscribeParams::fromPageRouteParams($params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused for the shared header
     * @param PageRouteParams $params Raw route parameters
     * @return PagePayload Current name and photo with the personal label, or null values for a deleted account with history
     * @throws HilosException When the route is invalid, nobody answers to the number, or a lookup fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $userId = HilosAnalyticsPersonPageSubscribeParams::fromPageRouteParams($params)->userId;
        $user = Hilos::$db->users[$userId] ?? null;
        $name = $user?->name;
        $photo = null;
        if ($name !== null) {
            $label = $name . ' (#' . $userId . ')';
            if (Hilos::hasFeature(HilosFeature::PROFILE_PHOTO)) {
                $userPhoto = Hilos::$db->userPhotos[$userId] ?? null;
                $photo = $userPhoto?->photo;
            }
        } elseif (new AnalyticsSectionReader()->browserSessionCount($userId) > 0) {
            $label = 'Deleted user #' . $userId;
        } else {
            throw new PageResourceNotFoundException('No such analytics person');
        }

        return new PagePayload(data: [
            PageCatalogConstants::WIRE_PAGE_LABEL => $label,
            self::NAME => $name,
            self::PHOTO => $photo,
        ]);
    }

    /** @return array<string, WireField> Personal columns behind the name and the published photo URL */
    protected function dataFields(): array
    {
        return [
            ...parent::dataFields(),
            self::NAME => WireField::column(HilosDbContext::users, ObjectUser::name),
            self::PHOTO => WireField::column(HilosDbContext::userPhotos, ObjectUserPhoto::fileId),
        ];
    }
}
