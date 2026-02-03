# Changelog

All notable changes to this project will be documented in this file.

## [0.3.0] - 2026-02-03

### Fixed

#### Critical Bugs
- **[CRIT-1] Fallback exit() in parent context**: Fixed `processChild()` being called directly in parent context during fallback, which would call `exit(1)` and kill the parent process. Added new `processChildInParent()` method that handles errors gracefully without calling `exit()`.

- **[CRIT-2] Infinite recursion in non-idempotent mode**: Added `MAX_RECURSION_DEPTH` constant (default: 10) to prevent stack overflow when using `isIdempotent=false`. The processor now logs an error and stops gracefully when the limit is reached.

#### High Severity Bugs
- **[HIGH-1] Counter desync on wait error**: When `pcntl_wait()` returns an error (pid <= 0), the child process counter is now reset to the actual count of tracked children instead of breaking with a stale counter value.

- **[HIGH-2] Empty page causes error exit**: Empty pages (due to deletions, pagination edge cases) no longer trigger `exit(1)`. An empty page is now considered successful (exit code 0) since there's nothing to process.

#### Medium Severity Bugs
- **[MED-2] Signal handler leaves orphan processes**: `handleSig()` now properly terminates all child processes when receiving SIGINT/SIGTERM:
  1. Sends SIGTERM to all tracked children
  2. Waits up to 5 seconds for graceful shutdown
  3. Force kills (SIGKILL) any remaining children

#### Other Improvements
- **Single-process mode fallback**: Added retry mechanism for failed pages in single-process mode (previously pages were silently skipped).
- **Better page tracking**: Changed from tracking only failed pages to tracking all completed pages, making fallback logic more reliable.
- **Improved logging**: Added more detailed logging including page numbers in error messages, debug logs for successful completions, and recursion depth tracking.
- **Code clarity**: Split `processChild()` into two methods - one for forked context (returns exit code) and one for parent context (no exit calls).
- **Fixed typo**: "Error on callback function will processing item" → "Error on callback function while processing item"

### Changed
- `processChild()` now returns an int exit code instead of calling `exit()` directly. The `exit()` call is made by the caller.
- `$childPids` is now a class property instead of a local variable, enabling proper cleanup in signal handlers.
- `$recursionDepth` added as class property to track non-idempotent fallback recursion.

### Notes on Non-Idempotent Mode
The `isIdempotent=false` mode uses modulo-based pagination where multiple logical pages map to the same database page. This is **by design** for queue-like processing where items are removed after processing. 

**Warning**: If your callback does not remove items from the query result set, this mode will cause:
1. Duplicate processing of items
2. Potential infinite loops (now limited by MAX_RECURSION_DEPTH)

Only use `isIdempotent=false` when your callback permanently removes items from the collection (e.g., deleting records, updating a status field that excludes them from the query).

## [0.2.0] - 2026-01-26

### Fixed
- Fix incorrect exit status check that caused false error logs
- Enable `pcntl_async_signals(true)` for proper signal handling
- Replace unreliable self-SIGKILL shutdown with proper exit codes
- Add `pcntl_wait` return value check to prevent infinite loops
- Fix typo `isIdemPotent` -> `isIdempotent` in interface
- Fix `@inheirtDoc` -> `@inheritDoc` typos
