<?php
/**
 * Copyright © Username, Inc. All rights reserved.
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

    /**
     * @param SearchCriteria $searchCriteria
     * @param $repository
     * @param int $pageSize
     * @param int $maxChildrenProcess
     * @param bool $isIdempotent
     */
    public function __construct(
        SearchCriteria $searchCriteria,
        $repository,
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
     * @inheirtDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->searchCriteria->setPageSize($this->getPageSize());
        if (!$this->isIdempotent()) {
            $moduloPage = $currentPage % $this->maxChildrenProcess;
            $currentPage = $moduloPage === 0 ? $this->maxChildrenProcess : $moduloPage;
        }
        $this->searchCriteria->setCurrentPage($currentPage);
    }

    /**
     * @inheirtDoc
     */
    public function getSize(): int
    {
        $this->searchCriteria->setPageSize(null);
        $this->searchCriteria->setCurrentPage(null);

        return $this->getSearchResults()->getTotalCount();
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
        return $this->getSearchResults()->getItems();
    }

    /**
     * @inheirtDoc
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
