<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockRequestType extends DataObject
{

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'Title' => 'Varchar(64)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockRequestType';

    private static string $plural_name = 'Request Types';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $indexes = [
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'Title' => 'Title',
        'Status' => 'Status',
    ];

    private static string $default_sort = 'Title';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $has_many = [
        'RoadblockURLRules' => RoadblockURLRule::class,
        'RoadblockRules' => RoadblockRule::class,
        'RequestLogs' => RequestLog::class,
    ];

    private static array $many_many = [
        'RoadblockIPRules' => RoadblockIPRule::class,
    ];

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        $defaultRecords = $this->config()->uninherited('default_records');

        if (empty($defaultRecords)) {
            return;
        }

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
                    'IPAddress' => $ipAddress['IPAddress'],
                    'Permission' => $ipAddress['Permission'],
                ])->first();

                if (!$ipObj) {
                    $ipObj = Injector::inst()->create(RoadblockIPRule::class, $ipAddress);
                }

                $obj->RoadblockIPRules()->add($ipObj);
            }
        }

        DB::alteration_message("Added default records to $className table", 'created');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function getExportFields(): array
    {
        $fields = [
            'getRoadblockIPRulesCSV' => 'RoadblockIPRules',
            'getRoadblockRulesCSV' => 'RoadblockRules',
            'getRoadblockURLRulesCSV' => 'RoadblockURLRules',
            'Status' => 'Status',
            'Title' => 'Title',
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
            if (!strpos($identifierstr, '|')) {
                continue;
            }

            $identifier = explode('|', trim($identifierstr));
            $filter = ['Permission' => $identifier[0], 'IPAddress' => $identifier[1]];
            $ipRule = RoadblockIPRule::get()->filter($filter);

            if (!$ipRule) {
                continue;
            }

            $this->RoadblockIPRules()->add($ipRule->first());
        }
    }

}
