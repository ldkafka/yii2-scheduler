# Release Checklist for yii2-scheduler v1.0.5

## Changes Synced from Vendor
✅ Added `SchedulerJobEvent.php` - typed event class for monitoring
✅ Added event constants to `Scheduler.php` (EVENT_JOB_BEFORE_RUN, EVENT_JOB_AFTER_RUN, EVENT_JOB_ERROR, EVENT_JOB_BLOCKED, EVENT_JOB_TIMEOUT)
✅ Added EVENT_JOB_BLOCKED trigger in `Scheduler::canRun()` method
✅ Updated `SafeJobWrapper.php` with event triggers for before/after/error events

## Documentation Updates
✅ Updated `composer.json` version to 1.0.5
✅ Updated description to mention monitoring events
✅ Added comprehensive v1.0.5 entry to `CHANGELOG.md`
✅ Added "Monitoring Events" section to `README.md` with usage examples
✅ Created `examples/MonitoringExample.php` with practical integration patterns

## Files Modified
- `src/SchedulerJobEvent.php` (NEW)
- `src/Scheduler.php`
- `src/SafeJobWrapper.php`
- `composer.json`
- `CHANGELOG.md`
- `README.md`
- `examples/MonitoringExample.php` (NEW)

## Git Commands for Release

```powershell
# Navigate to yii2-scheduler directory
cd C:\work\SoftwareProjects\yii2-scheduler

# Stage all changes
git add .

# Commit with descriptive message
git commit -m "Release v1.0.5: Add monitoring events system

- Add SchedulerJobEvent class with typed properties
- Add EVENT_JOB_BEFORE_RUN, EVENT_JOB_AFTER_RUN, EVENT_JOB_ERROR, EVENT_JOB_BLOCKED events
- Integrate events into SafeJobWrapper and Scheduler
- Add monitoring events documentation and examples
- Update README with event handler configuration
- Add MonitoringExample.php showing practical integration patterns"

# Create annotated tag
git tag -a v1.0.5 -m "Version 1.0.5 - Monitoring Events

New Features:
- Scheduler monitoring events for observability
- SchedulerJobEvent class with typed properties
- Event triggers throughout job lifecycle
- Integration examples and documentation

Events:
- jobBeforeRun: Job about to execute
- jobAfterRun: Job completed successfully
- jobError: Job threw exception
- jobBlocked: Job prevented from running (single-instance lock)
- jobTimeout: Reserved for future use"

# Push to remote
git push origin main
git push origin v1.0.5

# Create GitHub release (via web UI or gh cli)
# gh release create v1.0.5 --title "v1.0.5 - Monitoring Events" --notes-file RELEASE_NOTES.md
```

## GitHub Release Notes Template

```markdown
# v1.0.5 - Monitoring Events

## 🎉 New Features

### Monitoring Events System
The scheduler now triggers events throughout the job lifecycle, enabling integration with monitoring systems, alerting, and observability tools.

**Available Events:**
- `EVENT_JOB_BEFORE_RUN`: Triggered before job execution
- `EVENT_JOB_AFTER_RUN`: Triggered after successful execution
- `EVENT_JOB_ERROR`: Triggered when job throws exception
- `EVENT_JOB_BLOCKED`: Triggered when job blocked by single-instance lock
- `EVENT_JOB_TIMEOUT`: Reserved for future timeout detection

**Event Data:**
All events use the new `SchedulerJobEvent` class with typed properties:
- `job_class`: Job FQCN
- `job_config`: Job configuration array
- `start_time`, `end_time`: Microtime timestamps
- `result`: Job execution result
- `error`, `exception`, `trace`: Error details
- `reason`, `running_time`: Blocking details

## 📖 Documentation

- Comprehensive monitoring events section in README
- Practical integration examples in `examples/MonitoringExample.php`
- Event handler configuration examples
- Alert and metric collection patterns

## 🔧 Usage Example

```php
'scheduler' => [
    'class' => ldkafka\scheduler\Scheduler::class,
    'on jobAfterRun' => function ($event) {
        $duration = $event->end_time - $event->start_time;
        Yii::info("Job {$event->job_class} completed in {$duration}s", 'monitoring');
    },
    'on jobError' => function ($event) {
        Yii::error("Job {$event->job_class} failed: {$event->error}", 'monitoring');
        // Send alert, record metric, etc.
    },
    'config' => [ /* ... */ ],
    'jobs' => [ /* ... */ ],
],
```

## 📦 Installation

```bash
composer require ldkafka/yii2-scheduler:^1.0.5
```

## 🔗 Full Changelog
See [CHANGELOG.md](https://github.com/ldkafka/yii2-scheduler/blob/main/CHANGELOG.md) for complete details.
```

## Post-Release Checklist
- [ ] Verify tag appears on GitHub releases page
- [ ] Test composer installation: `composer require ldkafka/yii2-scheduler:^1.0.5`
- [ ] Update any dependent projects (like aura_v5) to use new version
- [ ] Announce release (if applicable)
- [ ] Monitor for issues/feedback

## Notes
- Events are backward compatible - existing installations work without changes
- Event handlers are optional - attach only what you need
- All events work in both sync and async (queue) modes
- MonitoringExample.php shows integration patterns for various use cases
