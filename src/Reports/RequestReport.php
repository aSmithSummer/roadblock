<?php

namespace aSmithSummer\Roadblock\Reports;

use aSmithSummer\Roadblock\Model\RequestLog;
use aSmithSummer\Roadblock\Model\RequestType;
use ReflectionClass;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Security\Member;

class RequestReport extends Report
{

    public function title(): string
    {
        return 'Requests Report';
    }

    public function description(): string
    {
        return 'Site request log';
    }

    public function parameterFields(): FieldList
    {
        $requestTypes = RequestType::get()->map('Title', 'Title');
        $memberFieldOptions = Member::get()->map('ID', 'getName');

        $response = new ReflectionClass(HTTPResponse::class);
        $options = $response->getStaticPropertyValue('status_codes');

        return FieldList::create([
            DateField::create('DateFrom', 'Date from'),
            DateField::create('DateTo', 'Date to'),
            TextField::create('IPAddress', 'IP address'),
            DropdownField::create('Member', 'Member name', $memberFieldOptions)
                ->setHasEmptyDefault(true)->setEmptyString('(Any)'),
            TextField::create('SessionAlias', 'Session alias'),
            TextField::create('URL', 'URL'),
            DropdownField::create('Verb', 'Verb', RequestLog::$verbs)
                ->setHasEmptyDefault(true)->setEmptyString('(Any)'),
            DropdownField::create('StatusCode', 'StatusCode', $options)
                ->setHasEmptyDefault(true)->setEmptyString('(Any)'),
            DropdownField::create('Type', 'Request type', $requestTypes)
                ->setHasEmptyDefault(true)->setEmptyString('(Any)'),
        ]);
    }

    public function sourceRecords(?array $params = []): DataList
    {
        $filter = [];

        if (isset($params['DateFrom'])) {
            $filter['Created:GreaterThan'] = DBDatetime::create()
                ->modify($params['DateFrom'])
                ->format('y-MM-dd HH:mm:ss');
        }

        if (isset($params['DateTo'])) {
            $filter['Created:LessThan'] = DBDatetime::create()
                ->modify($params['DateTo'])
                ->format('y-MM-dd HH:mm:ss');
        }

        if (isset($params['IPAddress'])) {
            $filter['IPAddress'] = $params['IPAddress'];
        }

        if (isset($params['Member'])) {
            $filter['SessionLog.Member.ID'] = $params['Member'];
        }

        if (isset($params['SessionAlias'])) {
            $filter['SessionLog.SessionAlias'] = $params['SessionAlias'];
        }

        if (isset($params['URL'])) {
            $filter['URL:PartialMatch'] = $params['URL'];
        }

        if (isset($params['Verb']) && $params['Verb']) {
            $filter['Verb'] = $params['Verb'];
        }

        if (isset($params['StatusCode']) && $params['StatusCode']) {
            $filter['StatusCode'] = $params['StatusCode'];
        }

        if (isset($params['Type']) && $params['Type']) {
            $filter['Types:PartialMatch'] = $params['Type'];
        }

        return RequestLog::get()->filter($filter)->sort('Created', 'DESC');
    }

    public function columns(): array
    {

        return [
            'Created' => 'Created',
            'SessionLog.Member.getName' => 'Name',
            'SessionLog.SessionAlias' => 'Session',
            'IPAddress' => 'IP Address',
            'FriendlyUserAgent' => 'User Agent',
            'URL' => 'URL',
            'Verb' => 'Verb',
            'StatusCode' => 'StatusCode',
            'Types' => 'Types',
        ];
    }

}
