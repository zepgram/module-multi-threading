<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\ItemProvider;

use Magento\Framework\Data\Collection;

class CollectionWrapper implements ItemProviderInterface
{
    /** @var Collection */
    private $collection;

    /** @var int */
    private $pageSize;

    /** @var int */
    private $maxChildrenProcess;

    /** @var bool */
    private $isIdempotent;

    /**
     * @param Collection $collection
     * @param int $pageSize
     * @param int $maxChildrenProcess
     * @param bool $isIdempotent
     */
    public function __construct(
        Collection $collection,
        int $pageSize,
        int $maxChildrenProcess,
        bool $isIdempotent
    ) {
        $this->collection = $collection;
        $this->pageSize = $pageSize;
        $this->maxChildrenProcess = $maxChildrenProcess;
        $this->isIdempotent = $maxChildrenProcess > 1 ? $isIdempotent : true;
    }

    /**
     * @inheirtDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->collection->setPageSize($this->getPageSize());
        if (!$this->isIdempotent()) {
            $moduloPage = $currentPage % $this->maxChildrenProcess;
            $currentPage = $moduloPage === 0 ? $this->maxChildrenProcess : $moduloPage;
        }
        $this->collection->setCurPage($currentPage);
    }

    /**
     * @inheirtDoc
     */
    public function getSize(): int
    {
        $this->collection->clear();
        $this->collection->setPageSize(null);
        $this->collection->setCurPage(null);

        return $this->collection->getSize();
    }

    /**
     * @inheirtDoc
     */
    public function getPageSize(): int
    {
        return (int)$this->pageSize;
    }

    /**
     * @inheirtDoc
     */
    public function getTotalPages(): int
    {
        return (int)ceil($this->getSize() / $this->getPageSize());
    }

    /**
     * @inheirtDoc
     */
    public function getItems(): array
    {
        $this->collection->load(false, true);

        return $this->collection->getItems();
    }

    /**
     * @inheirtDoc
     */
    public function isIdempotent(): bool
    {
        return $this->isIdempotent;
    }
}
