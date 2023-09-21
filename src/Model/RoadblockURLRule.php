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
class RoadblockURLRule extends DataObject
{

    private static array $db = [
        'Type' => "Enum('Any,Admin,Dev,API,File,Personal,Registration,Export,Staff,Bad','Any')",
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'RoadblockURLRule';

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

    public static function getURLType(string $url): string
    {
        $rules = self::get()->filter(['Status' => 'Enabled']);

        if ($rules) {
            foreach ($rules as $rule) {
                if (preg_match($rule->Pregmatch, $url)) {
                    return $rule->Type;
                }
            }
        }
        return '';
    }
}
