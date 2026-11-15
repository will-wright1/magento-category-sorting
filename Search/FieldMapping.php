<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Search;

use Willwright\CategorySorting\Model\Config;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProviderInterface;

class FieldMapping implements FieldProviderInterface
{
    public function getFields(array $context = []): array
    {
        return [
            Config::AVAILABILITY_FIELD => ['type' => 'integer'],
            Config::SALES_FIELD => ['type' => 'double'],
            Config::RATING_FIELD => ['type' => 'integer'],
            Config::ID_FIELD => ['type' => 'long'],
        ];
    }
}
