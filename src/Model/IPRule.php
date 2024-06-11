<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class IPRule extends DataObject
{


    private static array $db = [
        'Description' => 'Varchar(250)',
        'Permission' => "Enum('Allowed,Denied','Allowed')",
        'IPAddress' => 'Varchar(16)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'IPRule';

    private static string $plural_name = 'IP Addresses';

    private static array $indexes = [

        'UniqueCombination' => [
            'type' => 'unique',
            'columns' => ['Permission','IPAddress'],
        ],
    ];

    private static array $summary_fields = [
        'Permission' => 'Permission',
        'IPAddress' => 'IP Address',
        'Status' => 'Status',
        'Description' => 'Description',
    ];

    private static array $searchable_fields = [
        'Description',
        'Permission',
        'IPAddress',
        'Status',
    ];

    private static string $default_sort = 'IPAddress';

    private static array $belongs_many_many = [
        'RequestTypes' => RequestType::class,
    ];


    function Title() {
        return $this->IPAddress . ' - ' . $this->Permission;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Permission) {
            $result->addError(_t(self::class . '.FROM_VALIDATION', 'IP Address is required.'));
        }

        return $result;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }

    public function getExportFields(): array
    {

        $fields = [
            'Description' => 'Description',
            'IPAddress' => 'IPAddress',
            'Permission' => 'Permission',
            'Status' => 'Status',
            'getRequestTypesForCSV' => 'RequestTypes',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getRequestTypesForCSV(): string
    {
        return implode(',', $this->RequestTypes()->column('Title'));
    }

    /**
     *  For bulk csv import, column is comma separated list of request type titles within the cell
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
    public function importRequestTypes(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RequestTypes']) {
            return;
        }

        // Removes all relationships with request type
        $this->RequestTypes()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifier) {
            $filter = ['Title' => $identifier];
            $requestTypes = RequestType::get()->filter($filter);

            if (!$requestTypes) {
                continue;
            }

            foreach ($requestTypes as $requestType) {
                $this->RequestTypes()->add($requestType);
            }
        }
    }

}
