<?php

namespace Roadblock\Model;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
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

    private static string $default_sort = 'Title';

    private static array $has_many = [
        'RoadblockURLRules' => RoadblockURLRule::class,
        'RoadblockRules' => RoadblockRule::class,
        'RequestLogs' => RequestLog::class,
    ];

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        $defaultRecords = $this->config()->uninherited('default_records');

        if (!empty($defaultRecords)) {
            $className = static::class;
            foreach ($defaultRecords as $record) {
                $obj = self::get()->filter([
                    'Title' => $record['Title'],
                ])->first();

                if ($obj) {
                    continue;
                }
                $obj = Injector::inst()->create($className, $record);
                $obj->write();

                // Add IP addresses
                if (empty($record['RoadblockIPRules'])) {
                    continue;
                }

                foreach ($record['RoadblockIPRules'] as $ipAddress) {
                    $ipObj = RoadblockIPRule::get()->filter([
                        'Permission' => $ipAddress['Permission'],
                        'IPAddress' => $ipAddress['IPAddress'],
                    ])->first();
                    
                    if (!$ipObj) {
                        $ipObj = Injector::inst()->create(RoadblockIPRule::class, $ipAddress);
                    }

                    $obj->RoadblockIPRules()->add($ipObj);
                }
            }
            DB::alteration_message("Added default records to $className table", "created");
        }
    }

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

        return implode(',', $responseArray);
    }

    public function getRoadblockRulesCSV(): string
    {
        $responseArray = $this->RoadblockRules()->column('Title');

        return implode(',', $responseArray);
    }

    public function getRoadblockIPRulesCSV(): string
    {
        $responseArray = [];

        foreach ($this->RoadblockIPRules() as $obj) {
            $responseArray[] = $obj->Permission . '|' . $obj->IPAddress;
        }

        return implode(',', $responseArray);
    }

    public function importRoadblockRules(string $titles, array $csvRow): void
    {
        if ($titles !== $csvRow['RoadblockRules']) {
            return;
        }

        // Removes all Advisor Codes and Branches relationships with Member
        $this->owner->RoadblockRules()->removeAll();

        $rules = RoadblockRule::get()->filter('Title', explode(',', trim($titles)));

        foreach ($rules as $rule) {
            $this->RoadblockRules()->add($rule);
        }
    }

    public function importRoadblockURLRules(string $titles, array $csvRow): void
    {
        if ($titles !== $csvRow['RoadblockURLRules']) {
            return;
        }

        // Removes all Advisor Codes and Branches relationships with Member
        $this->owner->RoadblockURLRules()->removeAll();

        $urlRules = RoadblockURLRule::get()->filter('Title', explode(',', trim($titles)));

        foreach ($urlRules as $urlRule) {
            $this->RoadblockURLRules()->add($urlRule);
        }
    }

    public function importRoadblockIPRules(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RoadblockIPRules']) {
            return;
        }

        // Removes all Advisor Codes and Branches relationships with Member
        $this->owner->RoadblockIPRules()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifierstr) {
            if (strpos($identifierstr, '|')) {
                $identifier = explode('|', trim($identifierstr));
                $filter = ['Permission' => $identifier[0], 'IPAddress' => $identifier[1]];
                $ipRule = RoadblockIPRule::get()->filter($filter);

                if ($ipRule) {
                    $this->RoadblockIPRules()->add($ipRule->first());
                }
            }
        }
    }

}
