<?php
/**
 * Copyright © Username, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Throwable;
use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;

class ForkedProcessor
{
    private const MAX_RECURSION_DEPTH = 10;

    /** @var LoggerInterface */
    private $logger;

    /** @var ItemProviderInterface */
    private $itemProvider;

    /** @var callable */
    private $callback;

    /** @var int */
    private $maxChildrenProcess;

    /** @var bool */
    private $running = true;

    /** @var array<int, int> */
    private $childPids = [];

    /** @var int */
    private $recursionDepth = 0;

    /** @var ResourceConnection */
    private $resourceConnection;

    /** @var bool */
    private bool $reconnectDatabaseInChild;
    public function __construct(
        LoggerInterface $logger,
        ItemProviderInterface $itemProvider,
        callable $callback,
        int $maxChildrenProcess = 10,
        bool $reconnectDatabaseInChild = false,
        ?ResourceConnection $resourceConnection = null
    ) {
        $this->logger = $logger;
        $this->itemProvider = $itemProvider;
        $this->callback = $callback;
        $this->maxChildrenProcess = $maxChildrenProcess;
        $this->resourceConnection = $resourceConnection;
        $this->reconnectDatabaseInChild = $reconnectDatabaseInChild;
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, [$this, 'handleSig']);
        pcntl_signal(SIGTERM, [$this, 'handleSig']);
    }

    public function process(): void
    {
        while ($this->running) {
            if ($this->maxChildrenProcess > 1) {
                $this->handleMultipleChildProcesses();
            } else {
                $this->handleSingleChildProcesses();
            }
        }
    }

    public function handleSig(): void
    {
        $this->running = false;

        foreach ($this->childPids as $pid => $page) {
            $this->logger->info('Sending SIGTERM to child process', ['pid' => $pid, 'page' => $page]);
            posix_kill($pid, SIGTERM);
        }

        $timeout = time() + 5;
        while (!empty($this->childPids) && time() < $timeout) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($this->childPids[$pid]);
            }
            usleep(100000);
        }

        foreach ($this->childPids as $pid => $page) {
            $this->logger->warning('Force killing child process', ['pid' => $pid, 'page' => $page]);
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($this->childPids[$pid]);
        }
    }

    private function handleSingleChildProcesses(): void
    {
        $currentPage = 1;
        $failedPages = [];
        $totalPages = $this->itemProvider->getTotalPages();

        if ($totalPages <= 0) {
            $this->logger->info('There is nothing to process');
            $this->running = false;
            return;
        }

        while ($currentPage <= $totalPages && $this->running) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->logger->error('Could not fork the process', ['page' => $currentPage]);
                $failedPages[] = $currentPage;
                $currentPage++;
                continue;
            }

            if ($pid === 0) {
                $exitCode = $this->processChild($currentPage, $totalPages);
                $this->terminateChild($exitCode);
            }

            $this->childPids[$pid] = $currentPage;

            pcntl_waitpid($pid, $status);
            unset($this->childPids[$pid]);

            if (!$this->isSuccessfulChildStatus($status)) {
                $this->logger->error('Error with child process', [
                    'pid' => $pid,
                    'page' => $currentPage,
                    'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
                    'signaled' => pcntl_wifsignaled($status),
                    'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null
                ]);
                $failedPages[] = $currentPage;
            }

            $currentPage++;
        }

        if (!empty($failedPages) && $this->running) {
            $this->logger->info('Retrying failed pages in single-process mode', ['pages' => $failedPages]);
            foreach ($failedPages as $page) {
                $this->processChildInParent($page, $totalPages);
            }
        }

        $this->running = false;
    }

    private function handleMultipleChildProcesses(): void
    {
        $currentPage = 1;
        $childProcessCounter = 0;
        $this->childPids = [];
        $failedPages = [];
        $totalPages = $this->itemProvider->getTotalPages();

        if ($totalPages <= 0) {
            $this->logger->info('There is nothing to process');
            $this->running = false;
            return;
        }

        while ($currentPage <= $totalPages && $this->running) {
            while ($childProcessCounter >= $this->maxChildrenProcess && $this->running) {
                $pid = pcntl_wait($status);

                if ($pid <= 0) {
                    $this->logger->error('Error waiting for child process, resetting counter');
                    $childProcessCounter = count($this->childPids);
                    break;
                }

                $completedPage = $this->childPids[$pid] ?? null;

                if (!$this->isSuccessfulChildStatus($status)) {
                    $this->logger->error('Error with child process', [
                        'pid' => $pid,
                        'page' => $completedPage,
                        'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
                        'signaled' => pcntl_wifsignaled($status),
                        'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null
                    ]);
                    if ($completedPage !== null) {
                        $failedPages[] = $completedPage;
                    }
                } else {
                    $this->logger->debug('Child process completed successfully', [
                        'pid' => $pid,
                        'page' => $completedPage
                    ]);
                }

                unset($this->childPids[$pid]);
                $childProcessCounter--;
            }

            if (!$this->running) {
                break;
            }

            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->logger->error('Could not fork the process', ['page' => $currentPage]);
                $failedPages[] = $currentPage;
                $currentPage++;
                continue;
            }

            if ($pid === 0) {
                $exitCode = $this->processChild($currentPage, $totalPages);
                $this->terminateChild($exitCode);
            }

            $childProcessCounter++;
            $this->childPids[$pid] = $currentPage;

            $currentPage++;
        }

        while ($childProcessCounter > 0) {
            $pid = pcntl_wait($status);

            if ($pid <= 0) {
                $this->logger->error('Error waiting for remaining child process');
                break;
            }

            $completedPage = $this->childPids[$pid] ?? null;
            $childProcessCounter--;

            if (!$this->isSuccessfulChildStatus($status)) {
                $this->logger->error('Error with child process', [
                    'pid' => $pid,
                    'page' => $completedPage,
                    'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
                    'signaled' => pcntl_wifsignaled($status),
                    'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null
                ]);
                if ($completedPage !== null) {
                    $failedPages[] = $completedPage;
                }
            } else {
                $this->logger->info('Finished child process', [
                    'pid' => $pid,
                    'page' => $completedPage,
                    'remaining_children' => $childProcessCounter,
                    'memory_usage' => $this->getMemoryUsage()
                ]);
            }

            unset($this->childPids[$pid]);
        }

        $failedPages = array_values(array_unique($failedPages));
        if (!empty($failedPages)) {
            $this->logger->info('Fallback on failed pages', ['failed_pages' => $failedPages]);
            foreach ($failedPages as $page) {
                $this->processChildInParent($page, $totalPages);
            }
        }

        if (!$this->itemProvider->isIdempotent()) {
            $this->recursionDepth++;

            if ($this->recursionDepth > self::MAX_RECURSION_DEPTH) {
                $this->logger->error('Maximum recursion depth reached for non-idempotent processing', [
                    'depth' => $this->recursionDepth,
                    'max_depth' => self::MAX_RECURSION_DEPTH
                ]);
                $this->running = false;
                return;
            }

            $size = $this->itemProvider->getSize();
            $this->logger->info('Checking for remaining items in non-idempotent mode', [
                'total_items' => $size,
                'recursion_depth' => $this->recursionDepth
            ]);

            if ($size > 0) {
                $this->handleMultipleChildProcesses();
                return;
            }
        }

        $this->running = false;
    }

    private function processChild(int $currentPage, int $totalPages): int
    {
        if ($this->reconnectDatabaseInChild) {
            $this->reconnectDatabase();
        }

        $itemProceed = 0;
        $itemCount = 0;

        try {
            $this->itemProvider->setCurrentPage($currentPage);
            $items = $this->itemProvider->getItems();
            $itemCount = count($items);
        } catch (Throwable $e) {
            $this->logger->error('Error while loading collection', [
                'pid' => getmypid(),
                'current_page' => $currentPage,
                'exception' => $e
            ]);
            return 1;
        }

        $this->logger->info('Running child process', [
            'pid' => getmypid(),
            'memory_usage' => $this->getMemoryUsage(),
            'item_counter' => $itemCount,
            'current_page' => $currentPage,
            'remaining_pages' => $totalPages - $currentPage,
            'total_pages' => $totalPages
        ]);

        if ($itemCount === 0) {
            $this->logger->info('Page is empty, nothing to process', [
                'pid' => getmypid(),
                'current_page' => $currentPage
            ]);
            return 0;
        }

        foreach ($items as $item) {
            try {
                call_user_func($this->callback, $item);
                $itemProceed++;
            } catch (Throwable $e) {
                $this->logger->error('Error on callback function while processing item', [
                    'pid' => getmypid(),
                    'current_page' => $currentPage,
                    'exception' => $e,
                ]);
            }
        }

        if ($itemCount > 0 && $itemProceed === 0) {
            $this->logger->error('Failed to process any items on page', [
                'pid' => getmypid(),
                'current_page' => $currentPage,
                'item_count' => $itemCount
            ]);
            return 1;
        }

        $this->logger->info('Finished processing page', [
            'pid' => getmypid(),
            'memory_usage' => $this->getMemoryUsage(),
            'current_page' => $currentPage,
            'remaining_pages' => $totalPages - $currentPage,
            'total_pages' => $totalPages,
            'items_processed' => $itemProceed,
            'items_total' => $itemCount
        ]);

        return 0;
    }

    private function processChildInParent(int $page, int $totalPages): void
    {
        $itemProceed = 0;

        try {
            $this->itemProvider->setCurrentPage($page);
            $items = $this->itemProvider->getItems();
            $itemCount = count($items);
        } catch (Throwable $e) {
            $this->logger->error('Error while loading collection in fallback', [
                'page' => $page,
                'exception' => $e
            ]);
            return;
        }

        if ($itemCount === 0) {
            $this->logger->info('Fallback page is empty, skipping', ['page' => $page]);
            return;
        }

        $this->logger->info('Processing page in fallback mode', [
            'page' => $page,
            'item_count' => $itemCount
        ]);

        foreach ($items as $item) {
            try {
                call_user_func($this->callback, $item);
                $itemProceed++;
            } catch (Throwable $e) {
                $this->logger->error('Error on callback function in fallback', [
                    'page' => $page,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('Fallback processing complete', [
            'page' => $page,
            'items_processed' => $itemProceed,
            'items_total' => $itemCount
        ]);
    }

    /**
     * Close and reconnect database connection in child process
     *
     * After pcntl_fork(), parent and child share the same MySQL connection handle.
     * This causes "MySQL server has gone away" errors and connection state corruption.
     * Closing the connection forces the child to establish a fresh connection.
     */
    private function reconnectDatabase(): void
    {
        if ($this->resourceConnection !== null) {
            try {
                $this->resourceConnection->closeConnection(null);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to close database connection in child process', [
                    'pid' => getmypid(),
                    'exception' => $e
                ]);
            }
        }
    }

    private function isSuccessfulChildStatus(int $status): bool
    {
        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            return true;
        }

        return !$this->reconnectDatabaseInChild
            && pcntl_wifsignaled($status)
            && pcntl_wtermsig($status) === SIGKILL;
    }

    private function terminateChild(int $exitCode): void
    {
        if (!$this->reconnectDatabaseInChild) {
            posix_kill(getmypid(), $exitCode === 0 ? SIGKILL : SIGABRT);
        }

        exit($exitCode);
    }

    private function getMemoryUsage(): string
    {
        return round(memory_get_usage(true) / (1024 * 1024), 2) . 'MB';
    }
}
