<?php

namespace Roadblock\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RoadblockRule extends DataObject
{

    private ?RoadblockRuleInspector $currentTest = null;

    private array $exceptionData = [];

    private static array $db = [
        'Title' => 'Varchar(32)',
        'Level' => "Enum('Global,Member,Session','Session')",
        'LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
        'LoginAttemptsNumber' => 'Int',
        'LoginAttemptsStartOffset' => 'Int',
        'Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
        'IPAddress' => "Enum('Any,Allowed,Allowed for group, Allowed for permission,Denied','Any)",
        'Count' => 'Int',
        'StartOffset' => 'Int',
        'IPAddressBroadcastOnBlock' => 'Boolean',
        'IPAddressReceiveOnBlock' => 'Boolean',
        'ExcludeGroup' => "Boolean",
        'ExcludeUnauthenticated' => "Boolean",
        'ExcludePermission' => "Boolean",
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Enabled')",
        'Permission' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Group' => Group::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static array $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
        'RoadblockRuleInspectors' => RoadblockRuleInspector::class,
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

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('Permission');
        $permissions = Permission::get()->columnUnique('Code');
        $permissions[] = 'CMS_ACCESS';
        $permissions[] = 'CMS_ACCESS_LeftAndMain';
        sort($permissions);
        $permissions = array_combine($permissions, $permissions);

        $permission = DropdownField::create('Permission', 'Permission', $permissions);
        $fields->insertAfter('ExcludeUnauthenticated', $permission);

        $order = [
            'RoadblockRequestTypeID' => 'LoginAttemptsStartOffset',
            'Cumulative' => 'Permission',
            'Score' => 'Cumulative',
            'Status' => 'Score',
            'GroupID' => 'IPAddressReceiveOnBlock',
            'ExcludeGroup' => 'GroupID',
            'ExcludeUnauthenticated' => 'ExcludeGroup',
            'Permission' => 'ExcludeUnauthenticated',
            'ExcludePermission' => 'Permission',
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
            'Level' => _t(__CLASS__ . 'EDIT_LEVEL_DESCRIPTION', 'Global = IPAddress, Member = member, ' .
                'Session = current session.'),
            'LoginAttemptsStatus' => _t(__CLASS__ . 'EDIT_LOGIN_DESCRIPTION', 'Login attempt attached to a ' .
                'request of this status<br/>Level of member required for this field.'),
            'LoginAttemptsNumber' => _t(__CLASS__ . 'EDIT_LOGIN2_DESCRIPTION', 'And number of requests ' .
                'greater than or equal to'),
            'LoginAttemptsStartOffset' => _t(__CLASS__ . 'EDIT_LOGIN3_DESCRIPTION', 'Within the last x ' .
                    'seconds<br/>Set to 0 for just this request'),
            'RoadblockRequestTypeID' => _t(__CLASS__ . 'EDIT_TYPE_DESCRIPTION', 'Required if you want to ' .
                'use IPAddress and Verb.<br/>Request is for url\'s associated with this type'),
            'Count' => _t(__CLASS__ . 'EDIT_TYPE2_DESCRIPTION', 'And number of requests greater than or ' .
                'equal to<br/>Set to 1 with offset set to 0 to just evaluate this request'),
            'StartOffset' => _t(__CLASS__ . 'EDIT_TYPE3_DESCRIPTION', 'Within the last x seconds' .
                '<br/>Set to 0 for just this request'),
            'IPAddress' => _t(__CLASS__ . 'EDIT_IPADDRESSL_DESCRIPTION', 'Allowed = list of IP addresses ' .
                'attached to request type with \'Allowed\'. If in this list will pass.' .
                '<br/>Allowed for group = Allowed combined with group logic.' .
                '<br/>Allowed for permission = Allowed combined with permission logic.' .
                '<br/>Denied = list of IP addresses attached to request type with \'Denied\'. ' .
                'If in this list will fail (if no superceeding success).'),
            'IPAddressBroadcastOnBlock' => _t(__CLASS__ . 'EDIT_IPADDRESS2_DESCRIPTION', 'If blocked will ' .
                'add IP address automatically to recieve on block rule\'s request type'),
            'IPAddressReceiveOnBlock' => _t(__CLASS__ . 'EDIT_IPADDRESS3_DESCRIPTION', 'If a block occurs ' .
                'somewhere else, it will be added to this rule\'s request type.'),
            'ExcludeGroup' => _t(__CLASS__ . 'EDIT_GROUP_DESCRIPTION', 'If excluded, authenticated members ' .
                'in this group will fail' .
                '<br/>If not excluded authenticated members in this group and unauthenticated members will pass'),
            'ExcludeUnauthenticated' => _t(__CLASS__ . 'EDIT_GROUP2_DESCRIPTION', 'If excluded, ' .
                'unauthenticated members in this group will fail' .
                '<br/>If not excluded authenticated members in this group and unauthenticated members will pass'),
            'ExcludePermission' => _t(__CLASS__ . 'EDIT_PERMISSION_DESCRIPTION', 'If excluded, ' .
                'unauthenticated members with this permission will fail' .
                '<br/>If not excluded authenticated members with this permission and unauthenticated members will pass'),
            'Score' => _t(__CLASS__ . 'EDIT_SCORE_DESCRIPTION', 'Score contributes to the roadblock record. ' .
                '<br/>Scores over 100.00 will block the session.' .
                '<br/>Scores of 0.00 will block the session.' .
                '<br/>Scores under 0.00 will reduce score and provide info notification.'),
            'Cumulative' => _t(__CLASS__ . 'EDIT_SCORE_DESCRIPTION', 'Cumulative scores add each time, ' .
                'non-cumulative will only count once.'),
        ];

        $fields->insertBefore('Title', $instructions);

        foreach ($descriptions as $fieldName => $description) {
            $field = $fields->dataFieldByName($fieldName);
            $field->setDescription($description);
        }

        $fields->dataFieldByName('Permission')->setSource($permissions);

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
            'Count' => 'Count',
            'StartOffset' => 'Offset',
            'Verb' => 'Verb',
            'IPAddress' => 'IPAddress',
            'ExcludeGroup' => 'ExcludeGroup',
            'ExcludeUnauthenticated' => 'ExcludeUnauthenticated',
            'Score' => 'Score',
            'Cumulative' => 'Cumulative',
            'Status' => 'Status',
            'Group.Code' => 'Group.Code',
            'Permission' => 'Permission',
            'RoadblockRequestType.Title' => 'RoadblockRequestType.Title',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, RoadblockRule $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            $rule->addExceptionData(_t(__class__ . 'TEST_DISABLED',
                '{rule} is disabled',
                ['rule' => $rule->Title]));

            return true;
        }

        $rule->addExceptionData(_t(__class__ . 'TEST_ENABLED',
            '{rule} is enabled',
            ['rule' => $rule->Title]));

        $member = $rule->getCurrentUser();

        if ($rule->Level === 'Global') {
            if (self::evaluateSession($sessionLog, $requestLog, $rule, true)) {
                $rule->addExceptionData(_t(__class__ . 'TEST_GLOBAL_TRUE', 'Global evaluation true'));

                return true;
            }
            $rule->addExceptionData(_t(__class__ . 'TEST_GLOBAL_FALSE', 'Global evaluation false'));
        } else if ($rule->Level === 'Member') {
            if (!$member) {
                $rule->addExceptionData(_t(__class__ . 'TEST_NO_MEMBER', 'No member'));

                return true;
            }

            $rule->addExceptionData(_t(__class__ . 'TEST_MEMBER',
                'Member {FirstName} has been found',
                $member->FirstName));

            if ($rule->LoginAttemptsNumber) {
                $logins = $rule->getLoginAttemps($member);

                if (!$logins) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_NO_LOGIN_ATTEMPTS', 'There is no login attempt'));

                    return true;
                }

                if ($logins->count() <= $rule->LoginAttemptsNumber) {
                    $rule->addExceptionData(_t(
                        __class__ . 'TEST_LOGIN_ATTEMPTS_COUNT',
                        'Login attempt count of {loginCount} is less than or equal to ' .
                        'Login Attempt Number of {loginAttemptNumber}',
                        [
                            'loginCount' => $logins->count(),
                            'loginAttemptNumber' => $rule->LoginAttemptsNumber,
                        ]
                    ));

                    return true;
                }
                $rule->addExceptionData(_t(
                    __class__ . 'TEST_LOGIN_ATTEMPTS_COUNT_FALSE',
                    'Login attempt count of {loginCount} is greater than ' .
                    'Login Attempt Number of {loginAttemptNumber}',
                    [
                        'loginCount' => $logins->count(),
                        'loginAttemptNumber' => $rule->LoginAttemptsNumber,
                    ]
                ));
            }

            $status = max($rule->extend('updateEvaluateMember', $sessionLog, $requestLog, $rule));

            if ($status) {
                $rule->addExceptionData(_t(__class__ . 'TEST_EXTEND_MEMBER',
                    'Extend evaluate member is true'));

                return true;
            }

            $rule->addExceptionData(_t(__class__ . 'TEST_EXTEND_MEMBER_FALSE',
                'Extend evaluate member is false'));

            //loop all sessions for member
            foreach (SessionLog::getMemberSessions($member) as $sessionLog) {
                $status = self::evaluateSession($sessionLog, $requestLog, $rule);
                if ($status) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_MEMBER_SESSION',
                        'Meber evaluate session is true'));

                    return true;
                }
            }
            $rule->addExceptionData(_t(__class__ . 'TEST_MEMBER_SESSION_FALSE',
                'Meber evaluate is false'));

        } else {
            if (self::evaluateSession($sessionLog, $requestLog, $rule)) {
                return true;
            }
        }

        $rule->addExceptionData(_t(__class__ . 'TEST_FALSE',
            'Evaluate is false'));

        return false;
    }

    public static function evaluateSession(SessionLog $sessionLog, RequestLog $requestLog, RoadblockRule $rule, $global = false): bool
    {
        if ($rule->Status === 'Disabled') {
            return true;
        }

        $member = $rule->getCurrentUser();

        $type = $rule->RoadblockRequestType();

        if ($type && $type->ID) {
            $time = DBDatetime::create()
                ->modify($sessionLog->LastAccessed)
                ->modify('-' . $rule->StartOffset . ' seconds')
                ->format('y-MM-dd HH:mm:ss');
            $filter = [
                'RoadblockRequestTypeID' => $rule->RoadblockRequestTypeID,
            ];

            if ($global) {
                $filter['IPAddress'] = $requestLog->IPAddress;
            } else {
                $filter['SessionLogID'] = $sessionLog->ID;
            }

            if ($rule->Verb !== 'Any') {
                $filter['Verb'] = $rule->Verb;
            }

            $exclude = [];

            if ($rule->IPAddress !== 'Any') {
                $permission = in_array($rule->IPAddress, ['Allowed', 'Allowed for group', 'Allowed for permission']) ?
                    'Allowed' :
                    'Denied';
                $ipAddresses = $rule
                    ->RoadblockRequestType()
                    ->RoadblockIPRules()
                    ->filter(['Permission' => $permission])
                    ->column('IPAddress');

                if (!$ipAddresses) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_NO_IPADDRESS',
                        'No IP addresses of type {allowed} set for {requestType}',
                        ['allowed' => $permission, 'requestType' => $rule->RoadblockRequestType()->Title]));

                    return true;
                }

                if ($permission === 'Allowed') {
                    if ($global) {
                        $ipAddress = $filter['IPAddress'];
                    } else {
                        $ipAddress = $requestLog->IPAddress;
                        $exclude['IPAddress'] = $ipAddresses;
                    }
                    if (in_array($ipAddress, $ipAddresses)) {
                        $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_ALLOWED',
                            'IP address of type {global} is allowed for {requestType}',
                            [
                                'global' => $ipAddress,
                                'requestType' => $rule->RoadblockRequestType()->Title
                            ]
                        ));

                        switch ($rule->IPAddress) {
                            case 'Allowed':

                                return true;
                            case 'Allowed for group':
                                if ($rule->evaluateGroup($member)) {
                                    return true;
                                }

                                break;

                            case 'Allowed for permission':
                                if ($rule->evaluatePermission($member)) {
                                    return true;
                                }
                        }

                        $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_ALLOWED_FALSE',
                            'IP address of type {global} failed permission for {requestType}',
                            [
                                'global' => $ipAddress,
                                'requestType' => $rule->RoadblockRequestType()->Title
                            ]
                        ));
                    }
                } else {
                    if ($global) {
                        if (!in_array($filter['IPAddress'], $ipAddresses)) {
                            $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_DENIE',
                                'IP address of type {global} is not denied for {requestType}',
                                [
                                    'global' => $filter['IPAddress'],
                                    'requestType' => $rule->RoadblockRequestType()->Title
                                ]
                            ));

                            return true;
                        }

                        $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_DENIE_FALSE',
                            'IP address of type {global} is denied for {requestType}',
                            [
                                'global' => $filter['IPAddress'],
                                'requestType' => $rule->RoadblockRequestType()->Title
                            ]
                        ));
                    } else {
                        $filter['IPAddress'] = $ipAddresses;
                    }
                }
            }

            $requestLogs = $rule->getRequestLogs($filter, $time);

            if ($exclude) {
                $requestLogs->exclude($exclude);
            }

            if (!$requestLogs->exists()) {
                $rule->addExceptionData(_t(__class__ . 'TEST_NO_TYPE',
                    'No requests of type {type}, verb {verb}, ipaddress {ipAddress}',
                [
                    'type' => $rule->RoadblockRequestType()->Title,
                    'verb' => $rule->Verb,
                    'ipAddress' => $rule->IPAddress,
                ]));

                return true;
            }

            if ($requestLogs->count() < $rule->TypeCount) {
                $rule->addExceptionData(_t(
                    __class__ . 'TEST_TYPE_COUNT',
                    'Request count of {typeCount} is less than {typeNumber} for verb {verb}, ipaddress {ipAddress}',
                    [
                        'typeCount' => $requestLogs->count(),
                        'typeNumber' => $rule->Count,
                        'verb' => $rule->Verb,
                        'ipAddress' => $rule->IPAddress,
                    ]
                ));

                return true;
            }
            $rule->addExceptionData(_t(
                __class__ . 'TEST_TYPE_COUNT_FALSE',
                'Request count of {typeCount} is greater than or equal ' .
                'to {typeNumber} for verb {verb}, ipaddress {ipAddress}',
                [
                    'typeCount' => $requestLogs->count(),
                    'typeNumber' => $rule->Count,
                    'verb' => $rule->Verb,
                    'ipAddress' => $rule->IPAddress,
                ]
            ));
        }

        if ($rule->IPAddress !== 'Allowed for group' && $rule->evaluateGroup($member)) {
            return true;
        }

        if ($rule->IPAddress !== 'Allowed for permission' && $rule->evaluatePermission($member)) {
            return true;
        }

        $status = max($rule->extend('updateEvaluateSession', $sessionLog, $requestLog, $rule, $global));

        if ($status) {
            $rule->addExceptionData(_t(__class__ . 'TEST_EXTEND_SESSION',
                'Extend evaluate session is true'));
            return true;
        }
        $rule->addExceptionData(_t(__class__ . 'TEST_EXTEND_SESSION_FALSE',
            'Extend evaluate session is false'));

        return false;
    }

    public function evaluateGroup($member): bool
    {
        $group = $this->Group();

        if ($group && $group->ID) {
            if ($this->ExcludeGroup){
                if (
                    !$member || !$member->inGroup($group) &&
                    (!$this->ExcludeUnauthenticated || $member)
                ) {
                    $this->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_GROUP',
                        'Excluded Group for member {member} that is not in {group}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'group' => $group->Title]));

                    return true;
                }
                $this->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_GROUP_FALSE',
                    'Excluded Group for member {member} that is in {group}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'group' => $group->Title]));
            } else {
                if ($member && $member->inGroup($group)){
                    $this->addExceptionData(_t(__class__ . 'TEST_INCLUDE_GROUP',
                        'Included Group for member {member} that is in {group}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'group' => $group->Title]));

                    return true;
                }
                $this->addExceptionData(_t(__class__ . 'TEST_INCLUDE_GROUP_FALSE',
                    'Included Group for member {member} that is not in {group}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'group' => $group->Title]));
            }
        }

        return false;
    }

    public function evaluatePermission($member): bool
    {
        $permission = $this->Permission;

        if ($permission ) {
            if ($this->ExcludePermission) {
                if (!Permission::checkMember($member, $permission)) {
                    $this->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_PERMISSION',
                        'Excluded Permission for member {member} that is not in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission]));
                    return true;
                }
                $this->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_PERMISSION_FALSE',
                    'Excluded Permission for member {member} that is in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission]));
            } else {
                if (Permission::checkMember($member, $permission)){
                    $this->addExceptionData(_t(__class__ . 'TEST_INCLUDE_PERMISSION',
                        'Included Permission for member {member} that is in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission]));
                    return true;
                }
                $this->addExceptionData(_t(__class__ . 'TEST_INCLUDE_PERMISSION_FALSE',
                    'Included Permission for member {member} that is not in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission]));
            }
        }

        return false;
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

    public function setCurrentTest(?RoadblockRuleInspector $test): self
    {
        $this->resetExceptionData();
        $this->currentTest = $test;

        $test->setCurrentTest();

        return $this;
    }

    public function getCurrentTest(): ?RoadblockRuleInspector
    {
        return $this->currentTest;
    }

    public function testRule(): string
    {
        $testCases = $this->RoadblockRuleInspectors();
        $response = [];

        if ($testCases) {
            foreach ($testCases as $test) {
                $this->setCurrentTest($test);
                $sessionLog = $test->getSessionLog();
                $requestLog = $test->getRequestLog();

                if ($requestLog && $sessionLog) {
                    self::evaluate($sessionLog, $requestLog, $this);
                    $test->LastRun = DBDatetime::now()->Rfc2822();
                    $test->Result = $this->getExceptionData();
                    $test->write();
                }
            }
        }

        return $this->getExceptionData();
    }

    public function getCurrentUser(): ?Member
    {
        $member = Security::getCurrentUser();

        if ($this->currentTest) {
            $member = $this->currentTest->Member();
        }

        return $member;
    }

    public function getLoginAttemps(Member $member)
    {
        $time = DBDatetime::now()->modify('+' . $this->LoginAttemptsStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
        $filter = [
            'MemberID' => $member->ID,
        ];

        if ($this->LoginAttemptStatus !== 'Any') {
            $filter['Status'] = $this->LoginAttemptStatus;
        }

        $test = $this->currentTest;

        if ($test) {
            return $test->getLoginAttempt()
                ->filter($filter)
                ->filterByCallback(function ($requestLog) use ($time) {
                return $requestLog->Created >= $time;
            });
        }

        $filter['Created:GreaterThanOrEqual'] = $time;

        return LoginAttempt::get()->filter($filter);
    }

    public function getRequestLogs(array $filter, string $time)
    {
        $test = $this->currentTest;

        if ($test) {
            return $test->getRequestLogs()
                ->filter($filter)
                ->filterByCallback(function ($requestLog) use ($time) {
                    return $requestLog->Created >= $time;
                });
        }

        $filter['Created:GreaterThanOrEqual'] = $time;

        return RequestLog::get()->filter($filter);;
    }

    public function resetExceptionData(): void
    {
        $this->exceptionData = [];
    }

    public function addExceptionData(string $exceptionData): void
    {
        $array = $this->exceptionData;
        $array[] = $exceptionData;
        $this->exceptionData = $array;
    }

    public function getExceptionData(): string
    {
        return implode(PHP_EOL, $this->exceptionData);
    }

    public static function runTests(): void
    {
        $rules = self::get()->filter(['Status' => 'Enabled']);

        if ($rules) {
            foreach ($rules as $rule) {
                $rule->testRule();
            }
        }
    }

}
