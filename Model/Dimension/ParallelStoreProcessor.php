<?php

declare(strict_types=1);

namespace Zepgram\MultiThreading\Model\Dimension;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapper;
use Zepgram\MultiThreading\Model\ItemProvider\ArrayWrapperFactory;
use Zepgram\MultiThreading\Model\Processor\ForkedProcessorRunner;

class ParallelStoreProcessor
{
    /** @var ForkedProcessorRunner */
    private $forkedProcessorRunner;

    /** @var ArrayWrapperFactory */
    private $arrayWrapperFactory;

    /** @var StoreRepositoryInterface */
    private $storeRepository;

    public function __construct(
        ForkedProcessorRunner $forkedProcessorRunner,
        ArrayWrapperFactory $arrayWrapperFactory,
        StoreRepositoryInterface $storeRepository
    ) {
        $this->forkedProcessorRunner = $forkedProcessorRunner;
        $this->arrayWrapperFactory = $arrayWrapperFactory;
        $this->storeRepository = $storeRepository;
    }

    /**
     * @param callable $callback
     * @param int|null $maxChildrenProcess
     * @param bool $onlyActiveStores
     * @param bool $withDefaultStore
     * @return void
     */
    public function process(
        callable $callback,
        int $maxChildrenProcess = null,
        bool $onlyActiveStores = true,
        bool $withDefaultStore = false
    ): void {
        $stores = array_filter($this->storeRepository->getList(),
            function (StoreInterface $store) use ($onlyActiveStores, $withDefaultStore) {
                if (!$withDefaultStore && (int)$store->getId() === 0) {
                    return false;
                }
                if ($onlyActiveStores && !$store->getIsActive()) {
                    return false;
                }
                return true;
            });

        /** @var ArrayWrapper $itemProvider */
        $itemProvider = $this->arrayWrapperFactory->create([
            'items' => $stores,
            'pageSize' => 1
        ]);
        $storeCount = $itemProvider->getSize();
        $maxChildrenProcess = ($maxChildrenProcess >= $storeCount || $maxChildrenProcess === null)
            ? $storeCount : $maxChildrenProcess;

        $this->forkedProcessorRunner->run($itemProvider, $callback, $maxChildrenProcess);
    }
}
