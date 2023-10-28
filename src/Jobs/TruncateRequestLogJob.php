<?php

namespace aSmithSummer\Roadblock\Jobs;

use aSmithSummer\Roadblock\Model\RequestLog;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class TruncateRequestLogJob extends AbstractQueuedJob implements QueuedJob
{

    use Configurable;

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
        $this->totalSteps = count($this->definedSteps);
        $params= array_filter($params);
        $time = self::config()->get('keep_log_period') ;
        $paramArray = [
            'date' => null,
            'repeat' => false,
            'test' => false,
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
        return _t(self::class . '.TITLE', 'Remove old requests');
    }

    public function process(): void
    {
        if (!isset($this->definedSteps[$this->currentStep])) {
            throw new Exception(_t(self::class . '.USER_EXCEPTION', 'User error, unknown step defined.'));
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

        $this->addMessage(_t(
            self::class . '.DELETION_COUNT',
            'Step 1: {count} to delete.',
            ['count' => $records->count()]
        ));

        foreach ($this->paramArray as $k => $v) {
            $this->addMessage(_t(
                self::class . '.PARAMETERS',
                'Param "{name}" set to "{value}".',
                ['name' => $k, 'value' => $v]
            ));
        }

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
            $this->addMessage(_t(
                self::class . '.NEXT',
                'Step 2: Creating next schedule and finishing up.'
            ));

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
                $this->addMessage(_t(
                    self::class . '.CREATED',
                    'Step 2: Scheduled job created for {next}.',
                    ['next' => $nextDate]
                ));
            } else {
                $this->addMessage(_t(
                    self::class . '.NOT_CREATED',
                    'Step 2: Please manually create a job for {next}.',
                    ['next' => $nextDate]
                ));
            }
        } else {
            $this->addMessage(_t(
                self::class . '.NO_REPEAT',
                'Step 2: No repeat job to create.'
            ));
        }

        return true;
    }

}
