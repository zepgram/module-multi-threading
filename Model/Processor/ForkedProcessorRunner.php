<?php
/**
 * Copyright © Username, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Processor;

use Zepgram\MultiThreading\Model\ItemProvider\ItemProviderInterface;
use Zepgram\MultiThreading\Model\Processor\ForkedProcessorFactory;

class ForkedProcessorRunner
{
    /** @var ForkedProcessorFactory */
    private $forkedProcessorFactory;

    public function __construct(
        ForkedProcessorFactory $forkedProcessorFactory
    ) {
        $this->forkedProcessorFactory = $forkedProcessorFactory;
    }

    /**
     * @param ItemProviderInterface $itemProvider
     * @param callable $callback
     * @param int $maxChildrenProcess
     * @return void
     */
    public function run(
        ItemProviderInterface $itemProvider,
        callable $callback,
        int $maxChildrenProcess
    ): void {
        /** @var $forkedProcessor ForkedProcessor */
        $forkedProcessor = $this->forkedProcessorFactory->create([
            'itemProvider' => $itemProvider,
            'callback' => $callback,
            'maxChildrenProcess' => $maxChildrenProcess,
        ]);

        $forkedProcessor->process();
    }
}
