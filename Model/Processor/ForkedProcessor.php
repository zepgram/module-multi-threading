<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

use Psr\Log\LoggerInterface;
use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;
use Zepgram\MultiThreading\Model\Throwable;

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
        $currentPage = 1;
        $childProcessCounter = 0;
        $totalPages = $this->itemProvider->getTotalPages();

        while ($this->running) {
            while ($childProcessCounter >= $this->maxChildrenProcess && !$this->isParallelize) {
                pcntl_wait($status);
                $childProcessCounter--;
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
                foreach ($this->itemProvider->getItems() as $item) {
                    try {
                        call_user_func($this->callback, $item);
                    } catch (Throwable $e) {
                        $this->logger->error('Error occurred on callback function will processing item', [
                            'exception' => $e,
                        ]);
                    }
                }
                exit();
            }

            $currentPage++;
            if ($currentPage > $totalPages) {
                break;
            }
        }

        while ($childProcessCounter > 0) {
            pcntl_wait($status);
            if (pcntl_wexitstatus($status) != 0) {
                $this->logger->error('Error with child process exit code: ' . $status);
            }
            $childProcessCounter--;
        }
    }

    private function handleSigInt(): void
    {
        $this->running = false;
    }
}
