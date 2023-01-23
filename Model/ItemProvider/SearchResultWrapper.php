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

    /** @var SearchResultsInterface */
    private $searchResults;

    public function __construct(
        SearchCriteria $searchCriteria,
        $repository
    ) {
        $this->searchCriteria = $searchCriteria;
        $this->repository = $repository;
    }

    /**
     * @inheirtDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->searchResults = null;
        $this->searchCriteria->setCurrentPage($currentPage);
    }

    /**
     * @inheirtDoc
     */
    public function getPageSize(): int
    {
        return (int) $this->searchCriteria->getPageSize();
    }

    /**
     * @inheirtDoc
     */
    public function getTotalPages(): int
    {
        return (int) ceil($this->getSearchResults()->getTotalCount() / $this->getPageSize());
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
        if ($this->searchResults === null) {
            $this->searchResults = $this->repository->getList($this->searchCriteria);
        }

        return $this->searchResults;
    }
}
