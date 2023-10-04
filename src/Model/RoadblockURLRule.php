<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockURLRule extends DataObject
{

    private static array $db = [
        'Title' => 'Varchar(64)',
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockURLRule';

    private static array $summary_fields = [
        'Title' => 'Title',
        'Pregmatch' => 'Rule',
        'Status' => 'Status',
        'RoadblockRequestType.Title' => 'Type',
    ];

    private static array $has_one = [
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
