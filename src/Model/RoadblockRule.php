<?php

namespace Roadblock\Model;

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
        'RoadblockRuleTests' => RoadblockRuleInspector::class,
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

        if ($this->RoadblockRuleInspectors()) {
            $fields->addFieldToTab(
                'Root.TestResults',
                LiteralField::create('TestResults', $this->testRule())
            );
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

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, RoadblockRule $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            $rule->addExceptionData(_t(__class__ . 'TEST_DISABLED',
                '{rule} is disabled',
                $rule->Title));
            return true;
        }

        $rule->addExceptionData(_t(__class__ . 'TEST_ENABLED',
            '{rule} is enabled',
            $rule->Title));

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

        $type = $rule->RoadblockRequestType();

        if ($global) {
            $filter['IPAddress'] = $requestLog->IPAddress;
        } else {
            $filter['SessionLogID'] = $sessionLog->ID;
        }

        $requestLogs = $rule->getRequestLogs()->filter($filter);

        if ($type && $type->ID) {
            $time = DBDatetime::create()
                ->modify($sessionLog->LastAccessed)
                ->modify('-' . $rule->TypeStartOffset . ' seconds')
                ->format('y-MM-dd HH:mm:ss');
            $filter = [
                'Created:GreaterThanOrEqual' => $time,
                'RoadblockRequestTypeID' => $rule->RoadblockRequestTypeID,
            ];

            $typeList = $requestLogs->filter($filter);

            if (!$typeList->exists()) {
                $rule->addExceptionData(_t(__class__ . 'TEST_NO_TYPE',
                    'No requests of type {TYPE}'),
                $rule->RoadblockRequestType()->Title);
                return true;
            }

            if ($typeList->count() <= $rule->TypeCount) {
                $rule->addExceptionData(_t(
                    __class__ . 'TEST_TYPE_COUNT',
                    'Type count of {typeCount} is less than or equal to ' .
                    'Type Number of {typeNumber}',
                    [
                        'typeCount' => $typeList->count(),
                        'typeNumber' => $rule->TypeCount,
                    ]
                ));
                return true;
                $rule->addExceptionData(_t(
                    __class__ . 'TEST_TYPE_COUNT_FALSE',
                    'Type count of {typeCount} is greater than ' .
                    'Type Number of {typeNumber}',
                    [
                        'typeCount' => $typeList->count(),
                        'typeNumber' => $rule->TypeCount,
                    ]
                ));
            }
        }

        if ($rule->Verb !== 'Any') {
            $time = DBDatetime::now()->modify('-' . $rule->VerbStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
            $filter = [
                'Created:GreaterThanOrEqual' => $time,
                'Verb' => $rule->Verb,
            ];

            $verbList = $requestLogs->filter($filter);

            if (!$verbList->exists()) {
                $rule->addExceptionData(_t(__class__ . 'TEST_NO_VERB',
                    'No verbs of type {Verb}'),
                    $rule->Verb);
                return true;
            }

            if ($verbList->count() <= $rule->VerbCount) {
                $rule->addExceptionData(_t(
                    __class__ . 'TEST_VERB_COUNT',
                    'Verb count of {verbCount} is less than or equal to ' .
                    'Verb Number of {verbNumber}',
                    [
                        'verbCount' => $verbList->count(),
                        'verbNumber' => $rule->VerbCount,
                    ]
                ));
                return true;
            }
            $rule->addExceptionData(_t(
                __class__ . 'TEST_VERB_COUNT_FALSE',
                'Verb count of {verbCount} is greater than ' .
                'Verb Number of {verbNumber}',
                [
                    'verbCount' => $verbList->count(),
                    'verbNumber' => $rule->VerbCount,
                ]
            ));
        }

        $member = $rule->getCurrentUser();

        $group = $rule->Group();

        if ($group && $group->ID) {
            if ($rule->ExcludeGroup){
                if (!$member || !$member->inGroup($group)) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_GROUP',
                        'Excluded Group for member {member} that is not in {group}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'group' => $group->Title]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_GROUP_FALSE',
                    'Excluded Group for member {member} that is in {group}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'group' => $group->Title]));
            } else {
                if ($member && $member->inGroup($group)){
                    $rule->addExceptionData(_t(__class__ . 'TEST_INCLUDE_GROUP',
                        'Included Group for member {member} that is in {group}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'group' => $group->Title]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_INCLUDE_GROUP_FALSE',
                    'Included Group for member {member} that is not in {group}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'group' => $group->Title]));
            }
        }

        $permission = $rule->Permission();

        if ($permission && $permission->ID) {
            if ($rule->ExcludePermission) {
                if (!Permission::checkMember($member, $permission->Code)) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_PERMISSION',
                        'Excluded Permission for member {member} that is not in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission->Code]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_EXCLUDE_PERMISSION_FALSE',
                    'Excluded Permission for member {member} that is in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission->Code]));
            } else {
                if (Permission::checkMember($member, $permission->Code)){
                    $rule->addExceptionData(_t(__class__ . 'TEST_INCLUDE_PERMISSION',
                        'Included Permission for member {member} that is in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission->Code]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_INCLUDE_PERMISSION_FALSE',
                    'Included Permission for member {member} that is not in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission->Code]));
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
                $rule->addExceptionData(_t(__class__ . 'TEST_NO_IPADDRESS',
                    'No IP addresses of type {allowed} set for {requestType}',
                    ['allowed' => $permission, 'requestType' => $rule->RoadblockRequestType()->Title]));
                return true;
            }

            $filter = [
                'Created:GreaterThanOrEqual' => $time,
                'IPAddress' => $ipAddresses,
            ];

            $ipList = $requestLogs->filter($filter);

            if ($rule->IPAddress === 'Allowed') {
                if ($ipList->exists() && $ipList->count() <= $rule->IPAddressNumber) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_ALLOWED',
                        'Request count {count} is less than or equal to {number}',
                        ['count' => $ipList ? $ipList->count() : '(none)', 'number' => $rule->IPAddressNumber]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_ALLOWED_FALSE',
                    'Request count {count} is greater than {number}',
                    ['count' => $ipList ? $ipList->count() : '(none)', 'number' => $rule->IPAddressNumber]));
            } else {
                if(!$ipList->exists() || $ipList->count() <= $rule->IPAddressNumber) {
                    $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_DENIED',
                        'Request count {count} is less than or equal to {number}',
                        ['count' => $ipList ? $ipList->count() : '(none)', 'number' => $rule->IPAddressNumber]));
                    return true;
                }
                $rule->addExceptionData(_t(__class__ . 'TEST_IPADDRESS_DENIED_FALSE',
                    'Request count {count} is greater than {number}',
                    ['count' => $ipList ? $ipList->count() : '(none)', 'number' => $rule->IPAddressNumber]));
            }
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
        $this->currentTest = $test;

        return $this;
    }

    public function getCurrentTest(): bool
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
                }
            }
        }

        return $this->getExceptionData();
    }

    public function getCurrentUser(): ?Member
    {
        $member = Security::getCurrentUser();

        if ($this->currentTest) {
            $member = $this->currentTest->getMember();
        }

        return $member;
    }

    public function getLoginAttemps(Member $member)
    {
        $time = DBDatetime::now()->modify('+' . $this->LoginAttemptsStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
        $filter = [
            'MemberID' => $member->ID,
            'Created:GreaterThan' => $time,
        ];

        if ($this->LoginAttemptStatus !== 'Any') {
            $filter['Status'] = $this->LoginAttemptStatus;
        }

        $test = $this->currentTest;

        if ($test) {
            return $test->getLoginAttempt()->filter($filter);
        }

        return LoginAttempt::get()->filter($filter);
    }

    public function getRequestLogs()
    {
        $test = $this->currentTest;

        if ($test) {
            return $test->getRequestLogs();
        }

        return RequestLog::get();
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

}
