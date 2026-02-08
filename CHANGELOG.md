# Changelog

All notable changes to this project will be documented in this file.

## [0.3.0] - 2026-02-07

### Fixed

- **thread:processor exit status masking**: command now returns failure when at least one wrapped iteration fails.
- **thread:processor argument passthrough**: command now supports command arguments (including whitespace command strings like `"help cache:clean"`).
- **thread:processor memory pressure on output-heavy commands**: output is now flushed incrementally while child process is running instead of only at process end.
- **invalid max children configuration**: `ForkedProcessorRunner`, `ParallelStoreProcessor`, and `ParallelWebsiteProcessor` now reject `maxChildrenProcess <= 0`.
- **dimension processors with empty inputs**: store/website processors now return early without running the forked runner when no targets exist.

### Added

- `thread:processor --fail-on-loop` option to break iteration loop after first failure.
- `thread:processor --ignore-exit-code` option to force success exit code while emitting a warning summary.
- `thread:processor command_args` array argument.

### Changed

- `ForkedProcessor` fallback now targets explicitly failed pages instead of all non-completed pages.
- `ForkedProcessor` supports configurable child DB reconnect behavior through constructor argument `reconnectDatabaseInChild` (now opt-in; default `false` to preserve DB session compatibility for temporary-table-based workloads).
- `ForkedProcessor` compatibility mode (default) now terminates child workers with signals to avoid PHP child shutdown closing parent DB session state used by temporary-table-based workloads.

## [0.2.0] - 2026-01-26

### Fixed
- Fix incorrect exit status check that caused false error logs
- Enable `pcntl_async_signals(true)` for proper signal handling
- Replace unreliable self-SIGKILL shutdown with proper exit codes
- Add `pcntl_wait` return value check to prevent infinite loops
- Fix typo `isIdemPotent` -> `isIdempotent` in interface
- Fix `@inheirtDoc` -> `@inheritDoc` typos
