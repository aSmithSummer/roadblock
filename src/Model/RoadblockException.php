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
class RoadblockException extends DataObject
{

    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'IPAddress' => 'Varchar(11)',
        'Country' => 'Varchar(2)',
        'UserAgent' => 'Text',
        'Type' => "Enum('Admin,Dev,API,File,Personal,Registration,Export,General,Staff,Bad','General)",
    ];

    private static $belongs_to = [
        'Roadblock' => Roadblock::class,
    ];

    private static $has_many = [
        'URLRules' => RoadblockURLRule::class,
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static string $table_name = 'RoadblockException';


    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return false;
    }

}
