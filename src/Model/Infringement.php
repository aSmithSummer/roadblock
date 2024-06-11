<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

//phpcs:ignore SlevomatCodingStandard.Classes.SuperfluousExceptionNaming.SuperfluousSuffix
class Infringement extends DataObject
{

    use UseragentNiceTrait;


    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'StatusCode' => 'Varchar(8)',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'Description' => 'Text',
        'Types' => 'Varchar(512)',
    ];

    private static array $has_one = [
        'Rule' => Rule::class,
        'Roadblock' => Roadblock::class,
    ];

    private static string $table_name = 'Infringement';

    private static string $plural_name = 'Exceptions';

    private static array $summary_fields = [
        'Created' => 'Created',
        'Rule.Title' => 'Rule',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'StatusCode' => 'StatusCode',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'Types' => 'Types',
    ];

    private static string $default_sort = 'Created DESC';

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return false;
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
