<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RequestType extends DataObject
{


    private static array $db = [
        'Title' => 'Varchar(64)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RequestType';

    private static string $plural_name = 'Request Types';

    private static array $indexes = [

        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'Status' => 'Status',
    ];

    private static string $default_sort = 'Title';

    private static array $has_many = [
        'URLRules' => URLRule::class,
    ];

    private static array $many_many = [
        'IPRules' => IPRule::class,
    ];

    private static array $belongs_many_many = [
        'Rules' => Rule::class,
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
            if (empty($record['IPRules'])) {
                continue;
            }

            $ipNumber = IPRule::ipToNumeric($ipAddress['IPAddress']);
            
            foreach ($record['IPRules'] as $ipAddress) {
                $ipObj = IPRule::get()->filter([
                    'FromIPNumber:LessThanOrEqual' => $ipNumber,
                    'ToIPNumber:GreaterThanOrEqual' => $ipNumber,
                    'Permission' => $ipAddress['Permission'],
                ])->first();

                if (!$ipObj) {
                    $ipObj = Injector::inst()->create(IPRule::class, $ipAddress);
                }

                $obj->IPRules()->add($ipObj);
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
            'Title' => 'Title',
            'Status' => 'Status',
            'getIPRulesForCSV' => 'RIPRules',
            'getRulesForCSV' => 'Rules',
            'getURLRulesForCSV' => 'URLRules',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getURLRulesForCSV(): string
    {
        return implode(',', $this->URLRules()->column('Title'));
    }

    public function getRulesForCSV(): string
    {
        return implode(',', $this->Rules()->column('Title'));
    }

    public function getIPRulesForCSV(): string
    {
        $responseArray = [];

        foreach ($this->IPRules() as $obj) {
            $responseArray[] = $obj->Permission . '|' . $obj->FromIPNumber . '|' . $obj->ToIPNumber;
        }

        return implode(',', $responseArray);
    }

    /**
     * For bulk csv import, column is comma separated list of rule titles within the cell
     *
     * @param string $titles
     * @param array $csvRow
     * @return void
     */
    public function importRules(string $titles, array $csvRow): void
    {
        if (!$titles || $titles !== $csvRow['Rules']) {
            return;
        }

        // Removes all Advisor Codes and Branches relationships with Member
        $this->Rules()->removeAll();

        $rules = Rule::get()->filter('Title', explode(',', trim($titles)));

        foreach ($rules as $rule) {
            $this->Rules()->add($rule);
        }
    }

    /**
     *  For bulk csv import, column is comma separated list of URL rules' titles within the cell
     *
     * @param string $titles
     * @param array $csvRow
     * @return void
     */
    public function importURLRules(string $titles, array $csvRow): void
    {
        if (!$titles || $titles !== $csvRow['URLRules']) {
            return;
        }

        // Removes all Advisor Codes and Branches relationships with Member
        $this->URLRules()->removeAll();

        $urlRules = URLRule::get()->filter('Title', explode(',', trim($titles)));

        foreach ($urlRules as $urlRule) {
            $this->URLRules()->add($urlRule);
        }
    }

    /**
     *  For bulk csv import, column is comma separated list of IP Rule Permission '|' IPAddress within the cell
     * eg "Admin|127.0.0.1,Admin|123.123.123.123"
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
    public function importIPRules(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['IPRules']) {
            return;
        }

        // Removes all relationships with IP Rules
        $this->IPRules()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifierstr) {
            if (!strpos($identifierstr, '|')) {
                continue;
            }

            $identifier = explode('|', trim($identifierstr));
            $filter = [
                'Permission' => $identifier[0],
                'FromIPNumber' => $identifier[1],
                'ToIPNumber' => $identifier[2],
            ];

            $ipRules = IPRule::get()->filter($filter);

            if (!$ipRules || !$ipRules->exists()) {
                continue;
            }

            $this->IPRules()->add($ipRules->first());
        }
    }

}
