<?php

namespace aSmithSummer\Roadblock\Model;

use ReflectionClass;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

class RequestLogTest extends DataObject
{

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    public static array $verbs = [
        'POST' => 'POST',
        'GET' => 'GET',
        'DELETE' => 'DELETE',
        'CONNECT' => 'CONNECT',
        'OPTIONS' => 'OPTIONS',
        'TRACE' => 'TRACE',
        'PATCH' => 'PATCH',
        'HEAD' => 'HEAD',
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $db = [
        'TimeOffset' => 'Int',
        'URL' => 'Text',
        'Status' => 'Varchar(8)',
        'Verb' => "Enum('POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD')",
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'RoadblockRuleInspector' => RoadblockRuleInspector::class,
    ];

    private static string $table_name = 'RequestLogTest';

    private static string $plural_name = 'Test requests';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'TimeOffset' => 'TimeOffset',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IPAddress',
    ];

    private static string $default_sort = 'Created DESC';

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('StatusCode');

        $response = new ReflectionClass(HTTPResponse::class);
        $options = $response->getStaticPropertyValue('status_codes');

        $statusCode = DropdownField::create('StatusCode', 'Status code', $options)
            ->setHasEmptyDefault(true)->setEmptyString('(none)');
        $fields->insertAfter('IPAddress', $statusCode);

        return $fields;
    }

}
