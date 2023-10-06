<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
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
        'ExcludePermission' => "Boolean",
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static array $has_one = [
        'Group' => Group::class,
        'Permission' => Permission::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static array $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static array $belongs_many_many = [
        'Roadblock' => Roadblock::class,
    ];

    private static string $table_name = 'RoadblockRule';

    private static string $plural_name = 'Rules';

    private static array $indexes = [
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
    ];

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

    public function getExportFields(): array
    {
        $fields =  [
            'Title' => 'Title',
            'Level' => 'Level',
            'LoginAttemptsStatus' => 'LoginAttemptsStatus',
            'LoginAttemptsNumber' => 'LoginAttemptsNumber',
            'LoginAttemptsStartOffset' => 'LoginAttemptsStartOffset',
            'TypeCount' => 'TypeCount',
            'TypeStartOffset' => 'TypeStartOffset',
            'Verb' => 'Verb',
            'VerbCount' => 'VerbCount',
            'VerbStartOffset' => 'VerbStartOffset',
            'IPAddress' => 'IPAddress',
            'IPAddressNumber' => 'IPAddressNumber',
            'IPAddressOffset' => 'IPAddressOffset',
            'ExcludeGroup' => 'ExcludeGroup',
            'Score' => 'Score',
            'Cumulative' => 'Cumulative',
            'Status' => 'Status',
            'Score' => 'Score',
            'Score' => 'Score',
            'Score' => 'Score',
            'Group.Code' => 'Group.Code',
            'Permission.Code' => 'Permission.Code',
            'RoadblockRequestType.Title' => 'RoadblockRequestType.Title',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
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

            $status = max($rule->extend('updateEvaluateMember', $sessionLog, $request, $rule));

            if ($status) {
                return true;
            }

            //loop all sessions for member
            foreach (SessionLog::getMemberSessions($member) as $sessionLog) {
                $status = self::evaluateSession($sessionLog, $request, $rule);
                if ($status) {
                    return true;
                }
            }

        } else {
            if (self::evaluateSession($sessionLog, $request, $rule)) {
                return true;
            }
        }

        return false;
    }

    public static function evaluateSession(SessionLog $sessionLog, RequestLog $request, RoadblockRule $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            return true;
        }

        $type = $rule->RoadblockRequestType();

        if ($type && $type->ID) {
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
                'Created:GreaterThanOrEqual' => $time,
                'Verb' => $rule->Verb,
            ];

            $requests = RequestLog::get()->filter($filter);

            if (!$requests->exists()) {
                return true;
            }

            if ($requests->count() <= $rule->VerbCount) {
                return true;
            }
        }

        $member = Security::getCurrentUser();

        $group = $rule->Group();

        if ($group && $group->ID) {
            if ($rule->ExcludeGroup && (!$member || !$member->inGroup($group))) {
                return true;
            }

            if (!$rule->ExcludeGroup && $member && $member->inGroup($group)){
                return true;
            }
        }

        $permission = $rule->Permission();

        if ($permission && $permission->ID) {
            if ($rule->ExcludePermission && !Permission::check($permission->Code)) {
                return true;
            }

            if (!$rule->ExcludePermission && Permission::check($permission->Code)){
                return true;
            }
        }

        if ($rule->IPAddress !== 'Any') {
            $time = DBDatetime::create()
                ->modify($sessionLog->LastAccessed)
                ->modify('-' . $rule->IPAddressOffset . ' seconds')
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
                if(!$requests->exists() || $requests->count() <= $rule->IPAddressNumber) {
                    return true;
                }
            }
        }

        return max($rule->extend('updateEvaluateSession', $sessionLog, $request, $rule));
    }

}
