# CHANGELOG

All notable changes to this project will be documented in this file.

## [1.0.4] - 2025-11-10
### Added
- Lock metadata now includes timestamp: `{pid, host, ts}` for better observability and future heartbeat support.
- Stale lock reclamation with race-safe re-read-before-delete pattern (prevents deleting newly-acquired locks).
- Robust cron pattern parser supporting:
  - Global step patterns (`*/5`)
  - Range with step (`10-20/2`)
  - List patterns with OR semantics (`1,3,5,7`)
  - Proper validation and field normalization (`day` → `mday`)

### Changed
- **Log levels adjusted for production**: Normal operations now use `info` instead of `warning`; only anomalies/failures use warning/error.
  - Job selection, queueing, execution, lock acquisition/release → info
  - Stale lock removal, max time exceeded, single-instance conflict → warning
  - Init failures, queue push errors, missing classes → error
- Lock storage format upgraded from integer PID to metadata array (backward compatible; old locks expire after TTL).
- Improved PHPDoc across all cron parsing methods (`needsToRun`, `patternMatches`, `normalizeScheduleField`).

### Fixed
- Corrected `EVERY_DAY` symbolic schedule to use `mday` (day of month) instead of invalid `day` key.
- `initCache` now only reads lock metadata as array (legacy numeric PID support removed per user confirmation).

### Removed
- Obsolete config options `staleLockTtl` and `maxJobsPerTick` removed from README (were never implemented).

## [1.0.3] - 2025-11-10
### Added
- Centralized runtime job cleanup via `finalizeRuntimeJob()` replacing legacy destructor and ad-hoc removal.
- SafeJobWrapper documented in README and architecture.

### Changed
- Expanded and corrected PHPDoc across `Scheduler` methods (runJobs, queueJob, runJob, finalizeRuntimeJob, needsToRun, canRun).
- README simplified (removed heartbeat/status sections), clarified usage and cleanup semantics.
- Architecture diagram updated to show finalizeRuntimeJob.

### Fixed
- Incorrect lock deletion (`delete($this->_lock_pid)`) now properly deletes `SCHEDULER_LOCK_CACHE_KEY`.
- Removed stray malformed docblock at file top causing parse errors.

## [1.0.2] - 2025-11-10
### Fixed
- Removed debug code comment from `initCache()` method
- Added return type and value to `runJobs()` method for proper exit code handling
- Fixed array access in `canRun()` to safely handle running job metadata
- Updated yii2-queue dependency to ~2.3.0 for compatibility with Symfony Process v4+

### Changed
- Improved PHPDoc comments for `runJobs()` and `canRun()` methods
- Better error handling for job metadata access

## [1.0.1] - 2025-11-09
### Changed
- Relaxed yii2-queue dependency from ~2.0.0 to * for broader compatibility

## [1.0.0] - 2025-11-08
### Added
- Initial public release of `ldkafka/yii2-scheduler`.
- Daemon mode with drift correction and microsecond-precision timing.
- Atomic distributed job locks using global Yii cache (host/pid/qid metadata).
- Queue integration with yiisoft/yii2-queue for async job execution.
- Single-instance job locking with cache-based tracking.
- Comprehensive PHPDocs for Scheduler, SchedulerController, ScheduledJob.
- Usage action showing all available commands and examples.
- Show action for displaying loaded configuration.

### Changed
- Centralized cache usage to `Yii::$app->cache` (configurable via config array).

### Notes
- Queue integration requires `yiisoft/yii2-queue`; jobs run inline if queue not configured.
- Requires Symfony Process v4.x for yii2-queue compatibility.

[1.0.4]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.4
[1.0.3]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.3
[1.0.1]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.1
[1.0.0]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.0
