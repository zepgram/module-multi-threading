<?php
/**
 * Copyright © Username, Inc. All rights reserved.
 */

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Dimension;

use InvalidArgumentException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapper;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapperFactory;
use Zepgram\MultiThreading\Model\Processor\ForkedProcessorRunner;

class ParallelWebsiteProcessor
{
    /** @var ForkedProcessorRunner */
    private $forkedProcessorRunner;

    /** @var ArrayWrapperFactory */
    private $arrayWrapperFactory;

    /** @var WebsiteRepositoryInterface */
    private $websiteRepository;

    public function __construct(
        ForkedProcessorRunner $forkedProcessorRunner,
        ArrayWrapperFactory $arrayWrapperFactory,
        WebsiteRepositoryInterface $websiteRepository
    ) {
        $this->forkedProcessorRunner = $forkedProcessorRunner;
        $this->arrayWrapperFactory = $arrayWrapperFactory;
        $this->websiteRepository = $websiteRepository;
    }

    /**
     * @param callable $callback
     * @param int|null $maxChildrenProcess
     * @param bool $withDefaultWebsite
     * @return void
     */
    public function process(
        callable $callback,
        ?int $maxChildrenProcess = null,
        bool $withDefaultWebsite = false
    ): void {
        if ($maxChildrenProcess !== null && $maxChildrenProcess <= 0) {
            throw new InvalidArgumentException('maxChildrenProcess must be greater than 0');
        }

        $websites = array_filter($this->websiteRepository->getList(),
            function (WebsiteInterface $website) use ($withDefaultWebsite) {
                if (!$withDefaultWebsite && (int)$website->getId() === 0) {
                    return false;
                }
                return true;
            });

        /** @var ArrayWrapper $itemProvider */
        $itemProvider = $this->arrayWrapperFactory->create([
            'items' => $websites,
            'pageSize' => 1
        ]);
        $websiteCount = $itemProvider->getSize();
        if ($websiteCount === 0) {
            return;
        }
        $maxChildrenProcess = $maxChildrenProcess === null
            ? $websiteCount
            : min($maxChildrenProcess, $websiteCount);

        $this->forkedProcessorRunner->run($itemProvider, $callback, $maxChildrenProcess);
    }
}
