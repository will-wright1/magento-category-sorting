<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\Indexer;

use Willwright\CategorySorting\Model\Refresh;
use Magento\Framework\Indexer\ActionInterface;

class Ranking implements ActionInterface, \Magento\Framework\Mview\ActionInterface
{
    public function __construct(private Refresh $refresh)
    {
    }

    public function executeFull(): void
    {
        // The dependent catalogsearch_fulltext indexer publishes the resulting snapshot.
        $this->refresh->execute(true, false);
    }

    public function executeList(array $ids): void
    {
        $this->executeFull();
    }

    public function executeRow($id): void
    {
        $this->executeFull();
    }

    public function execute($ids): void
    {
        $this->executeFull();
    }
}
