<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model;

use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapper;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapperFactory;
use Zepgram\MultiThreading\Model\Processor\ForkedProcessorRunner;

class ForkedArrayProcessor
{
    /** @var ForkedProcessorRunner */
    private $forkedProcessorRunner;

    /** @var ArrayWrapperFactory */
    private $arrayWrapperFactory;

    /**
     * @param ForkedProcessorRunner $forkedProcessorRunner
     * @param ArrayWrapperFactory $arrayWrapperFactory
     */
    public function __construct(
        ForkedProcessorRunner $forkedProcessorRunner,
        ArrayWrapperFactory $arrayWrapperFactory
    ) {
        $this->forkedProcessorRunner = $forkedProcessorRunner;
        $this->arrayWrapperFactory = $arrayWrapperFactory;
    }

    /**
     * @param array $array
     * @param callable $callback
     * @param int $pageSize
     * @param int $maxChildrenProcess
     * @param bool $isParallelize
     * @return void
     */
    public function process(
        array $array,
        callable $callback,
        int $pageSize = 1000,
        int $maxChildrenProcess = 10,
        bool $isParallelize = true
    ): void {
        /** @var ArrayWrapper $itemProvider */
        $itemProvider = $this->arrayWrapperFactory->create([
            'items' => $array,
            'pageSize' => $pageSize
        ]);

        $this->forkedProcessorRunner->run($itemProvider, $callback, $maxChildrenProcess, $isParallelize, false);
    }
}
