<?php
/**
 * Copyright © Zepgram, Inc. All rights reserved.
 */

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

    /** @var int|null Cached size to avoid mutating collection state */
    private $cachedSize = null;

    /**
     * @param Collection $collection
     * @param int $pageSize
     * @param int $maxChildrenProcess
     * @param bool $isIdempotent
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Collection $collection,
        int $pageSize,
        int $maxChildrenProcess,
        bool $isIdempotent
    ) {
        if ($pageSize <= 0) {
            throw new \InvalidArgumentException('pageSize must be greater than 0');
        }
        $this->collection = $collection;
        $this->pageSize = $pageSize;
        $this->maxChildrenProcess = $maxChildrenProcess;
        $this->isIdempotent = $maxChildrenProcess > 1 ? $isIdempotent : true;
    }

    /**
     * @inheritDoc
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
     * Get total size of collection.
     * 
     * For idempotent mode: caches result to avoid mutating collection state.
     * For non-idempotent mode: always queries fresh (items may be removed).
     * 
     * @inheritDoc
     */
    public function getSize(): int
    {
        // For non-idempotent processing, always get fresh count
        // as items are expected to be removed after processing
        if (!$this->isIdempotent()) {
            return $this->getFreshSize();
        }

        // For idempotent processing, cache the size
        if ($this->cachedSize === null) {
            $this->cachedSize = $this->getFreshSize();
        }

        return $this->cachedSize;
    }

    /**
     * Get fresh size from database (resets collection state)
     */
    private function getFreshSize(): int
    {
        $this->collection->clear();
        $this->collection->setPageSize(null);
        $this->collection->setCurPage(null);

        return $this->collection->getSize();
    }

    /**
     * Reset cached size (useful after processing in non-idempotent mode)
     */
    public function resetCache(): void
    {
        $this->cachedSize = null;
    }

    /**
     * @inheritDoc
     */
    public function getPageSize(): int
    {
        return (int)$this->pageSize;
    }

    /**
     * @inheritDoc
     */
    public function getTotalPages(): int
    {
        return (int)ceil($this->getSize() / $this->getPageSize());
    }

    /**
     * @inheritDoc
     */
    public function getItems(): array
    {
        $this->collection->load(false, true);

        return $this->collection->getItems();
    }

    /**
     * @inheritDoc
     */
    public function isIdempotent(): bool
    {
        return $this->isIdempotent;
    }
}
