<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

class LoginAttemptInspector extends DataObject
{


    private static array $db = [
        'TimeOffset' => 'Int',
        'Status' => "Enum('Success,Failed')",
        'IPAddress' => 'Varchar(255)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'RuleInspector' => RuleInspector::class,
    ];

    private static string $table_name = 'LoginAttemptInspector';

    private static string $plural_name = 'Test logins';

    private static array $summary_fields = [
        'TimeOffset' => 'TimeOffset',
        'Status' => 'URL',
    ];

    private static string $default_sort = 'Created DESC';

}
