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
class RoadblockURLRules extends DataObject
{

    private static array $db = [
        'Type' => "Enum('Any,Admin,Dev,API,File,Personal,Registration,Export,Staff,Bad','Any')",
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];


    private static string $table_name = 'RoadblockURLRules';


    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
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
