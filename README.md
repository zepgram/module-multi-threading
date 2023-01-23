# Multi-Threading for Magento 2

This module is a powerful tool for developers who want to process large data sets in
a short amount of time. It allows you to process large collections of data in parallel
using multiple child processes, improving performance and reducing processing time.

## Installation
```php
composer require zepgram/multi-threading
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
        $pageSize = 100,
        $maxChildrenProcess = 10,
        $isParallelize = true
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
        $searchCriteria,
        $productRepository,
        $callback,
        $pageSize = 100,
        $maxChildrenProcess = 10,
        $isParallelize = true
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
    
    $array = [1,2,3,4,5];
    $callback = function ($item) {
        echo $item;
        // do your business logic here
    };
    
    $this->forkedArrayProcessor->process(
        $array,
        $callback,
        $pageSize = 2,
        $maxChildrenProcess = 2,
        $isParallelize = true
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
The `thread:processor` command creates a new child process using the `pcntl_fork()` function,
which is a system call that creates a child process, allowing the parent process to continue
executing. The child process runs the command specified by the user, while the parent process
monitors the child process and can act accordingly.

The `ForkedSearchResultProcessor`,`ForkedCollectionProcessor` and `ForkedArrayProcessor` classes
use a similar approach to process a search criteria or a collection. The process is divided
into several pages, and for each page, a child process is created to run the callback
function specified by the user on each item of that page.

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
  that will be used by the multi-threading process.

- `$isParallelize`: This parameter is used to set whether the multi-threading process should run
  in parallel or sequentially. If set to true, the process will run in parallel,
  if set to false, the process will run sequentially. Running task sequentially may be useful when
  you want to keep the sort order of your items.

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
this module. Great power comes with greater responsibility, use it wisely.
