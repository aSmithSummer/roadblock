<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RequestLogTest extends DataObject
{

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

    private static array $db = [
        'TimeOffset' => 'Int',
        'URL' => 'Text',
        'Verb' => "Enum('POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD')",
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'RoadblockRuleInspector' => RoadblockRuleInspector::class,
    ];

    private static string $table_name = 'RequestLogTest';

    private static string $plural_name = 'Test requests';

    private static array $summary_fields = [
        'TimeOffset' => 'TimeOffset',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IPAddress',
    ];

    private static string $default_sort = 'Created DESC';

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }


}
