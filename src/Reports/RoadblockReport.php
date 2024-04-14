<?php

namespace aSmithSummer\Roadblock\Reports;

use aSmithSummer\Roadblock\Model\Roadblock;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Security\Member;

class RoadblockReport extends Report
{

    public function title(): string
    {
        return 'Roadblocks Report';
    }

    public function description(): string
    {
        return 'Automated IP blocks report';
    }

    public function parameterFields(): FieldList
    {
        $memberNames = Roadblock::get()
            ->distinct(true)
            ->where('MemberName is not null')
            ->map('MemberName', 'MemberName');

        return FieldList::create([
            DateField::create('DateFrom', 'Date from'),
            DateField::create('DateTo', 'Date to'),
            TextField::create('IPAddress', 'IP address'),
            DropdownField::create('MemberName', 'Member name', $memberNames)
                ->setHasEmptyDefault(true)->setEmptyString('(Any)'),
            TextField::create('SessionAlias', 'Session alias'),
            CheckboxField::create('BlockedOnly', 'Blocked only')->addExtraClass('column-field'),
            CheckboxField::create('AdminOverride', 'Admin override')->addExtraClass('column-field'),
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
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
