<?php

namespace Roadblock\Reports;

use Roadblock\Model\Roadblock;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;

class RoadblockReport extends Report
{

    public function title(): string
    {
        return 'Roadblocks Report';
    }

    public function description(): string
    {
        $description = 'Automated IP blocks report';

        return $description;
    }

    public function parameterFields(): FieldList
    {
        return FieldList::create([
            DateField::create('DateFrom', 'Date from'),
            DateField::create('DateTo', 'Date to'),
            TextField::create('IPAddress', 'IP address'),
            TextField::create('MemberName', 'Member name'),
            TextField::create('SessionAlias', 'Session alias'),
            CheckboxField::create('BlockedOnly', 'Blocked only')->addExtraClass('column-field'),
            CheckboxField::create('AdminOverride', 'Admin override')->addExtraClass('column-field'),
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
            $filter['MemberName'] = $params['MemberName'];
        }

        if (isset($params['SessionAlias'])) {
            $filter['SessionAlias'] = $params['SessionAlias'];
        }

        if (isset($params['BlockedOnly'])) {
            $filter['Score:GreaterThanOrEqual'] = Roadblock::$threshold;
            $filter['AdminOverride:Not'] = 1;
        }

        if (isset($params['AdminOverride'])) {
            $filter['AdminOverride'] = 1;
        }

        return Roadblock::get()->filter($filter)->sort('Created', 'DESC');
    }

    public function columns(): array
    {
        return [
            'Created' => 'Created',
            'MemberName' => 'Name',
            'SessionAlias' => 'Session',
            'IPAddress' => 'IP Address',
            'LastAccessed' => 'DBDatetime',
            'FriendlyUserAgent' => 'User Agent',
            'LastAccessed.Nice' => 'Last accessed',
            'Expiry.Nice' => 'Expiry',
            'Score' => 'Score',
            'AdminOverride.Nice' => 'Admin override',
            'CycleCount' => 'Cycles',
        ];
    }

}
