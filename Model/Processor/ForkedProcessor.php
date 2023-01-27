<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

use Psr\Log\LoggerInterface;
use Throwable;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapper;
use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;

class ForkedProcessor
{
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

    /**
     * @param LoggerInterface $logger
     * @param ItemProviderInterface $itemProvider
     * @param callable $callback
     * @param int $maxChildrenProcess
     * @param bool $isParallelize
     */
    public function __construct(
        LoggerInterface $logger,
        ItemProviderInterface $itemProvider,
        callable $callback,
        int $maxChildrenProcess = 10,
        bool $isParallelize = true
    ) {
        $this->logger = $logger;
        $this->itemProvider = $itemProvider;
        $this->callback = $callback;
        $this->maxChildrenProcess = $isParallelize ? $maxChildrenProcess : 1;
        pcntl_signal(SIGINT, [$this, 'handleSigInt']);
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

    private function handleSingleChildProcesses(): void
    {
        $currentPage = 1;
        $childProcessCounter = 0;
        $totalPages = $this->itemProvider->getTotalPages();

        while ($currentPage <= $totalPages) {
            // create fork
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->logger->error('Could not fork the process');
            } elseif ($pid) {
                // parent process
                $childProcessCounter++;
            } else {
                // child process
                $this->processChild($currentPage, $totalPages, $childProcessCounter);
                exit(0);
            }

            $pid = pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) != 0) {
                $this->logger->error('Error with child process', [
                    'pid' => $pid,
                    'exit_code' => $status
                ]);
            }

            $childProcessCounter--;
            $currentPage++;
            if ($currentPage > $totalPages) {
                $this->running = false;
                break;
            }
        }
    }

    private function handleMultipleChildProcesses(): void
    {
        $currentPage = 1;
        $childProcessCounter = 0;
        $totalPages = $this->itemProvider->getTotalPages();

        while ($currentPage <= $totalPages) {
            // manage children
            while ($childProcessCounter >= $this->maxChildrenProcess) {
                $pid = pcntl_wait($status);
                if (pcntl_wexitstatus($status) != 0) {
                    $this->logger->error('Error with child process', [
                        'pid' => $pid,
                        'exit_code' => $status
                    ]);
                }
                $childProcessCounter--;
            }

            // create fork
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->logger->error('Could not fork the process');
            } elseif ($pid) {
                // parent process
                $childProcessCounter++;
            } else {
                // child process
                $this->processChild($currentPage, $totalPages, $childProcessCounter);
                exit(0);
            }

            $currentPage++;
            if ($currentPage > $totalPages) {
                $this->running = false;
                break;
            }
        }

        // wait children process before releasing parent
        while ($childProcessCounter > 0) {
            $pid = pcntl_wait($status);
            $childProcessCounter--;
            if (pcntl_wexitstatus($status) != 0) {
                $this->logger->error('Error with child process', [
                    'pid' => $pid,
                    'exit_code' => $status
                ]);
            }
            $this->logger->info('Finished last child process', [
                'pid' => $pid,
                'child_process_counter' => $childProcessCounter + 1,
                'memory_usage' => $this->getMemoryUsage()
            ]);
        }

        // fallback based on database query
        if (!$this->itemProvider instanceof ArrayWrapper) {
            $size = $this->itemProvider->getSize();
            $this->logger->info('Missing items from original query collection', ['total_items' => $size]);
            if ($size !== 0) {
                $this->handleMultipleChildProcesses();
            }
        }
    }

    private function processChild(int $currentPage, int $totalPages, int $childProcessCounter): void
    {
        $itemProceed = 0;
        try {
            $this->itemProvider->setCurrentPage($currentPage);
            $items = $this->itemProvider->getItems();
        } catch (Throwable $e) {
            $this->logger->error('Error while loading collection', [
                'pid' => getmypid(),
                'current_page' => $currentPage,
                'exception' => $e
            ]);
            exit(1);
        }

        $this->logger->info('Running child process', [
            'pid' => getmypid(),
            'child_process_counter' => $childProcessCounter + 1,
            'memory_usage' => $this->getMemoryUsage(),
            'item_counter' => count($items),
            'current_page' => $currentPage,
            'remaining_pages' => $totalPages - $currentPage,
            'total_pages' => $totalPages
        ]);

        foreach ($items as $item) {
            try {
                call_user_func($this->callback, $item);
                $itemProceed++;
            } catch (Throwable $e) {
                $this->logger->error('Error on callback function will processing item', [
                    'pid' => getmypid(),
                    'current_page' => $currentPage,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('Finished child process', [
            'pid' => getmypid(),
            'child_process_counter' => $childProcessCounter + 1,
            'memory_usage' => $this->getMemoryUsage(),
            'current_page' => $currentPage,
            'remaining_pages' => $totalPages - $currentPage,
            'total_pages' => $totalPages,
            'item_proceed' => $itemProceed
        ]);
    }

    private function handleSigInt(): void
    {
        $this->running = false;
    }

    private function getMemoryUsage(): string
    {
        return round(memory_get_usage(true) / (1024 * 1024), 2) . 'MB';
    }
}
