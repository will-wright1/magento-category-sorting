<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Plugin;

use Willwright\CategorySorting\Model\Config;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeProvider;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\ExpressionBuilder;
use Magento\Framework\Search\RequestInterface;

class SearchSort
{
    public function __construct(private AttributeProvider $attributes, private ExpressionBuilder $expressions)
    {
    }

    public function aroundGetSort(Sort $subject, callable $proceed, RequestInterface $request): array
    {
        if ($request->getName() !== 'catalog_view_container' || !method_exists($request, 'getSort')) {
            return $proceed($request);
        }
        foreach ($request->getSort() as $sort) {
            if (($sort['field'] ?? '') === Config::SORT_CODE) {
                // A single Recommended choice expands before OpenSearch selects any page of results.
                // Availability always wins, including when an old URL supplies an ascending direction.
                return [
                    [Config::AVAILABILITY_FIELD => ['order' => 'asc', 'missing' => 2, 'unmapped_type' => 'integer']],
                    [Config::SALES_FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'double']],
                    [Config::RATING_FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'integer']],
                    $this->expressions->build($this->attributes->getByAttributeCode('price'), 'desc', $request),
                    [Config::ID_FIELD => ['order' => 'asc', 'unmapped_type' => 'long']],
                ];
            }
        }
        return $proceed($request);
    }
}
