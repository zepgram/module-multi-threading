<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\ItemProvider;

use Magento\Framework\Data\Collection;

class CollectionWrapper implements ItemProviderInterface
{
    /** @var Collection */
    private $collection;

    public function __construct(Collection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * @inheirtDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->collection->setCurPage($currentPage);
    }

    /**
     * @inheirtDoc
     */
    public function getPageSize(): int
    {
        return (int) $this->collection->getPageSize();
    }

    /**
     * @inheirtDoc
     */
    public function getTotalPages(): int
    {
        return (int) ceil($this->collection->getSize() / $this->getPageSize());
    }

    /**
     * @inheirtDoc
     */
    public function getItems(): array
    {
        return $this->collection->getItems();
    }
}
