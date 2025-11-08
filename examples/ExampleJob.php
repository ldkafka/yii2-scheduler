<?php
namespace examples;

use ldkafka\scheduler\ScheduledJob;
use Yii;

class ExampleJob extends ScheduledJob
{
    public function execute($queue = null)
    {
        Yii::info('Example job ran', 'scheduler');
        return true;
    }
}
