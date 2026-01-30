# Multi-Threading for Magento 2

This module is a powerful tool for developers who want to process large data sets in
a short amount of time. It allows you to process large collections of data in parallel
using multiple child processes, improving performance and reducing processing time.

## Installation
```php
composer require zepgram/module-multi-threading
bin/magento module:enable Zepgram_MultiThreading
bin/magento setup:upgrade
```

## Usage

These classes allows you to process a search criteria, a collection or an array using multi-threading.

### ForkedSearchResultProcessor

```php
use Zepgram\MultiThreading\Model\ForkedSearchResultProcessor;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class MyAwesomeClass
{
    /** @var ForkedSearchResultProcessor */
    private $forkedSearchResultProcessor;
    
    /** @var ProductRepositoryInterface */
    private $productRepository;
    
    public function __construct(
        ForkedSearchResultProcessor $forkedSearchResultProcessor,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder 
    ) {
        $this->forkedSearchResultProcessor = $forkedSearchResultProcessor;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }
    
    $searchCriteria = $this->searchCriteriaBuilder->create();
    $productRepository = $this->productRepository;
    $callback = function ($item) {
        $item->getData();
        // do your business logic here
    };
    
    $this->forkedSearchResultProcessor->process(
        $searchCriteria,
        $productRepository,
        $callback,
        $pageSize = 1000,
        $maxChildrenProcess = 10,
        $isIdempotent = true
    );
}
```

### ForkedCollectionProcessor
```php
use Zepgram\MultiThreading\Model\ForkedCollectionProcessor;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class MyAwesomeClass
{
    /** @var ForkedCollectionProcessor */
    private $forkedCollectionProcessor;

    public function __construct(
        ForkedCollectionProcessor $forkedCollectionProcessor,
        CollectionFactory $collectionFactory
    ) {
        $this->forkedCollectionProcessor = $forkedCollectionProcessor;
        $this->collectionFactory = $collectionFactory;
    }

    $collection = $this->collectionFactory->create();
    $callback = function ($item) {
        $item->getData();
        // do your business logic here
    };

    $this->forkedCollectionProcessor->process(
        $collection,
        $callback,
        $pageSize = 1000,
        $maxChildrenProcess = 10,
        $isIdempotent = true
    );
}
```

### ForkedArrayProcessor
This class allows you to process an array of data using multi-threading.

```php
use Zepgram\MultiThreading\Model\ForkedArrayProcessor;

class MyAwesomeClass
{
    /** @var ForkedArrayProcessor */
    private $forkedArrayProcessor;
    
    public function __construct(ForkedArrayProcessor $forkedArrayProcessor)
    {
        $this->forkedArrayProcessor = $forkedArrayProcessor;
    }
    
    $array = [1,2,3,4,5,...];
    $callback = function ($item) {
        echo $item;
        // do your business logic here
    };
    
    $this->forkedArrayProcessor->process(
        $array,
        $callback,
        $pageSize = 2,
        $maxChildrenProcess = 2
    );
}
```

### ParallelStoreProcessor or ParallelWebsiteProcessor

```php
use Zepgram\MultiThreading\Model\Dimension\ParallelStoreProcessor;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class MyAwesomeClass
{
    /** @var ParallelStoreProcessor */
    private $parallelStoreProcessor;
    
    /** @var CollectionFactory */
    private $collectionFactory;
    
    public function __construct(
        ParallelStoreProcessor $parallelStoreProcessor,
        CollectionFactory $collectionFactory
    ) {
        $this->parallelStoreProcessor = $parallelStoreProcessor;
        $this->collectionFactory = $collectionFactory;
    }
    
    $array = [1,2,3,4,5,...];
    $callback = function (StoreInterface $store) {
        // retrieve data from database foreach stores (do not load the collection !)
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('type_id', 'simple')
            ->addFieldToSelect(['sku', 'description', 'created_at'])
            ->setStoreId($store->getId())
            ->addStoreFilter($store->getId())
            ->distinct(true);
            
        // handle pagination system to avoid memory leak
        $currentPage = 1;
        $pageSize = 1000;
        $collection->setPageSize($pageSize);
        $totalPages = $collection->getLastPageNumber();
        while ($currentPage <= $totalPages) {
            $collection->clear();
            $collection->setCurPage($currentPage);
            foreach ($collection->getItems() as $product) {
                // do your business logic here
            }
            $currentPage++;
        }
    };
    
    // your collection will be processed foreach store by a dedicated child process
    $this->parallelStoreProcessor->process(
        $callback,
        $maxChildrenProcess = null,
        $onlyActiveStores = true,
        $withDefaultStore = false
    );
}
```

### bin/magento thread:processor command

This command allows running a command indefinitely in a dedicated thread using 
the Process Symfony Component.
```php
bin/magento thread:processor <command_name> [--timeout=<timeout>] [--iterations=<iterations>] [--environment=<environment>] [--progress]
```

#### Options

- `timeout`: Define the process timeout in seconds (default: 300)
- `iterations`: Define the number of iteration (default: 0)
- `environment`: Set environment variables separate by comma
- `progress`: Show progress bar while executing command

### How it works
The `thread:processor` command creates a dedicated child process to execute existing command line.
The child process runs the command specified by the user, while the parent process
monitors the child process and can act accordingly. You can define iterations and execute the same command
multiple times with a dedicated child foreach execution.

The `ForkedSearchResultProcessor`,`ForkedCollectionProcessor` and `ForkedArrayProcessor` classes
use a similar approach to process a search criteria or a collection. The process is divided
into several pages, and for each page, a child process is created to run the callback
function specified by the user on each item of that page.

The `ParallelStoreProcessor` and `ParallelWebsiteProcessor` classes are designed to make it easier 
to process a list of stores or websites in parallel. To use either of these classes, you'll need to
provide a callback function that will be called for each store or website in the list. The callback 
function should take one parameter, which will be a single store or website object.<br>
Each store or website will be passed to the callback function in a separate process, 
allowing faster processing times.
The number of children process cannot exceed the number of stores or websites: for example,
if you have 10 stores, the maximum number of child processes that can be created in parallel is 10.

#### Here is a breakdown of the parameters:
- `$collection`/`$searchCriteria`/`$array`: The first parameter is the data source,
  either a `Magento\Framework\Api\SearchCriteriaInterface` for ForkedSearchResultProcessor or
  a `Magento\Framework\Data\Collection` for ForkedCollectionProcessor, and an `array`
  for ForkedArrayProcessor

- `$callback`: This parameter is a callable that will be executed on each item of the collection.
  It is a callback function that is passed the current item from the collection to be processed.
  This function should contain the business logic that should be executed on each item.

- `$pageSize`: This parameter is used to set the number of items per page.
  It is used to paginate the collection so that it can be processed in smaller chunks.

- `$maxChildrenProcess`: This parameter is used to set the maximum number of child
  processes that can be run simultaneously. This is used to control the number of threads
  that will be used by the multi-threading process. If set to 1, by definition you will have no parallelization, 
  the parent process will wait the child process to finish before creating another one.

- `$isIdempotent`: This parameter is a flag set to `true` by default and can be used for `ForkedSearchResultProcessor` 
  or `ForkedCollectionProcessor` when your `$maxChildrenProcess` is greater than one.
  While fetching data from database with `ForkedSearchResult` and `ForkedCollectionProcessor` you may change values
  queried: by modifying items on columns queried you will change the nature of the initial collection query and at the end, 
  the OFFSET limit in the query will be invalid because the native pagination system expect the pagination to be
  processed by only one process. To avoid that, set `$isIdempotent` to `false`.<br>
  E.G.: In your collection query, you request all products `disabled`, in your callback method you `enable` and save 
  them in database, then in this particular case you are modifying the column that you request in your collection,
  your query is not idempotent.

### Memory Limit
This module allows to bypass the limitation of the memory limit, because the memory
limit is reset on each child process creation. This means that even if the memory limit
is set to a low value, this module can still process large amounts of data without
running out of memory. However, it is important to keep in mind that this also means
that the overall resource usage will be higher, so it is important to monitor the
system and adjust the parameters accordingly.

### Limitations
This module uses `pcntl_fork()` function which is not available on Windows.

### Conclusion
This module provides a useful tool for running commands or processing collections
and search criteria in a multi-threaded way, making it a great solution for improving
performance and reducing execution time.
The module is easy to install and use, and provides options for controlling the number
of child processes, timeout, and environment variables.

### Disclaimer
The Multi-Threading for Magento 2 module is provided as is, without any guarantees or warranties.
While this module has been tested and is believed to be functional, it is important to note
that the use of multi-threading in PHP can be complex and may have unintended consequences.
As such, it is the responsibility of the user of this module to thoroughly test it in a
development environment before deploying it to a production environment.
I decline all responsibility for any issues or damages that may occur as a result of using
this module. With great power comes great responsibility, use it wisely.

