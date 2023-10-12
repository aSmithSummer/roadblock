<?php

namespace Roadblock\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RoadblockRule extends DataObject
{

    private static array $db = [
        'Title' => 'Varchar(32)',
        'Level' => "Enum('Global,Member,Session','Session')",
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
        'IPAddressBroadcastOnBlock' => 'Boolean',
        'IPAddressReceiveOnBlock' => 'Boolean',
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

    private static string $default_sort = 'Title';

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

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $order = [
            'RoadblockRequestTypeID' => 'LoginAttemptsStartOffset',
            'Cumulative' => 'PermissionID',
            'Score' => 'Cumulative',
            'Status' => 'Score',
            'GroupID' => 'IPAddressReceiveOnBlock',
            'ExcludeGroup' => 'GroupID',
            'PermissionID' => 'ExcludeGroup',
            'ExcludePermission' => 'PermissionID',
        ];

        foreach ($order as $fieldName => $after) {
            $field = $fields->dataFieldByName($fieldName);
            $fields->insertAfter($after, $field);
        }

        $instructions = literalField::create(
            'Instructions',
            _t(__CLASS__ . 'EDIT_INSTRUCTIONS',
                'If any field group evaluates to true, the rule is pass without creating an exception.')
        );

        $descriptions = [
            'Level' => _t(__CLASS__ . 'EDIT_LEVEL_DESCRIPTION', 'Global = IPAddress, Member = member, Session = current session.'),
            'LoginAttemptsStatus' => _t(__CLASS__ . 'EDIT_LOGIN_DESCRIPTION', 'Login attempt attached to a request of this status<br/>Level of member required for this field.'),
            'LoginAttemptsNumber' => _t(__CLASS__ . 'EDIT_LOGIN2_DESCRIPTION', 'And number of requests greater than or equal to'),
            'LoginAttemptsStartOffset' => _t(__CLASS__ . 'EDIT_LOGIN3_DESCRIPTION', 'Within the last x seconds<br/>Set to 0 for just this request'),
            'RoadblockRequestTypeID' => _t(__CLASS__ . 'EDIT_TYPE_DESCRIPTION', 'Required if you want to use IPAddress.<br/>Request is for url\'s associated with this type'),
            'TypeCount' => _t(__CLASS__ . 'EDIT_TYPE2_DESCRIPTION', 'And number of requests greater than or equal to<br/>Set to 0 to ignore and just use Denie IPAddress etc<br/>Set to 1 with offset set to 0 to just allow IPAddress etc'),
            'TypeStartOffset' => _t(__CLASS__ . 'EDIT_TYPE3_DESCRIPTION', 'Within the last x seconds<br/>Set to 0 for just this request'),
            'VerbCount' => _t(__CLASS__ . 'EDIT_VERB_DESCRIPTION', 'And number of requests greater than or equal to'),
            'VerbStartOffset' => _t(__CLASS__ . 'EDIT_VERB2_DESCRIPTION', 'Within the last x seconds<br/>Set to 0 for just this request'),
            'IPAddress' => _t(__CLASS__ . 'EDIT_IPADDRESSL_DESCRIPTION', 'Allowed = list of IP addresses attached to request type with \'Allowed\'. If in this list will pass.<br/>Denied = list of IP addresses attached to request type with \'Denied\'. If in this list will fail (if no superceeding success).'),
            'IPAddressNumber' => _t(__CLASS__ . 'EDIT_IPADDRESS2_DESCRIPTION', 'And number of requests greater than or equal to'),
            'IPAddressOffset' => _t(__CLASS__ . 'EDIT_IPADDRESS3_DESCRIPTION', 'Within last x seconds.'),
            'IPAddressBroadcastOnBlock' => _t(__CLASS__ . 'EDIT_IPADDRESS4_DESCRIPTION', 'If blocked will add IP address automatically to recieve on block rule\'s request type'),
            'IPAddressReceiveOnBlock' => _t(__CLASS__ . 'EDIT_IPADDRESS5_DESCRIPTION', 'If a block occurs somewhere else, it will be added to this rule\'s request type.'),
            'ExcludeGroup' => _t(__CLASS__ . 'EDIT_GROUP_DESCRIPTION', 'If excluded, authenticated members in this group will fail<br/>If not excluded authenticated members in this group and unauthenticated members will pass'),
            'ExcludePermission' => _t(__CLASS__ . 'EDIT_PERMISSION_DESCRIPTION', 'If excluded, authenticated members with this permission will fail<br/>If not excluded authenticated members with this permission and unauthenticated members will pass'),
            'Score' => _t(__CLASS__ . 'EDIT_SCORE_DESCRIPTION', 'Score contributes to the roadblock record. Scores over 100.00 will block the session.'),
            'Cumulative' => _t(__CLASS__ . 'EDIT_SCORE_DESCRIPTION', 'Cumulative scores add each time, non-cumulative will only count once.'),
        ];

        $fields->insertBefore('Title', $instructions);

        foreach ($descriptions as $fieldName => $description) {
            $field = $fields->dataFieldByName($fieldName);
            $field->setDescription($description);
        }

        return $fields;
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

        if ($rule->Level === 'Global') {
            if (self::evaluateSession($sessionLog, $request, $rule, true)) {
                return true;
            }
        } else if ($rule->Level === 'Member') {
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

    public static function evaluateSession(SessionLog $sessionLog, RequestLog $request, RoadblockRule $rule, $global = false): bool
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
                'Created:GreaterThanOrEqual' => $time,
                'RoadblockRequestTypeID' => $rule->RoadblockRequestTypeID,
            ];

            if ($global) {
                $filter['IPAddress'] = $request->IPAddress;
            } else {
                $filter['SessionLogID'] = $sessionLog->ID;
            }

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
                'Created:GreaterThanOrEqual' => $time,
                'Verb' => $rule->Verb,
            ];

            if ($global) {
                $filter['IPAddress'] = $request->IPAddress;
            } else {
                $filter['SessionLogID'] = $sessionLog->ID;
            }

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

            if (!$ipAddresses) {
                return true;
            }

            $filter = [
                'Created:GreaterThanOrEqual' => $time,
                'IPAddress' => $ipAddresses,
            ];

            if ($global) {
                $filter['IPAddress'] = $request->getIP();
            } else {
                $filter['SessionLogID'] = $sessionLog->ID;
            }

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

        return max($rule->extend('updateEvaluateSession', $sessionLog, $request, $rule, $global));
    }

    public static function broadcastOnBlock(RoadblockRule $rule, RequestLog $requestLog): void
    {
        if ($requestLog->IPAddressBroadcastOnBlock) {
            $ipAddress = RoadblockIPRule::get()->filter([
                'Permission' => 'Denied',
                'IPAddress' => $requestLog->IPAddress,
            ])->first();

            if (!$ipAddress) {
                $ipAddress = RoadblockIPRule::create([
                    'Permission' => 'Denied',
                    'IPAddress' => $requestLog->IPAddress,
                    'Description' => _t(
                        __CLASS__ . '.BROADCAST_DESCRIPTION',
                        'Auto blocking from {rule).',
                        ['rule' => $rule->Title]
                    )
                ]);
            }

            $rules = self::get()->filter([
                'IPAddressReceiveOnBlock' => 1,
            ]);

            foreach ($rules as $rule) {
                $rule->RoadblockRequestType()->RoadblockIPRules()->add($ipAddress);
            }
        }
    }

}
