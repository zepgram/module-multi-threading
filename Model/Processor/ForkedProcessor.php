<?php
/**
 * Copyright © Zepgram, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

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

    /** @var array<int, int> Map of PID => page number for active children */
    private $childPids = [];

    /** @var int Current recursion depth for non-idempotent fallback */
    private $recursionDepth = 0;

    /**
     * @param LoggerInterface $logger
     * @param ItemProviderInterface $itemProvider
     * @param callable $callback
     * @param int $maxChildrenProcess
     */
    public function __construct(
        LoggerInterface $logger,
        ItemProviderInterface $itemProvider,
        callable $callback,
        int $maxChildrenProcess = 10
    ) {
        $this->logger = $logger;
        $this->itemProvider = $itemProvider;
        $this->callback = $callback;
        $this->maxChildrenProcess = $maxChildrenProcess;
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

    /**
     * Handle termination signals gracefully
     * Sends SIGTERM to all children before stopping
     */
    public function handleSig(): void
    {
        $this->running = false;
        
        // Terminate all child processes gracefully
        foreach ($this->childPids as $pid => $page) {
            $this->logger->info('Sending SIGTERM to child process', ['pid' => $pid, 'page' => $page]);
            posix_kill($pid, SIGTERM);
        }
        
        // Give children time to exit gracefully (max 5 seconds)
        $timeout = time() + 5;
        while (!empty($this->childPids) && time() < $timeout) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($this->childPids[$pid]);
            }
            usleep(100000); // 100ms
        }
        
        // Force kill any remaining children
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
                // Track failed fork for retry
                $failedPages[] = $currentPage;
                $currentPage++;
                continue;
            }
            
            if ($pid === 0) {
                // Child process
                $exitCode = $this->processChild($currentPage, $totalPages);
                exit($exitCode);
            }
            
            // Parent process - track child
            $this->childPids[$pid] = $currentPage;
            
            // Wait for this child (sequential processing)
            pcntl_waitpid($pid, $status);
            unset($this->childPids[$pid]);
            
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
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
        
        // Retry failed pages (once)
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
        $totalPages = $this->itemProvider->getTotalPages();
        
        if ($totalPages <= 0) {
            $this->logger->info('There is nothing to process');
            $this->running = false;
            return;
        }

        while ($currentPage <= $totalPages && $this->running) {
            // Wait for a slot if at max capacity
            while ($childProcessCounter >= $this->maxChildrenProcess && $this->running) {
                $pid = pcntl_wait($status);
                
                if ($pid <= 0) {
                    $this->logger->error('Error waiting for child process, resetting counter');
                    // Reset counter to actual number of tracked children
                    $childProcessCounter = count($this->childPids);
                    break;
                }
                
                $completedPage = $this->childPids[$pid] ?? null;
                
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $this->logger->error('Error with child process', [
                        'pid' => $pid,
                        'page' => $completedPage,
                        'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
                        'signaled' => pcntl_wifsignaled($status),
                        'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null
                    ]);
                    // Keep in childPids for fallback detection (will be missing from successful set)
                } else {
                    $this->logger->debug('Child process completed successfully', [
                        'pid' => $pid,
                        'page' => $completedPage
                    ]);
                }
                
                unset($this->childPids[$pid]);
                $childProcessCounter--;
            }

            // Check if we should stop
            if (!$this->running) {
                break;
            }

            // Fork new child
            $pid = pcntl_fork();
            
            if ($pid == -1) {
                $this->logger->error('Could not fork the process', ['page' => $currentPage]);
                $currentPage++;
                continue;
            }
            
            if ($pid === 0) {
                // Child process
                $exitCode = $this->processChild($currentPage, $totalPages);
                exit($exitCode);
            }
            
            // Parent process
            $childProcessCounter++;
            $this->childPids[$pid] = $currentPage;

            $currentPage++;
        }

        // Wait for all remaining children
        $completedPages = [];
        while ($childProcessCounter > 0) {
            $pid = pcntl_wait($status);
            
            if ($pid <= 0) {
                $this->logger->error('Error waiting for remaining child process');
                break;
            }
            
            $completedPage = $this->childPids[$pid] ?? null;
            $childProcessCounter--;
            
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $this->logger->error('Error with child process', [
                    'pid' => $pid,
                    'page' => $completedPage,
                    'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
                    'signaled' => pcntl_wifsignaled($status),
                    'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null
                ]);
            } else {
                if ($completedPage !== null) {
                    $completedPages[] = $completedPage;
                }
                $this->logger->info('Finished child process', [
                    'pid' => $pid,
                    'page' => $completedPage,
                    'remaining_children' => $childProcessCounter,
                    'memory_usage' => $this->getMemoryUsage()
                ]);
            }
            
            unset($this->childPids[$pid]);
        }

        // Calculate missing pages (pages that were forked but failed)
        $allPages = range(1, $totalPages);
        $missingPages = array_diff($allPages, $completedPages);
        
        if (!empty($missingPages)) {
            $this->logger->info('Fallback on missing pages', ['missing_pages' => array_values($missingPages)]);
            foreach ($missingPages as $page) {
                $this->processChildInParent($page, $totalPages);
            }
        }

        // Non-idempotent fallback with recursion limit
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

    /**
     * Process items in child context (forked process)
     * Returns exit code: 0 for success, 1 for error
     */
    private function processChild(int $currentPage, int $totalPages): int
    {
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
                'exception' => $e->getMessage()
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

        // Handle legitimately empty pages gracefully
        if ($itemCount === 0) {
            $this->logger->info('Page is empty, nothing to process', [
                'pid' => getmypid(),
                'current_page' => $currentPage
            ]);
            return 0; // Not an error - page was just empty
        }

        foreach ($items as $item) {
            try {
                call_user_func($this->callback, $item);
                $itemProceed++;
            } catch (Throwable $e) {
                $this->logger->error('Error on callback function while processing item', [
                    'pid' => getmypid(),
                    'current_page' => $currentPage,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Only return error if we had items but couldn't process any
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

    /**
     * Process a page directly in the parent context (for fallback)
     * Does NOT call exit() - safe for parent context
     */
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
                'exception' => $e->getMessage()
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
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Fallback processing complete', [
            'page' => $page,
            'items_processed' => $itemProceed,
            'items_total' => $itemCount
        ]);
    }

    private function getMemoryUsage(): string
    {
        return round(memory_get_usage(true) / (1024 * 1024), 2) . 'MB';
    }
}
