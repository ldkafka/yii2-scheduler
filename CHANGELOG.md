# CHANGELOG

All notable changes to this project will be documented in this file.

## [1.0.0] - 2025-11-08
### Added
- Initial public release of `ldkafka/yii2-scheduler`.
- Daemon mode with drift correction and heartbeat (`scheduler_daemon_heartbeat`).
- Atomic distributed job locks using global Yii cache (host/pid/jid metadata).
- Stale lock cleanup via `staleLockTtl` configuration.
- Per-tick job cap (`maxJobsPerTick`) for backpressure.
- Status action `scheduler/status` exposing heartbeat and lock payloads.
- Comprehensive PHPDocs for Scheduler, SchedulerController, ScheduledJob.

### Changed
- Centralized cache usage to `Yii::$app->cache` (removed configurable cache override).

### Notes
- Queue integration requires `yiisoft/yii2-queue`; jobs run inline if queue not configured.
- Future versions will include concurrency policy and adaptive backpressure.

[1.0.0]: https://github.com/ldkafka/yii2-scheduler/releases/tag/v1.0.0
