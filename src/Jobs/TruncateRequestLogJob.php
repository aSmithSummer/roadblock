<?php

namespace Roadblock\Jobs;

use Roadblock\Model\RequestLog;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class TruncateRequestLogJob extends AbstractQueuedJob implements QueuedJob
{
    protected array $definedSteps = [
        'stepRemoveStaleRecords',
        'stepCreateNextSchedule',
    ];

    /**
     * Constructor
     *
     * @param array $params of job params
     * Can be 'test', 'repeat', or numeric seconds ago to start truncation from,
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    public function __construct(...$params)
    {
        $params= array_filter($params);
        $time = self::config()->get('keep_log_period') ;
        $paramArray = [
            'test' => false,
            'repeat' => false,
            'date' => null,
        ];

        if ($params) {
            foreach ($params as $param) {
                if ($param === 'test') {
                    // isCMSAdminTest is not defined as this causes issues with automation of job creation.
                    $paramArray['test'] = true;
                } elseif ($param === 'repeat') {
                    // repeat is not defined as this causes issues with automation of job creation.
                    $paramArray['repeat'] = true;
                } elseif (is_numeric($param)) {
                    $time = $param;
                }
            }
        }

        $paramArray['date'] = DBDatetime::now()
            ->modify('-' . $time . ' seconds')
            ->format('y-MM-dd HH:mm:ss');

        $this->paramArray = $paramArray;
    }

    public function getTitle(): string
    {
        return 'Remove old requests';
    }

    public function process(): void
    {
        if (!isset($this->definedSteps[$this->currentStep])) {
            throw new Exception('User error, unknown step defined.');
        }

        $stepIsDone = call_user_func([$this, $this->definedSteps[$this->currentStep]]);

        if ($stepIsDone) {
            $this->currentStep++;
        }

        if ($this->currentStep < $this->totalSteps) {
            return;
        }

        $this->isComplete = true;
    }

    public function stepRemoveStaleRecords(): bool
    {
        $records = RequestLog::get()->filter([
            'Created:LessThanOrEqual' => $this->paramArray['date'],
        ]);

        $this->addMessage('Step 1: ' . $records->count() . ' to delete.');

        if ($this->paramArray['test']) {
            return true;
        }

        foreach ($records as $record) {
            $record->delete();
        }

        return true;
    }

    public function stepCreateNextSchedule(): bool
    {
        if ($this->paramArray['repeat']) {
            $this->addMessage('Step 2: Creating next schedule and finishing up.');

            $nextDate = DBDatetime::create()
                ->modify($this->paramArray['date'])
                ->modify(self::config()->get('keep_log_repeat_interval'))
                ->format('y-MM-dd HH:mm:ss');

            $params = [
                $nextDate,
                'repeat',
            ];

            if ($this->paramArray['test']) {
                $params[] = 'test';
            }

            $job = Injector::inst()->createWithArgs(get_class($this), $params);

            $service = singleton(QueuedJobService::class);
            $jobId = $service->queueJob($job, $nextDate);

            if ($jobId) {
                $this->addMessage('Step 5: Scheduled job created for ' . $nextDate);
            } else {
                $this->addMessage('Step 5: Please manually create a job for ' . $nextDate);
            }
        } else {
            $this->addMessage('Step 5: No repeat job to create.');
        }

        return true;
    }

}
