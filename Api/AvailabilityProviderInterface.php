<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Api;

/** Replace this service to integrate another inventory implementation. */
interface AvailabilityProviderInterface
{
    /** @return array<int, int> Product ID => Availability::IN_STOCK, PREORDER or UNAVAILABLE. */
    public function getRanks(array $productIds, int $storeId): array;
}
