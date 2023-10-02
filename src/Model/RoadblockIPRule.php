<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Silverstripe\ORM\ArrayList;

/**
 * Tracks a session.
 */
class RoadblockIPRule extends DataObject
{

    private static array $db = [
        'Description' => 'Varchar(250)',
        'Permission' => "Enum('Allowed,Denied','Allowed')",
        'IPAddress' => 'Varchar(16)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockIPRule';

    private static array $summary_fields = [
        'Permission' => 'Permission',
        'IPAddress' => 'IP Address',
        'Status' => 'Status',
        'Description' => 'Description',
    ];

    private static array $belongs_many_many = [
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
}
