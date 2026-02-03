<?php
/**
 * Copyright © Zepgram, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\ItemProvider;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchResultsInterface;

class SearchResultWrapper implements ItemProviderInterface
{
    /** @var SearchCriteria */
    private $searchCriteria;

    /** @var object */
    private $repository;

    /** @var int */
    private $pageSize;

    /** @var int */
    private $maxChildrenProcess;

    /** @var bool */
    private $isIdempotent;

    /** @var int|null Cached size to avoid mutating searchCriteria state */
    private $cachedSize = null;

    /** @var int|null Store current page to restore after getSize */
    private $currentPage = null;

    /**
     * @param SearchCriteria $searchCriteria
     * @param object $repository Repository with getList() method
     * @param int $pageSize
     * @param int $maxChildrenProcess
     * @param bool $isIdempotent
     */
    public function __construct(
        SearchCriteria $searchCriteria,
        object $repository,
        int $pageSize,
        int $maxChildrenProcess,
        bool $isIdempotent
    ) {
        $this->searchCriteria = $searchCriteria;
        $this->repository = $repository;
        $this->pageSize = $pageSize;
        $this->maxChildrenProcess = $maxChildrenProcess;
        $this->isIdempotent = $maxChildrenProcess > 1 ? $isIdempotent : true;
    }

    /**
     * @inheritDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->searchCriteria->setPageSize($this->getPageSize());
        if (!$this->isIdempotent()) {
            $moduloPage = $currentPage % $this->maxChildrenProcess;
            $currentPage = $moduloPage === 0 ? $this->maxChildrenProcess : $moduloPage;
        }
        $this->currentPage = $currentPage;
        $this->searchCriteria->setCurrentPage($currentPage);
    }

    /**
     * Get total count of items.
     * 
     * For idempotent mode: caches result to avoid repeated queries.
     * For non-idempotent mode: always queries fresh (items may be removed).
     * 
     * @inheritDoc
     */
    public function getSize(): int
    {
        // For non-idempotent processing, always get fresh count
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
     * Get fresh size from repository
     * Preserves current pagination state after query
     */
    private function getFreshSize(): int
    {
        // Store current state
        $savedPageSize = $this->searchCriteria->getPageSize();
        $savedCurrentPage = $this->searchCriteria->getCurrentPage();

        // Query for total count (some repositories need null pagination)
        $this->searchCriteria->setPageSize(null);
        $this->searchCriteria->setCurrentPage(null);

        $totalCount = $this->getSearchResults()->getTotalCount();

        // Restore state
        $this->searchCriteria->setPageSize($savedPageSize);
        $this->searchCriteria->setCurrentPage($savedCurrentPage);

        return $totalCount;
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
        return $this->getSearchResults()->getItems();
    }

    /**
     * @inheritDoc
     */
    public function isIdempotent(): bool
    {
        return $this->isIdempotent;
    }

    /**
     * @return SearchResultsInterface
     */
    public function getSearchResults(): SearchResultsInterface
    {
        return $this->repository->getList($this->searchCriteria);
    }
}
