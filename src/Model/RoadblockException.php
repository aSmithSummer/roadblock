<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Silverstripe\ORM\ArrayList;

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
        'Type' => "Enum('Admin,Dev,API,File,Personal,Registration,Export,General,Staff,Bad','General)",
    ];

    private static $has_one = [
        'RoadblockRule' => RoadblockRule::class,
        'Roadblock' => Roadblock::class,
    ];

    private static string $table_name = 'RoadblockException';

    private static array $summary_fields = [
        'RoadblockRule.Title' => 'Rule',
        'URL' => 'URL',
        'Verb' => 'Verb',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'Type' => 'Type',
    ];

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
