<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockRequestType extends DataObject
{

    private static array $db = [
        'Title' => 'Varchar(64)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockRequestType';

    private static string $plural_name = 'Request Types';

    private static array $indexes = [
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ]
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'Status' => 'Status',
    ];

    private static array $has_many = [
        'RoadblockURLRules' => RoadblockURLRule::class,
        'RoadblockRules' => RoadblockRule::class,
        'RequestLogs' => RequestLog::class,
    ];

    private static array $many_many = [
        'RoadblockIPRules' => RoadblockIPRule::class,
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function getExportFields(): array
    {
        $fields =  [
            'Title' => 'Title',
            'Status' => 'Status',
            'getRoadblockURLRulesCSV' => 'RoadblockURLRules',
            'getRoadblockRulesCSV' => 'RoadblockRules',
            'getRoadblockIPRulesCSV' => 'RoadblockIPRules',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getRoadblockURLRulesCSV(): string
    {
        $responseArray = $this->RoadblockURLRules()->column('Title');

        $response = implode(',', $responseArray);

        return $response;
    }

    public function getRoadblockRulesCSV(): string
    {
        $responseArray = $this->RoadblockRules()->column('Title');

        $response = implode(',', $responseArray);

        return $response;
    }

    public function getRoadblockIPRulesCSV(): string
    {
        $responseArray = [];

        foreach ($this->RoadblockIPRules() as $obj) {
            $responseArray[] = $obj->Permission . '|' . $obj->IPAddress;
        }
        
        $response = implode(',', $responseArray);

        return $response;
    }

}
