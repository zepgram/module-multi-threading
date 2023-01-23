<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\ItemProvider;

interface ItemProviderInterface
{
    /**
     * @param int $currentPage
     * @return void
     */
    public function setCurrentPage(int $currentPage): void;

    /**
     * @return int
     */
    public function getPageSize(): int;

    /**
     * @return int
     */
    public function getTotalPages(): int;

    /**
     * @return array
     */
    public function getItems(): array;
}
