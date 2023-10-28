<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockURLRule extends DataObject
{

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'Title' => 'Varchar(64)',
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
        'Order' => 'Int',
    ];

    private static string $table_name = 'RoadblockURLRule';

    private static string $plural_name = 'URL Rules';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $indexes = [
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
        'Order' => true,
    ];

    private static string $default_sort = 'Order';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'Title' => 'Title',
        'Pregmatch' => 'Rule',
        'Status' => 'Status',
        'RoadblockRequestType.Title' => 'Type',
    ];

    private static array $has_one = [
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Pregmatch) {
            $result->addError(_t(self::class . '.FROM_VALIDATION', 'Pregmatch is required.'));
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
            'Title' => 'Title',
            'Pregmatch' => 'Pregmatch',
            'Status' => 'Status',
            'RoadblockRequestType.Title' => 'RoadblockRequestType.Title',
            'Order' => 'Order',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public static function getURLType(string $url): int
    {
        $urlRules = self::get()->filter(['Status' => 'Enabled']);

        if ($urlRules) {
            foreach ($urlRules as $urlRule) {
                if (preg_match($urlRule->Pregmatch, $url)) {
                    return $urlRule->RoadblockRequestTypeID;
                }
            }
        }

        return 0;
    }

}
