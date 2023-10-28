<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

//phpcs:ignore SlevomatCodingStandard.Classes.SuperfluousExceptionNaming.SuperfluousSuffix
class RoadblockException extends DataObject
{

    use UseragentNiceTrait;

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'Description' => 'Text',
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $has_one = [
        'RoadblockRule' => RoadblockRule::class,
        'Roadblock' => Roadblock::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static string $table_name = 'RoadblockException';

    private static string $plural_name = 'Exceptions';
   //phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'Created' => 'Created',
        'RoadblockRule.Title' => 'Rule',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'RoadblockRequestType.Title' => 'Type',
    ];

    private static string $default_sort = 'Created DESC';

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return false;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return false;
    }

}
