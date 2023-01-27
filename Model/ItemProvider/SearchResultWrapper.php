<?php

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

    private $pageSize;

    /** @var int */
    private $maxChildrenProcess;

    /**
     * @param SearchCriteria $searchCriteria
     * @param $repository
     * @param int $pageSize
     * @param int $maxChildrenProcess
     */
    public function __construct(
        SearchCriteria $searchCriteria,
        $repository,
        int $pageSize,
        int $maxChildrenProcess
    ) {
        $this->searchCriteria = $searchCriteria;
        $this->repository = $repository;
        $this->pageSize = $pageSize;
        $this->maxChildrenProcess = $maxChildrenProcess;
    }

    /**
     * @inheirtDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->searchCriteria->setPageSize($this->getPageSize());
        $moduloPage = $currentPage % $this->maxChildrenProcess;
        $moduloPage = $moduloPage === 0 ? $this->maxChildrenProcess : $moduloPage;
        $this->searchCriteria->setCurrentPage($moduloPage);
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
     * @return SearchResultsInterface
     */
    public function getSearchResults(): SearchResultsInterface
    {
        return $this->repository->getList($this->searchCriteria);
    }
}
