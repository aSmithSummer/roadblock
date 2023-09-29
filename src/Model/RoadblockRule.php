<?php

namespace Roadblock\Model;

use App\Extensions\SiteConfigExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Silverstripe\ORM\ArrayList;
use SilverStripe\Security\Security;

/**
 * Tracks a session.
 */
class RoadblockRule extends DataObject
{

    private static array $db = [
        'Title' => 'Varchar(32)',
        'Level' => "Enum('Member,Session','Session')",
        'LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
        'LoginAttemptsNumber' => 'Int',
        'LoginAttemptsStartOffset' => 'Int',
        'TypeCount' => 'Int',
        'TypeStartOffset' => 'Int',
        'Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
        'VerbCount' => 'Int',
        'VerbStartOffset' => 'Int',
        'IPAddress' => "Enum('Any,Allowed,Denied','Any)",
        'IPAddressNumber' => 'Int',
        'IPAddressOffset' => 'Int',
        'ExcludeGroup' => "Boolean",
        'PermissionAllowOrDeny' => "Boolean",
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    /*
     *
        'Age' => "Enum('Any,Under18,Over65','Any')",
        'Country' => "Enum('Any,NZ,Overseas','Any')",
        'TrustedDevicesCount' => 'Int',
     */

    private static $has_one = [
        'Group' => Group::class,
        'Permission' => Permission::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static $belongs_many_many = [
        'Roadblock' => Roadblock::class,
    ];

    private static string $table_name = 'RoadblockRule';

    private static array $summary_fields = [
        'Title' => 'Title',
        'Level' => 'Level',
        'LoginAttemptsStatus' => 'LoginAttemptsStatus',
        'RoadblockRequestType.Title' => 'Type',
        'Verb' => 'Verb',
        'Score' => 'Score',
        'Cumulative' => 'Cumulative',
        'Status' => 'Status',
    ];
    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
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
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $request, RoadblockRule $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            return true;
        }

        $member = Security::getCurrentUser();

        if ($rule->Level === 'Member') {
            if (!$member) {
                return true;
            }

            /*
            $age = $member->calculateCurrentAge();

            if (self::Age === 'Under18' && $age >= 18) {
                return true;
            }

            if (self::Age === 'Over65' && $age < 65) {
                return true;
            }
            */

            if ($rule->LoginAttemptsNumber) {
                $time = DBDatetime::now()->modify('+' . $rule->LoginAttemptsStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
                $filter = [
                    'MemberID' => $member->ID,
                    'Created:GreaterThan' => $time,
                ];

                if ($rule->LoginAttemptStatus !== 'Any') {
                    $filter['Status'] = $rule->LoginAttemptStatus;
                }
                $logins = LoginAttempt::get()->filter($filter);

                if (!$logins) {
                    return true;
                }

                if ($logins->count() <= $rule->LoginAttemptsNumber) {
                    return true;
                }
            }
        }

        /*
        if (self::Country === 'NZ' && $request->Country !== 'NZ') {
            return true;
        }

        if (self::Country === 'Overseas' && $request->Country === 'NZ') {
            return true;
        }
        */
        $type = $rule->RoadblockRequestType();

        if ($type) {
            //
            $time = DBDatetime::create()
                ->modify($sessionLog->LastAccessed)
                ->modify('-' . $rule->TypeStartOffset . ' seconds')
                ->format('y-MM-dd HH:mm:ss');
            $filter = [
                'SessionLogID' => $sessionLog->ID,
                'Created:GreaterThanOrEqual' => $time,
                'RoadblockRequestTypeID' => $rule->RoadblockRequestTypeID,
            ];

            $requests = RequestLog::get()->filter($filter);

            if (!$requests->exists()) {
                return true;
            }

            if ($requests->count() <= $rule->TypeCount) {
                return true;
            }
        }

        if ($rule->Verb !== 'Any') {
            $time = DBDatetime::now()->modify('-' . $rule->VerbStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
            $filter = [
                'SessionLogID' => $sessionLog->ID,
                'Created:GreaterThan' => $time,
                'Verb' => $rule->Verb,
            ];

            $requests = RequestLog::get()->filter($filter);

            if (!$requests) {
                return true;
            }

            if ($requests->count() <= $rule->VerbCount) {
                return true;
            }
        }

        $group = $rule->Group();

        if ($group) {
            if ($rule->ExcludeGroup && (!$member || $member->inGroup($group))) {
                return true;
            }

            if (!$rule->ExcludeGroup && !$member->inGroup($group)){
                return true;
            }
        }

        if ($rule->IPAddress !== 'Any') {
            $time = DBDatetime::create()
                ->modify($sessionLog->LastAccessed)
                ->modify('-' . $rule->TypeStartOffset . ' seconds')
                ->format('y-MM-dd HH:mm:ss');

            $permission = $rule->IPAddress === 'Allowed' ? 'Allowed' : 'Denied';

            $ipAddresses = $rule
                ->RoadblockRequestType()
                ->RoadblockIPRules()
                ->filter(['Permission' => $permission])
                ->column('IPAddress');

            $filter = [
                'SessionLogID' => $sessionLog->ID,
                'Created:GreaterThanOrEqual' => $time,
                'IPAddress' => $ipAddresses,
            ];

            $requests = RequestLog::get()->filter($filter);

            if ($rule->IPAddress === 'Allowed') {
                if ($requests->exists() && $requests->count() <= $rule->IPAddressNumber) {
                    return true;
                }
            } else {
                if(!$requests->exists() || $request->count() <= $rule->IPAddressNumber) {
                    return true;
                }
            }
        }

        /*

        if (self::TrustedDevicesCount < $sessionLog->TrustedDevices()->count()) {
            return true;
        }
        */

        return false;
    }

}
