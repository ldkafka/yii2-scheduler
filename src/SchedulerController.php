<?php
namespace ldkafka\scheduler;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\base\ErrorException;

/**
 * Console controller for scheduled job execution.
 * 
 * Provides two execution modes:
 * 1. Single-run mode (actionRun) - executes jobs once, intended for system cron
 * 2. Daemon mode (actionDaemon) - continuous loop with high-precision timing
 * 
 * The controller delegates all scheduling logic to the Scheduler component,
 * acting only as a thin CLI wrapper with timing/loop control.
 * 
 * @property Scheduler $scheduler The scheduler component instance
 */
class SchedulerController extends Controller
{
    /**
     * Debug mode flag - enables verbose logging when true.
     * @const bool
     */
    public const DEBUG_MODE = false;
    
    /**
     * Tick interval in seconds for daemon mode.
     * Daemon will attempt to execute runJobs() at this interval.
     * @const int
     */
    public const TICK_INTERVAL = 60;
    
    /**
     * Maximum consecutive missed ticks before daemon exits.
     * A tick is "missed" when execution time exceeds the tick interval.
     * @const int
     */
    public const MAX_MISSED_TICKS = 5;

    /**
     * @var string Default action when controller is invoked without action name
     */
    public $defaultAction = 'usage';
    
    /**
     * @var Scheduler The scheduler component managing job execution
     */
    public $scheduler;

    /**
     * {@inheritdoc}
     * 
     * Initializes the controller and ensures scheduler component is available.
     */
    public function init()
    {
        parent::init();
        ($this->scheduler && ($this->scheduler instanceof Scheduler)) or $this->initScheduler();
    }

    /**
     * Initialize scheduler component.
     * 
     * Attempts to resolve scheduler from:
     * 1. $this->scheduler property (if already set)
     * 2. Yii::$app->scheduler application component
     * 3. New Scheduler instance as fallback
     * 
     * @return void
     */
    private function initScheduler(): void
    {
        $this->scheduler = $this->scheduler ?? (Yii::$app->scheduler ?? new Scheduler());
    }

    /**
     * Display usage information and available commands.
     * 
     * This is the default action when the controller is invoked without
     * specifying an action name. Shows all available actions and their
     * command-line options.
     * 
     * Usage:
     * ```
     * php yii scheduler
     * php yii scheduler/usage
     * ```
     * 
     * @return int ExitCode::OK
     */
    public function actionUsage(): int
    {
        echo "\nScheduler Controller - Job Scheduling and Execution\n";
        echo str_repeat('=', 60) . "\n\n";
        
        echo "AVAILABLE ACTIONS:\n\n";
        
        echo "  scheduler/show\n";
        echo "    Display scheduler configuration and registered jobs.\n";
        echo "    Usage: php yii scheduler/show\n\n";
        
        echo "  scheduler/run\n";
        echo "    Execute scheduled jobs once and exit.\n";
        echo "    Intended for system cron (run every minute).\n";
        echo "    Usage: php yii scheduler/run\n";
        echo "    Cron:  * * * * * /usr/bin/php /path/to/yii scheduler/run\n\n";
        
        echo "  scheduler/daemon\n";
        echo "    Run scheduler in continuous daemon mode.\n";
        echo "    Executes jobs every " . self::TICK_INTERVAL . " seconds with high-precision timing.\n";
        echo "    Usage: php yii scheduler/daemon\n\n";
        
        echo "CONFIGURATION:\n\n";
        echo "  Tick Interval:       " . self::TICK_INTERVAL . " seconds\n";
        echo "  Max Missed Ticks:    " . self::MAX_MISSED_TICKS . " consecutive\n";
        echo "  Debug Mode:          " . (self::DEBUG_MODE ? 'enabled' : 'disabled') . "\n\n";
        
        echo "DAEMON FEATURES:\n\n";
        echo "  • High-precision timing with drift compensation\n";
        echo "  • CPU-efficient sleep using usleep()\n";
        echo "  • Graceful shutdown via SIGINT/SIGTERM\n";
        echo "  • Automatic re-anchoring every 100 ticks\n";
        echo "  • Overrun detection and tracking\n\n";
        
        echo "EXAMPLES:\n\n";
        echo "  # Show configuration\n";
        echo "  php yii scheduler/show\n\n";
        
        echo "  # Run once (for cron)\n";
        echo "  php yii scheduler/run\n\n";
        
        echo "  # Start daemon\n";
        echo "  php yii scheduler/daemon\n\n";
        
        echo "  # Stop daemon (send SIGTERM)\n";
        echo "  kill -TERM \$(cat /var/run/scheduler.pid)\n\n";
        
        return ExitCode::OK;
    }

    /**
     * Display scheduler configuration and registered jobs.
     * 
     * This is the default action when the controller is invoked without
     * specifying an action name.
     * 
     * Usage:
     * ```
     * php yii scheduler
     * php yii scheduler/show
     * ```
     * 
     * @return int ExitCode::OK
     */
    public function actionShow(): int
    {
        echo "Showing loaded configuration:\n\n" . $this->scheduler->showConfig();
        return ExitCode::OK;
    }

    /**
     * Execute scheduled jobs once and exit.
     * 
     * This action is intended for system cron scheduling (e.g., run every minute).
     * Delegates all scheduling logic to the Scheduler component's runJobs() method.
     * 
     * Usage:
     * ```
     * php yii scheduler/run
     * ```
     * 
     * Crontab example:
     * ```
     * * * * * * /usr/bin/php /path/to/yii scheduler/run >> /dev/null 2>&1
     * ```
     * 
     * @return int ExitCode::OK on success, ExitCode::UNSPECIFIED_ERROR on failure
     */
    public function actionRun(): int
    {
        $ret = false;

        try {
            $ret = $this->scheduler->runJobs();
        } catch (\Throwable $e) {
            Yii::error("Scheduler run exception: {$e->getMessage()}", 'scheduler');
        }
        
        return $ret ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }    /**
     * Run scheduler in daemon mode with continuous execution loop.
     * 
     * Executes $this->scheduler->runJobs() at regular intervals (TICK_INTERVAL seconds).
     * Implements high-resolution timing with drift compensation to maintain accurate
     * scheduling even under variable system load.
     * 
     * Features:
     * - High-precision timing using microtime() with drift correction
     * - CPU-efficient sleep using usleep() between ticks
     * - Graceful shutdown via SIGINT/SIGTERM signal handling
     * - Automatic re-anchoring every 100 ticks to prevent long-term drift accumulation
     * - Overrun detection with consecutive miss tracking
     * - Exits after MAX_MISSED_TICKS consecutive overruns
     * 
     * Timing Algorithm:
     * 1. Establish base anchor timestamp at start
     * 2. Calculate target time for next tick: baseSecond + (tickCount × interval)
     * 3. After runJobs() completes, calculate remaining sleep time
     * 4. If positive sleep time: sleep until target, reset miss counter
     * 5. If negative (overrun): increment miss counter, continue immediately
     * 6. Re-anchor every 100 ticks to prevent drift from accumulating
     * 
     * Usage:
     * ```
     * php yii scheduler/daemon
     * ```
     * 
     * Systemd service example:
     * ```
     * [Unit]
     * Description=Scheduler Daemon
     * After=network.target
     * 
     * [Service]
     * Type=simple
     * User=www-data
     * WorkingDirectory=/path/to/app
     * ExecStart=/usr/bin/php /path/to/yii scheduler/daemon
     * Restart=always
     * 
     * [Install]
     * WantedBy=multi-user.target
     * ```
     * 
     * @return int ExitCode::OK on normal exit (signal or max missed ticks)
     */
    public function actionDaemon(): int
    {
        $tickInterval = self::TICK_INTERVAL;
        $maxMissed = self::MAX_MISSED_TICKS;

        $missedConsecutive = 0;
        $ticks = 0;
        $running = true;

        // Register signal handlers for graceful shutdown (SIGINT = Ctrl+C, SIGTERM = kill)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $sig) {
                pcntl_signal($sig, function() use (&$running) {
                    echo "shutdown...\n";
                    $running = false;
                });
            }
        }

        // Establish timing anchor for drift correction algorithm
        // Use integer seconds to align with clock boundaries
        $startTime = microtime(true);
        $baseSecond = (int)$startTime;
        
        Yii::info("Scheduler daemon started (PID: " . getmypid() . ", interval: {$tickInterval}s)", 'scheduler');

        while ($running) {
            $tickStart = microtime(true);
            $ticks++;

            // Execute scheduled jobs for this tick
            try {
                $this->scheduler->runJobs();
            } catch (\Throwable $e) {
                Yii::error("[Tick #{$ticks}] Exception: {$e->getMessage()}", 'scheduler');
            }

            // Calculate precise sleep duration to maintain schedule
            // Target next tick = anchor + (total_ticks × interval)
            // This compensates for any execution time variance
            $now = microtime(true);
            $elapsed = $now - $tickStart;
            $targetNextTick = $baseSecond + ($ticks * $tickInterval);
            $sleepTime = $targetNextTick - $now;

            if ($sleepTime > 0) {
                // Still have time before next tick - sleep efficiently
                $missedConsecutive = 0;
                usleep((int)($sleepTime * 1e6)); // convert seconds to microseconds
            } else {
                // Tick execution overran the interval - continue immediately
                $missedConsecutive++;
                Yii::warning(
                    sprintf('[Tick #%d] Overrun: %.2fs (missed: %d/%d)', 
                        $ticks, $elapsed, $missedConsecutive, $maxMissed
                    ), 
                    'scheduler'
                );
                
                // Safety exit if too many consecutive overruns occur
                if ($missedConsecutive >= $maxMissed) {
                    Yii::error('Max consecutive overruns exceeded. Stopping daemon.', 'scheduler');
                    break;
                }
            }

            // Periodically re-anchor timing base to prevent long-term drift
            // Every 100 ticks = ~100 minutes at 60-second interval
            if (($ticks % 100) === 0) {
                $baseSecond = (int)microtime(true);
                Yii::info("Re-anchored timing after {$ticks} ticks", 'scheduler');
            }
        }
        
        $totalRuntime = round(microtime(true) - $startTime, 2);
        Yii::info("Scheduler daemon stopped. Ticks: {$ticks}, Runtime: {$totalRuntime}s", 'scheduler');
        
        return ExitCode::OK;
    }
}
