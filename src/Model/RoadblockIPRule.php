<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockIPRule extends DataObject
{

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'Description' => 'Varchar(250)',
        'Permission' => "Enum('Allowed,Denied','Allowed')",
        'IPAddress' => 'Varchar(16)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockIPRule';

    private static string $plural_name = 'IP Addresses';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $indexes = [
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueCombination' => [
            'type' => 'unique',
            'columns' => ['Permission','IPAddress'],
        ],
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
        'RoadblockRequestTypes' => RoadblockRequestType::class,
    ];


    function Title() {
        return $this->IPAddress . ' - ' . $this->Permission;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Permission) {
            $result->addError(_t(self::class . '.FROM_VALIDATION', 'IPAddress is required.'));
        }

        return $result;
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
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        $fields = [
            'Description' => 'Description',
            'IPAddress' => 'IPAddress',
            'Permission' => 'Permission',
            'Status' => 'Status',
            'getRoadblockRequestTypesCSV' => 'RoadblockRequestTypes',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getRoadblockRequestTypesCSV(): string
    {
        $responseArray = [];

        foreach ($this->RoadblockRequestTypes() as $obj) {
            $responseArray[] = $obj->Title;
        }

        return implode(',', $responseArray);
    }

    public function importRoadblockRequestTypes(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RoadblockRequestTypes']) {
            return;
        }

        // Removes all relationships with request type
        $this->RoadblockRequestTypes()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifier) {
            $filter = ['Title' => $identifier];
            $roadblockRequestTypes = RoadblockRequestType::get()->filter($filter);

            if (!$roadblockRequestTypes) {
                continue;
            }

            foreach ($roadblockRequestTypes as $roadblockRequestType) {
                $this->RoadblockRequestTypes()->add($roadblockRequestType);
            }
        }
    }

}
