<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\ItemProvider;

class ArrayWrapper implements ItemProviderInterface
{
    /** @var array */
    private $items;

    /** @var int */
    private $pageSize;

    /** @var int */
    private $currentPage = 1;

    /**
     * @param array $items
     * @param int $pageSize
     */
    public function __construct(array $items, int $pageSize)
    {
        $this->items = $items;
        $this->pageSize = $pageSize;
    }

    /**
     * @inheritDoc
     */
    public function setCurrentPage(int $currentPage): void
    {
        $this->currentPage = $currentPage;
    }

    /**
     * @inheirtDoc
     */
    public function getSize(): int
    {
        return count($this->items);
    }

    /**
     * @inheritDoc
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @inheritDoc
     */
    public function getTotalPages(): int
    {
        return (int)ceil($this->getSize() / $this->pageSize);
    }

    /**
     * @inheritDoc
     */
    public function getItems(): array
    {
        $offset = ($this->currentPage - 1) * $this->pageSize;

        return array_slice($this->items, $offset, $this->pageSize);
    }

    /**
     * @inheirtDoc
     */
    public function isIdempotent(): bool
    {
        return true;
    }
}
