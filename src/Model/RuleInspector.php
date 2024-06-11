<?php

namespace aSmithSummer\Roadblock\Model;

use ReflectionClass;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class RuleInspector extends DataObject
{

    private ?RequestLog $requestLog = null;
    private ?SessionLog $sessionLog= null;
    private ?LoginAttempt $loginAttempt = null;
    private ?ArrayList $requestLogs = null;

    private ?ArrayList $loginAttempts = null;

    private static array $db = [
        'Title' => 'Varchar(50)',
        'RequestURL' => 'Text',
        'RequestVerb' => "Enum('POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD')",
        'StatusCode' => 'Varchar(8)',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'SessionIdentifier' => 'Varchar(45)',
        'LoginAttemptStatus' => "Enum('Success,Failed')",
        'ExpectedResult' => 'Text',
        'LastRun' => 'DBDatetime',
        'Result' => 'Text',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'Rule' => Rule::class,
    ];

    private static array $has_many = [
        'RequestLogInspectors' => RequestLogInspector::class,
        'LoginAttemptInspectors' => LoginAttemptInspector::class,
    ];

    private static string $table_name = 'RuleInspector';

    private static string $plural_name = 'Assessments';

    private static array $summary_fields = [
        'Title' => 'Title',
        'RequestURL' => 'URL',
        'RequestVerb' => 'Verb',
        'IPAddress' => 'IPAddress',
        'SessionIdentifier' => 'SessionIdentifier',
        'Member.Title' => 'Member',
        'LoginAttemptStatus' => 'LoginAttemptStatus',
        'getStatusDescription' => 'Status',
        'Result' => 'Result',
    ];

    private static string $default_sort = 'Title';

    public function getExportFields(): array
    {

        $fields = [
            'Title' => 'Title',
            'RequestURL' => 'RequestURL',
            'RequestVerb' => 'RequestVerb',
            'UserAgent' => 'UserAgent',
            'IPAddress' => 'IPAddress',
            'StatusCode' => 'StatusCode',
            'Member.ID' => 'Member',
            'Rule.Title' => 'Rule',
            'LoginAttemptStatus' => 'LoginAttemptStatus',
            'SessionIdentifier' => 'SessionIdentifier',
            'ExpectedResult' => 'ExpectedResult',
            'getRequestLogInspectorsForCSV' => 'RequestLogInspectors',
            'getLoginAttemptInspectorsForCSV' => 'LoginAttemptInspectors',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $rule = $this->Rule();
        $this->Result = $rule->assessmentResult($this, false);
        $this->LastRun = DBDatetime::now();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->dataFieldByName('LastRun')->setReadOnly(true);
        $fields->dataFieldByName('Result')->setReadOnly(true);

        $fields->removeByName('StatusCode');

        $response = new ReflectionClass(HTTPResponse::class);
        $options = $response->getStaticPropertyValue('status_codes');

        $statusCode = DropdownField::create('StatusCode', 'Status code', $options)
            ->setHasEmptyDefault(true)->setEmptyString('(none)');
        $fields->insertAfter('RequestVerb', $statusCode);

        return $fields;
    }

    public function getStatusDescription(): DBHTMLText
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

    public function getRequestLogInspectorsForCSV(): string
    {
        $csvData = [];

        foreach ($this->RequestLogInspectors() as $requestLogInspector) {
            $csvData[] = $requestLogInspector->TimeOffset . '|' .
                $requestLogInspector->URL . '|' .
                $requestLogInspector->Verb . '|' .
                $requestLogInspector->StatusCode . '|' .
                $requestLogInspector->IPAddress . '|' .
                $requestLogInspector->UserAgent;
        }

        return $csvData ? implode(',', $csvData) : '';
    }

    public function getLoginAttemptInspectorsForCSV(): string
    {
        $csvData = [];

        foreach ($this->LoginAttemptInspectors() as $loginAttemptInspector) {
            $csvData[] = $loginAttemptInspector->TimeOffset . '|' .
                $loginAttemptInspector->Status . '|' .
                $loginAttemptInspector->IPAddress . '|' .
                $loginAttemptInspector->UserAgent;
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

    public function getLoginAttempts(): ?ArrayList
    {
        return $this->loginAttempts;
    }

    public function getRequestLogs(): ?ArrayList
    {
        return $this->requestLogs;
    }

    public function prepareRequestLog(string $time): void
    {
        $url = $this->RequestURL;

        if ($url === null) {
            return;
        }

        $requestData = [
            'Created' => $time,
            'IPAddress' => $this->IPAddress,
            'SessionLogID' => 0,
            'URL' => $url,
            'UserAgent' => $this->UserAgent,
            'Verb' => $this->RequestVerb,
            'StatusCode' => $this->StatusCode,
            'Types' => URLRule::getURLTypes($url),
        ];

        $this->extend('updatePrepareRequestLog', $requestData);

        $this->requestLog = RequestLog::create($requestData);
    }

    public function prepareSessionLog(string $time): void
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

    public function prepareLoginAttempt(string $time): void
    {
        $memberID = $this->Member() ? $this->Member()->ID : 0;
        $loginAttemptData = [
            'Created' => $time,
            'MemberID' => $memberID,
            'Status' => $this->LoginAttemptStatus,
            'IP' => $this->IPAddress,
            'UserAgent' => $this->UserAgent,
        ];

        $this->loginAttempt = LoginAttempt::create($loginAttemptData);
    }

    public function prepareRequestLogs(string $time): void
    {
        $arrayList = ArrayList::create();
        $arrayList->push($this->requestLog);

        if ($this->RequestLogInspectors()) {
            foreach ($this->RequestLogInspectors() as $requestInspetor) {
                $url = $requestInspetor->URL;
                $timeObj = DBDatetime::create()->modify($time)->modify('-' . $requestInspetor->TimeOffset . ' seconds');

                $requestLogData = [
                    'Created' => $timeObj->format('y-MM-dd HH:mm:ss'),
                    'IPAddress' => $requestInspetor->IPAddress,
                    'URL' => $url,
                    'UserAgent' => $requestInspetor->UserAgent,
                    'Verb' => $requestInspetor->Verb,
                    'StatusCode' => $requestInspetor->StatusCode,
                    'Types' => URLRule::getURLTypes($url),
                ];

                $this->extend('updatePrepareRequestLogs', $requestLogData);

                $arrayList->push(RequestLog::create($requestLogData));
            }
        }

        $this->requestLogs = $arrayList;
    }

    public function prepareLoginAttempts(string $time): void
    {
        $arrayList = ArrayList::create();
        $arrayList->push($this->loginAttempt);

        if ($this->LoginAttemptInspectors()) {
            foreach ($this->LoginAttemptInspectors() as $loginAttemptInspector) {
                $timeObj = DBDatetime::create()->modify($time)->modify('-' . $loginAttemptInspector->TimeOffset . ' seconds');
                $memberID = $this->Member() ? $this->Member()->ID : 0;

                $loginAttemptData = [
                    'Created' => $timeObj->format('y-MM-dd HH:mm:ss'),
                    'MemberID' => $memberID,
                    'Status' => $loginAttemptInspector->Status,
                    'IP' => $loginAttemptInspector->IPAddress,
                    'UserAgent' => $loginAttemptInspector->UserAgent,
                ];

                $this->extend('updatePrepareLoginAttempts', $loginAttemptData);

                $arrayList->push(LoginAttempt::create($loginAttemptData));
            }
        }

        $this->loginAttempts = $arrayList;
    }

    public function prepareCurrentAssessment(): void
    {
        $time = DBDatetime::now()->Rfc2822();
        $this->prepareRequestLog($time);
        $this->prepareSessionLog($time);
        $this->prepareLoginAttempt($time);
        $this->prepareRequestLogs($time);
        $this->prepareLoginAttempts($time);

        $this->extend('updatePrepareCurrentAssessment', $this, $time);
    }

    /**
     *  For bulk csv import, column is value of rule titles within the cell
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
    public function importRule(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['Rule']) {
            return;
        }

        $csv = trim($csv);

        $rules = Rule::get()->filter('Title', $csv);

        if (!$rules || !$rules->exists()) {
            return;
        }

        $this->RuleID = $rules->first()->ID;
    }

    /**
     * For bulk csv import, column is comma separated list within the cell of request log inspector values
     * seperated by a '|'
     * within the cell. The order of the values is as follows:
     * (INT)TimeOffset|URL|Verb|IPAddress|UserAgent
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function importRequestLogInspectors(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RequestLogInspectors']) {
            return;
        }

        // Removes all relationships with IP Rules
        $inspectors = $this->owner->RequestLogInspectors();

        if ($inspectors) {
            foreach ($inspectors as $inspector) {
                $inspector->delete();
            }
        }

        foreach (explode(',', trim($csv) ?? '') as $identifierstr) {
            if (!strpos($identifierstr, '|')) {
                continue;
            }

            $identifier = explode('|', trim($identifierstr));

            $data = [
                'TimeOffset' => $identifier[0],
                'URL' => $identifier[1],
                'Verb' => $identifier[2],
                'IPAddress' => $identifier[3],
                'UserAgent' => $identifier[4],
            ];

            $requestLogInspector = RequestLogInspector::create($data);

            $requestLogInspector->write();

            $this->RequestLogInspectors()->add($requestLogInspector);
        }
    }

    /**
     * For bulk csv import, column is comma separated list within the cell of login attempt inspector values
     *  seperated by a '|'
     *  within the cell. The order of the values is as follows:
     *  (INT)TimeOffset|Status|IPAddress|UserAgent
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function importLoginAttemptInspectors(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['LoginAttemptInspectors']) {
            return;
        }

        // Removes all relationships with IP Rules
        $inspectors = $this->owner->LoginAttemptInspectors();

        if ($inspectors) {
            foreach ($inspectors as $inspectors) {
                $inspectors->delete();
            }
        }

        foreach (explode(',', trim($csv) ?? '') as $identifierstr) {
            if (!strpos($identifierstr, '|')) {
                continue;
            }

            $identifier = explode('|', trim($identifierstr));

            $data = [
                'TimeOffset' => $identifier[0],
                'Status' => $identifier[1],
                'IPAddress' => $identifier[2],
                'UserAgent' => $identifier[3],
            ];

            $loginAttemptInspector = LoginAttemptInspector::create($data);

            $loginAttemptInspector->write();

            $this->LoginAttemptInspectors()->add($loginAttemptInspector);
        }
    }

    /**
     * For bulk csv import, column value is ID of a member
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
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
