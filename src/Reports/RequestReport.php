<?php

namespace Roadblock\Reports;

use Roadblock\Model\RequestLog;
use Roadblock\Model\RoadblockRequestType;
use Roadblock\Model\SessionLog;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;

class RequestReport extends Report
{

    public function title(): string
    {
        return 'Requests Report';
    }

    public function description(): string
    {
        $description = 'Site request log';

        return $description;
    }

    public function parameterFields(): FieldList
    {
        $requestTyoes = RoadblockRequestType::get()->map('ID', 'Title');

        return FieldList::create([
            DateField::create('DateFrom', 'Date from'),
            DateField::create('DateTo', 'Date to'),
            TextField::create('IPAddress', 'IP address'),
            TextField::create('MemberName', 'Member name'),
            TextField::create('SessionAlias', 'Session alias'),
            TextField::create('URL', 'URL'),
            DropdownField::create('Verb', 'Verb', RequestLog::$verbs),
            DropdownField::create('Type', 'Request type', $requestTypes),
        ]);
    }

    public function sourceRecords(?array $params = []): ArrayList
    {
        $fitler = [];

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

        if (isset($params['MemberName'])) {
            $filter['SessionLog.MemberName'] = $params['MemberName'];
        }

        if (isset($params['SessionAlias'])) {
            $filter['SessionLog.SessionAlias'] = $params['SessionAlias'];
        }

        if (isset($params['URL'])) {
            $filter['URL'] = $params['URL'];
        }

        if (isset($params['Verb'])) {
            $filter['Verb'] = 1;
        }

        if (isset($params['Type'])) {
            $filter['Type.ID'] = $params['Type'];
        }

        return RequestLog::get()->filter($filter)->sort('Created', 'DESC');
    }

    public function columns(): array
    {
        return [
            'Created' => 'Created',
            'SessionLog.MemberName' => 'Name',
            'SessionLog.SessionAlias' => 'Session',
            'IPAddress' => 'IP Address',
            'FriendlyUserAgent' => 'User Agent',
            'URL' => 'URL',
            'Verb' => 'Verb',
            'RoadblockRequestType.Title' => 'Type',
        ];
    }

}
