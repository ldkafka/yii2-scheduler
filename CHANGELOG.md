# CHANGELOG

All notable changes to this project will be documented in this file.

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

[1.0.2]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.2
[1.0.1]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.1
[1.0.0]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.0
