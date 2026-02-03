<?php
declare(strict_types=1);
require_once __DIR__ . '/../../Model/ItemProvider/ItemProviderInterface.php';
use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;
class MockItemProvider implements ItemProviderInterface {
    private array $pages;
    private int $pageSize;
    private bool $idempotent;
    private int $currentPage = 1;
    public function __construct(array $pages, int $pageSize = 10, bool $idempotent = true) {
        $this->pages = $pages;
        $this->pageSize = $pageSize;
        $this->idempotent = $idempotent;
    }
    public function setCurrentPage(int $page): void { $this->currentPage = $page; }
    public function getItems(): array { return $this->pages[$this->currentPage] ?? []; }
    public function getSize(): int {
        $total = 0;
        foreach ($this->pages as $items) $total += count($items);
        return $total;
    }
    public function getTotalPages(): int { return count($this->pages); }
    public function getPageSize(): int { return $this->pageSize; }
    public function isIdempotent(): bool { return $this->idempotent; }
}
