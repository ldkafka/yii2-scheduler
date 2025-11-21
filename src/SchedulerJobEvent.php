<?php
namespace ldkafka\scheduler;

use yii\base\Event;

/**
 * SchedulerJobEvent
 * 
 * Custom event class for scheduler job events with typed properties
 */
class SchedulerJobEvent extends Event
{
    /**
     * @var string The job class name (FQCN)
     */
    public $job_class;
    
    /**
     * @var array The job configuration
     */
    public $job_config;
    
    /**
     * @var float|null Job start time (microtime)
     */
    public $start_time;
    
    /**
     * @var float|null Job end time (microtime)
     */
    public $end_time;
    
    /**
     * @var mixed Job execution result
     */
    public $result;
    
    /**
     * @var string|null Error message (for error events)
     */
    public $error;
    
    /**
     * @var string|null Exception class name (for error events)
     */
    public $exception;
    
    /**
     * @var string|null Exception trace (for error events)
     */
    public $trace;
    
    /**
     * @var float|null Error time (microtime, for error events)
     */
    public $error_time;
    
    /**
     * @var string|null Reason for blocked/timeout
     */
    public $reason;
    
    /**
     * @var float|null Running time (for timeout/blocked events)
     */
    public $running_time;
}
