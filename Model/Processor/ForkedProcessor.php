<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

use Psr\Log\LoggerInterface;
use Throwable;
use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;

class ForkedProcessor
{
    /** @var LoggerInterface */
    private $logger;

    /** @var ItemProviderInterface */
    private $itemProvider;

    /** @var callable */
    private $callback;

    /** @var int|null */
    private $maxChildrenProcess;

    /** @var bool */
    private $isParallelize;

    /** @var bool */
    private $running = true;

    public function __construct(
        LoggerInterface $logger,
        ItemProviderInterface $itemProvider,
        callable $callback,
        ?int $maxChildrenProcess = 10,
        bool $isParallelize = true
    ) {
        $this->logger = $logger;
        $this->itemProvider = $itemProvider;
        $this->callback = $callback;
        $this->maxChildrenProcess = $isParallelize ? $maxChildrenProcess : 1;
        $this->isParallelize = $isParallelize;
        pcntl_signal(SIGINT, [$this, 'handleSigInt']);
    }

    public function process(): void
    {
        while ($this->running) {
            if ($this->isParallelize) {
                $this->handleMultipleChildProcesses();
            } else {
                $this->handleSingleChildProcesses();
            }
        }
    }

    private function handleSingleChildProcesses(): void
    {
        $currentPage = 1;
        $totalPages = $this->itemProvider->getTotalPages();
        $childProcessCounter = 0;

        while ($currentPage <= $totalPages) {
            $pid = pcntl_fork();
            $childProcessCounter++;

            if (!$pid) {
                $this->itemProvider->setCurrentPage($currentPage);
                $items = $this->itemProvider->getItems();
                $this->logger->info('Running child process', [
                    'pid' => getmypid(),
                    'child_process_counter' => $childProcessCounter,
                    'memory_usage' => $this->getMemoryUsage(),
                    'items' => count($items),
                    'current_page' => $currentPage,
                    'remaining_pages' => $totalPages - $currentPage,
                    'total_pages' => $totalPages
                ]);
                foreach ($items as $item) {
                    try {
                        call_user_func($this->callback, $item);
                    } catch (Throwable $e) {
                        $this->logger->error('Error occurred on callback function while processing item', [
                            'exception' => $e,
                        ]);
                    }
                }
                exit(0);
            }

            pcntl_waitpid($pid, $status);
            $status = pcntl_wexitstatus($status);
            if ($status !== 0) {
                $this->logger->error('Error with child process exit code: ' . $status);
            }
            $childProcessCounter--;
            $this->logger->info('Finished child process', [
                'pid' => $pid,
                'child_process_counter' => $childProcessCounter,
                'memory_usage' => $this->getMemoryUsage()
            ]);

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
            while ($childProcessCounter >= $this->maxChildrenProcess) {
                $pid = pcntl_wait($status);
                $childProcessCounter--;
                $this->logger->info('Finished child process', [
                    'pid' => $pid,
                    'child_process_counter' => $childProcessCounter,
                    'memory_usage' => $this->getMemoryUsage()
                ]);
            }

            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->logger->error('Could not fork the process');
            } elseif ($pid) {
                // parent process
                $childProcessCounter++;
            } else {
                // child process
                $this->itemProvider->setCurrentPage($currentPage);
                $items = $this->itemProvider->getItems();
                $this->logger->info('Running child process', [
                    'pid' => getmypid(),
                    'child_process_counter' => $childProcessCounter,
                    'memory_usage' => $this->getMemoryUsage(),
                    'items' => count($items),
                    'current_page' => $currentPage,
                    'remaining_pages' => $totalPages - $currentPage,
                    'total_pages' => $totalPages
                ]);
                foreach ($items as $item) {
                    try {
                        call_user_func($this->callback, $item);
                    } catch (Throwable $e) {
                        $this->logger->error('Error occurred on callback function will processing item', [
                            'exception' => $e,
                        ]);
                    }
                }
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
            if (pcntl_wexitstatus($status) != 0) {
                $this->logger->error('Error with child process exit code: ' . $status);
            }
            $childProcessCounter--;
            $this->logger->info('Finished child process', [
                'pid' => $pid,
                'child_process_counter' => $childProcessCounter,
                'memory_usage' => $this->getMemoryUsage()
            ]);
        }
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
