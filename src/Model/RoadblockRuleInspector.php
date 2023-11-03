<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class RoadblockRuleInspector extends DataObject
{

    private ?RequestLog $requestLog = null;
    private ?SessionLog $sessionLog= null;
    private ?LoginAttempt $loginAttempt = null;
    private ?ArrayList $requestLogs = null;
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'Title' => 'Varchar(32)',
        'RequestURL' => 'Text',
        'RequestVerb' => "Enum('POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD')",
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'SessionIdentifier' => 'Varchar(45)',
        'LoginAttemptStatus' => "Enum('Success,Failure')",
        'ExpectedResult' => 'Text',
        'LastRun' => 'DBDatetime',
        'Result' => 'Text',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'RoadblockRule' => RoadblockRule::class,
    ];

    private static array $has_many = [
        'RequestLogTests' => RequestLogTest::class,
    ];

    private static string $table_name = 'RoadblockRuleInspector';

    private static string $plural_name = 'Tests';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'Title' => 'Title',
        'RequestURL' => 'URL',
        'RequestVerb' => 'Verb',
        'IPAddress' => 'IPAddress',
        'SessionIdentifier' => 'SessionIdentifier',
        'Member.Title' => 'Member',
        'LoginAttemptStatus' => 'LoginAttemptStatus',
        'getStatusNice' => 'Status',
    ];

    private static string $default_sort = 'Title';

    public function getExportFields(): array
    {
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        $fields = [
            'Title' => 'Title',
            'RequestURL' => 'RequestURL',
            'RequestVerb' => 'RequestVerb',
            'UserAgent' => 'UserAgent',
            'IPAddress' => 'IPAddress',
            'Member.ID' => 'Member',
            'RoadblockRule.Title' => 'RoadblockRule',
            'LoginAttemptStatus' => 'LoginAttemptStatus',
            'SessionIdentifier' => 'SessionIdentifier',
            'ExpectedResult' => 'ExpectedResult',
            'getRequestLogTestsCSV' => 'RequestLogTests',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $lastRunField = $fields->dataFieldByName('LastRun');
        $lastRunField->setReadOnly(true);

        $resultField = $fields->dataFieldByName('Result');
        $resultField->setReadOnly(true);

        return $fields;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function getStatusNice(): DBHTMLText
    {
        $result = str_replace(["\n", "\r"], '', $this->Result ?? '');
        $expected = str_replace(["\n", "\r"], '', $this->ExpectedResult ?? '');
        $text = $result === $expected
            ? '<strong style="color: green">Pass</strong>'
            : '<strong style="color: red">Fail</strong>';
        $html = DBHTMLText::create();
        $html->setValue($text);

        return $html;
    }

    public function getRequestLogTestsCSV(): string
    {
        $csvData = [];

        foreach ($this->RequestLogTests() as $restestLogTest) {
            $csvData[] = $restestLogTest->TimeOffset . '|' .
                $restestLogTest->URL . '|' .
                $restestLogTest->Verb . '|' .
                $restestLogTest->IPAddress . '|' .
                $restestLogTest->UserAgent;
        }

        return $csvData ? implode(',', $csvData) : '';
    }

    public function getRequestLog(): ?RequestLog
    {
        return $this->requestLog;
    }

    public function getSessionLog(): ?SessionLog
    {
        return $this->sessionLog;
    }

    public function getLoginAttempt(): ?LoginAttempt
    {
        return $this->loginAttempt;
    }

    public function getRequestLogs(): ?ArrayList
    {
        return $this->requestLogs;
    }

    public function setRequestLog(string $time): void
    {
        $url = $this->RequestURL;

        if ($url === null) {
            return;
        }

        $requestData = [
            'Created' => $time,
            'IPAddress' => $this->IPAddress,
            'RoadblockRequestTypeID' => RoadblockURLRule::getURLType($url),
            'SessionLogID' => 0,
            'URL' => $url,
            'UserAgent' => $this->UserAgent,
            'Verb' => $this->RequestVerb,
        ];

        $this->extend('updateSetRequestLogData', $requestData);

        $this->requestLog = RequestLog::create($requestData);
    }

    public function setSessionLog(string $time): void
    {
        $sessionData = [
            'IPAddress' => $this->IPAddress,
            'LastAccessed' => $time,
            'SessionIdentifier' => $this->SessionIdentifier,
            'SessionLastAccessed' => $time,
            'UserAgent' => $this->UserAgent,
        ];

        $this->sessionLog = SessionLog::create($sessionData);
    }

    public function setLoginAttempt(string $time): void
    {
        $loginAttemptData = [
            'Created' => $time,
            'MemberID' => $this->Member()->ID,
            'Status' => $this->LoginAttemptStatus,
        ];

        $this->loginAttempt = LoginAttempt::create($loginAttemptData);
    }

    public function setRequestLogs(string $time): void
    {
        $arrayList = ArrayList::create();
        $arrayList->push($this->requestLog);

        if ($this->RequestLogTests()) {
            foreach ($this->RequestLogTests() as $requestTest) {
                $url = $requestTest->URL;
                $timeObj = DBDatetime::create()->modify($time)->modify('-' . $requestTest->TimeOffset . ' seconds');

                $requestLogData = [
                    'Created' => $timeObj->format('y-MM-dd HH:mm:ss'),
                    'IPAddress' => $requestTest->IPAddress,
                    'RoadblockRequestTypeID' => RoadblockURLRule::getURLType($url),
                    'URL' => $url,
                    'UserAgent' => $requestTest->UserAgent,
                    'Verb' => $requestTest->Verb,
                ];

                $this->extend('updateSetRequestLogData', $requestLogData);

                $arrayList->push(RequestLog::create($requestLogData));
            }
        }

        $this->requestLogs = $arrayList;
    }

    public function setCurrentTest(): void
    {
        $time = DBDatetime::now()->Rfc2822();
        $this->setRequestLog($time);
        $this->setSessionLog($time);
        $this->setLoginAttempt($time);
        $this->setRequestLogs($time);

        $this->extend('updateSetCurrentTest', $this, $time);
    }

    public function importRoadblockRule(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['RoadblockRule']) {
            return;
        }

        $csv = trim($csv);

        $roadblockRules = RoadblockRule::get()->filter('Title', $csv);

        if (!$roadblockRules || !$roadblockRules->exists()) {
            return;
        }

        $this->RoadblockRuleID = $roadblockRules->first()->ID;
    }

    public function importRequestLogTests(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RequestLogTests']) {
            return;
        }

        // Removes all relationships with IP Rules
        $tests = $this->owner->RequestLogTests();

        if ($tests) {
            foreach ($tests as $test) {
                $test->delete();
            }
        }

        foreach (explode(',', trim($csv) ?? '') as $identifierstr) {
            if (!strpos($identifierstr, '|')) {
                continue;
            }

            $identifier = explode('|', trim($identifierstr));
            // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
            $data = [
                'TimeOffset' => $identifier[0],
                'URL' => $identifier[1],
                'Verb' => $identifier[2],
                'IPAddress' => $identifier[3],
                'UserAgent' => $identifier[4],
            ];

            $requestLogTest = RequestLogTest::create($data);

            $requestLogTest->write();

            $this->RequestLogTests()->add($requestLogTest);
        }
    }

    public function importMember(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['Member']) {
            return;
        }

        $csv = trim($csv);

        $members = Member::get()->filter('ID', $csv);

        if (!$members || !$members->exists()) {
            return;
        }

        $this->MemberID = $members->first()->ID;
    }

}
