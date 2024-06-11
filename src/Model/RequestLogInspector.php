<?php

namespace aSmithSummer\Roadblock\Model;

use ReflectionClass;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

class RequestLogInspector extends DataObject
{


    private static array $db = [
        'TimeOffset' => 'Int',
        'URL' => 'Text',
        'StatusCode' => 'Varchar(8)',
        'Verb' => "Enum('POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD')",
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'RuleInspector' => RuleInspector::class,
    ];

    private static string $table_name = 'RequestLogInspector';

    private static string $plural_name = 'Test requests';

    private static array $summary_fields = [
        'TimeOffset' => 'TimeOffset',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IPAddress',
    ];

    private static string $default_sort = 'Created DESC';

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
