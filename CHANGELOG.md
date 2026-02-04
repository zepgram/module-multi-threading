# Changelog

All notable changes to this project will be documented in this file.

## [0.3.0] - 2026-02-03

### Fixed

#### Critical Bugs (4)
- **[CRIT-1] Fallback exit() in parent context**: Fixed `processChild()` being called directly in parent context during fallback, which would call `exit(1)` and kill the parent process. Added new `processChildInParent()` method that handles errors gracefully without calling `exit()`.

- **[CRIT-2] Infinite recursion in non-idempotent mode**: Added `MAX_RECURSION_DEPTH` constant (default: 10) to prevent stack overflow when using `isIdempotent=false`. The processor now logs an error and stops gracefully when the limit is reached.

- **[CRIT-3] Division by zero with pageSize=0**: Added validation in all ItemProvider constructors (`ArrayWrapper`, `CollectionWrapper`, `SearchResultWrapper`) to throw `InvalidArgumentException` when `pageSize <= 0`. Previously, this would cause a fatal division by zero error in `getTotalPages()`.

- **[CRIT-4] Database connection corruption after fork**: Added `ResourceConnection` dependency to `ForkedProcessor` and `reconnectDatabase()` method. After `pcntl_fork()`, parent and child share the same MySQL connection handle, causing "MySQL server has gone away" errors. The child process now closes the inherited connection to force a fresh connection on first query.

#### High Severity Bugs (2)
- **[HIGH-1] Counter desync on wait error**: When `pcntl_wait()` returns an error (pid <= 0), the child process counter is now reset to the actual count of tracked children instead of breaking with a stale counter value.

- **[HIGH-2] Empty page causes error exit**: Empty pages (due to deletions, pagination edge cases) no longer trigger `exit(1)`. An empty page is now considered successful (exit code 0) since there's nothing to process.

#### Medium Severity Bugs (3)
- **[MED-1] getSize() mutates collection/criteria state**: `CollectionWrapper` and `SearchResultWrapper` now cache the size for idempotent operations and restore state after querying for non-idempotent operations. This prevents unintended side effects when `getSize()` or `getTotalPages()` is called multiple times.

- **[MED-2] Signal handler leaves orphan processes**: `handleSig()` now properly terminates all child processes when receiving SIGINT/SIGTERM:
  1. Sends SIGTERM to all tracked children
  2. Waits up to 5 seconds for graceful shutdown
  3. Force kills (SIGKILL) any remaining children

- **[MED-3] pcntl_waitpid(-1) after fork failure**: In single-process mode, when `pcntl_fork()` fails (returns -1), the code now properly skips the wait call and tracks the page for retry, instead of calling `pcntl_waitpid(-1)` which would wait for any arbitrary child process.

#### Low Severity Bugs (3)
- **[LOW-1] Single-process mode no retry**: Added retry mechanism for failed pages in single-process mode. Previously, failed pages were logged but silently skipped.

- **[LOW-2] Fork failure silently skips pages**: Fork failures in multi-process mode now properly log the error and continue, with failed pages tracked for potential retry.

- **[LOW-3] Typo in log message**: Fixed "Error on callback function will processing item" → "Error on callback function while processing item".

### Changed
- `processChild()` now returns an int exit code instead of calling `exit()` directly. The `exit()` call is made by the caller.
- `$childPids` is now a class property instead of a local variable, enabling proper cleanup in signal handlers.
- `$recursionDepth` added as class property to track non-idempotent fallback recursion.
- `CollectionWrapper::getSize()` now caches result for idempotent mode.
- `SearchResultWrapper::getSize()` now caches result for idempotent mode and restores state.
- Added `resetCache()` method to both wrappers for explicit cache invalidation.
- Added `ResourceConnection` as optional constructor parameter in `ForkedProcessor`.
- Added `reconnectDatabase()` private method to handle database connection reset in child processes.
- Updated `di.xml` to inject `ResourceConnection` into `ForkedProcessor`.

### Notes on Non-Idempotent Mode

The `isIdempotent=false` mode uses modulo-based pagination where multiple logical pages map to the same database page. This is **by design** for queue-like processing where items are removed after processing. 

**⚠️ Warning**: The modulo pagination (page N maps to page N % maxChildren) means:
- With maxChildren=3 and 9 pages: pages 1,4,7 all query DB page 1
- This is intentional for queue processing where items are removed

**Only use `isIdempotent=false` when**:
1. Your callback permanently removes items from the collection (e.g., deleting records)
2. Or updates a field that excludes them from the original query
3. You understand that the same database page will be queried multiple times

If your callback does not remove items, you will get:
1. Duplicate processing of the same items
2. Potential infinite loops (now limited by MAX_RECURSION_DEPTH)

### Migration Notes

This is a **backward-compatible** bug fix release. No code changes required.

If you were relying on any of the buggy behaviors (unlikely), note:
- Empty pages no longer trigger error logs
- Signal handlers now terminate children (may affect monitoring)
- Fallback no longer kills parent process on error

## [0.2.0] - 2026-01-26

### Fixed
- Fix incorrect exit status check that caused false error logs
- Enable `pcntl_async_signals(true)` for proper signal handling
- Replace unreliable self-SIGKILL shutdown with proper exit codes
- Add `pcntl_wait` return value check to prevent infinite loops
- Fix typo `isIdemPotent` -> `isIdempotent` in interface
- Fix `@inheirtDoc` -> `@inheritDoc` typos
