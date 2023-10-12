<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockException extends DataObject
{
    use UseragentNiceTrait;

    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static $has_one = [
        'RoadblockRule' => RoadblockRule::class,
        'Roadblock' => Roadblock::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static string $table_name = 'RoadblockException';

    private static string $plural_name = 'Exceptions';

    private static array $summary_fields = [
        'Created.Nice',
        'RoadblockRule.Title' => 'Rule',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'RoadblockRequestType.Title' => 'Type',
    ];

    private static string $default_sort = 'Created DESC';

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

}
