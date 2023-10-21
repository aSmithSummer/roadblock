<?php

namespace Roadblock\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RoadblockRuleInspector extends DataObject
{

    private ?RequestLog $requestLog = null;
    private ?SessionLog $sessionLog= null;
    private ?LoginAttempt $loginAttempt = null;
    private ?ArrayList $requestLogs = null;

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

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $lastRunField = $fields->dataFieldByName('LastRun');
        $lastRunField->setReadOnly(true);

        $resultField = $fields->dataFieldByName('Result');
        $resultField->setReadOnly(true);

        return $fields;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function getStatusNice(): DBHTMLText
    {
        $text = $this->Result === $this->ExpectedResult ?
            '<strong style="color: green">Pass</strong>' :
            '<strong style="color: red">Fail</strong>';
        $html = DBHTMLText::create();
        $html->setValue($text);

        return $html;
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
            'URL' => $url,
            'Verb' => $this->RequestVerb,
            'IPAddress' => $this->IPAddress,
            'UserAgent' => $this->UserAgent,
            'RoadblockRequestTypeID' => RoadblockURLRule::getURLType($url),
        ];

        $this->extend('updateSetRequestLogData', $requestData);

        $this->requestLog = RequestLog::create($requestData);
    }

    public function setSessionLog(string $time): void
    {
        $sessionData = [
            'SessionIdentifier' => $this->SessionIdentifier,
            'LastAccessed' => $time,
            'IPAddress' => $this->IPAddress,
            'UserAgent' => $this->UserAgent,
            'SessionLastAccessed' => $time,
        ];

        $this->sessionLog = SessionLog::create($sessionData);
    }

    public function setLoginAttempt(string $time): void
    {
        $loginAttemptData = [
            'Status' => $this->LoginAttemptStatus,
            'MemberID' => $this->Member()->ID,
            'Created' => $time,
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
                $timeObj = DBDatetime::create()->modify($time)->modify('-' . $requestTest . ' seconds');

                $requestLogData = [
                    'Created' => $timeObj->format('y-MM-dd HH:mm:ss'),
                    'URL' => $url,
                    'Verb' => $requestTest->Verb,
                    'IPAddress' => $requestTest->IPAddress,
                    'UserAgent' => $requestTest->UserAgent,
                    'RoadblockRequestTypeID' => RoadblockURLRule::getURLType($url),
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

}
