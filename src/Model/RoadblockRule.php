<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\ArrayList;
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
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
        'ExcludeGroup' => 'Boolean',
        'ExcludeUnauthenticated' => 'Boolean',
        'ExcludePermission' => 'Boolean',
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Disabled')",
        'Permission' => 'Varchar(255)',
        'NotifyIndividuallySubject' => 'Varchar(255)',
        'NotifyMemberContent' => 'HTMLText',
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
        'Roadblocks' => Roadblock::class,
    ];

    private static string $table_name = 'RoadblockRule';

    private static string $plural_name = 'Rules';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $indexes = [
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
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

        $permission = DropdownField::create('Permission', 'Permission', $permissions)
            ->setHasEmptyDefault(true)->setEmptyString('(none)');
        $fields->insertAfter('ExcludeUnauthenticated', $permission);
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
            _t(
                self::class . 'EDIT_INSTRUCTIONS',
                'If any field group evaluates to true, the rule is pass without creating an exception.'
            )
        );

        $descriptions = [
            'Count' => _t(self::class . 'EDIT_TYPE2_DESCRIPTION', 'And number of requests greater than or ' .
                'equal to<br/>Set to 1 with offset set to 0 to just evaluate this request'),
            'Cumulative' => _t(self::class . 'EDIT_SCORE_DESCRIPTION', 'Cumulative scores add each time, ' .
                'non-cumulative will only count once.'),
            'ExcludeGroup' => _t(self::class . 'EDIT_GROUP_DESCRIPTION', 'If excluded, authenticated members ' .
                'in this group will fail' .
                '<br/>If not excluded authenticated members in this group and unauthenticated members will pass'),
            'ExcludePermission' => _t(self::class . 'EDIT_PERMISSION_DESCRIPTION', 'If excluded, ' .
                'unauthenticated members with this permission will fail' .
                '<br/>If not excluded authenticated members with permission and unauthenticated members will pass'),
            'ExcludeUnauthenticated' => _t(self::class . 'EDIT_GROUP2_DESCRIPTION', 'If excluded, ' .
                'unauthenticated members in this group will fail' .
                '<br/>If not excluded authenticated members in this group and unauthenticated members will pass'),
            'IPAddress' => _t(self::class . 'EDIT_IPADDRESSL_DESCRIPTION', 'Allowed = list of IP addresses ' .
                'attached to request type with \'Allowed\'. If in this list will pass.' .
                '<br/>Allowed for group = Allowed combined with group logic.' .
                '<br/>Allowed for permission = Allowed combined with permission logic.' .
                '<br/>Denied = list of IP addresses attached to request type with \'Denied\'. ' .
                'If in this list will fail (if no superceeding success).'),
            'IPAddressBroadcastOnBlock' => _t(self::class . 'EDIT_IPADDRESS2_DESCRIPTION', 'If blocked will ' .
                'add IP address automatically to recieve on block rule\'s request type'),
            'IPAddressReceiveOnBlock' => _t(self::class . 'EDIT_IPADDRESS3_DESCRIPTION', 'If a block occurs ' .
                'somewhere else, it will be added to this rule\'s request type.'),
            'Level' => _t(self::class . 'EDIT_LEVEL_DESCRIPTION', 'Global = IPAddress, Member = member, ' .
                'Session = current session.'),
            'LoginAttemptsNumber' => _t(self::class . 'EDIT_LOGIN2_DESCRIPTION', 'And number of requests ' .
                'greater than or equal to'),
            'LoginAttemptsStartOffset' => _t(self::class . 'EDIT_LOGIN3_DESCRIPTION', 'Within the last x ' .
                    'seconds<br/>Set to 0 for just this request'),
            'LoginAttemptsStatus' => _t(self::class . 'EDIT_LOGIN_DESCRIPTION', 'Login attempt attached to a ' .
                'request of this status<br/>Level of member required for this field.'),
            'RoadblockRequestTypeID' => _t(self::class . 'EDIT_TYPE_DESCRIPTION', 'Required if you want to ' .
                'use IPAddress and Verb.<br/>Request is for url\'s associated with this type'),
            'Score' => _t(self::class . 'EDIT_SCORE_DESCRIPTION', 'Score contributes to the roadblock record. ' .
                '<br/>Scores over 100.00 will block the session.' .
                '<br/>Scores of 0.00 will block the session.' .
                '<br/>Scores under 0.00 will reduce score and provide info notification.'),
            'StartOffset' => _t(self::class . 'EDIT_TYPE3_DESCRIPTION', 'Within the last x seconds' .
                '<br/>Set to 0 for just this request'),
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
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        $fields = [
            'Title' => 'Title',
            'Level' => 'Level',
            'LoginAttemptsStatus' => 'LoginAttemptsStatus',
            'LoginAttemptsNumber' => 'LoginAttemptsNumber',
            'LoginAttemptsStartOffset' => 'LoginAttemptsStartOffset',
            'Count' => 'Count',
            'StartOffset' => 'StartOffset',
            'Verb' => 'Verb',
            'IPAddress' => 'IPAddress',
            'IPAddressBroadcastOnBlock' => 'IPAddressBroadcastOnBlock',
            'IPAddressReceiveOnBlock' => 'IPAddressReceiveOnBlock',
            'ExcludeGroup' => 'ExcludeGroup',
            'ExcludeUnauthenticated' => 'ExcludeUnauthenticated',
            'ExcludePermission' => 'ExcludePermission',
            'Score' => 'Score',
            'Cumulative' => 'Cumulative',
            'Status' => 'Status',
            'Group.Code' => 'Group',
            'Permission' => 'Permission',
            'RoadblockRequestType.Title' => 'RoadblockRequestType',
            'NotifyIndividuallySubject' => 'NotifyIndividuallySubject',
            'NotifyMemberContent' => 'NotifyMemberContent',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function evaluateGroup(?Member $member): bool
    {
        $group = $this->Group();

        if ($group && $group->ID) {
            if ($this->ExcludeGroup) {
                if (!$member || !$member->inGroup($group) && (!$this->ExcludeUnauthenticated || $member)) {
                    $this->addExceptionData(_t(
                        self::class . 'TEST_EXCLUDE_GROUP',
                        'Excluded Group for member {member} that is not in {group}',
                        ['group' => $group->Title,
                            'member' => $member ? $member->FirstName : '(none)',
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . 'TEST_EXCLUDE_GROUP_FALSE',
                    'Excluded Group for member {member} that is in {group}',
                    ['group' => $group->Title,
                        'member' => $member ? $member->FirstName : '(none)',
                    ]
                ));
            } else {
                if ($member && $member->inGroup($group)) {
                    $this->addExceptionData(_t(
                        self::class . 'TEST_INCLUDE_GROUP',
                        'Included Group for member {member} that is in {group}',
                        ['group' => $group->Title,
                            'member' => $member ? $member->FirstName : '(none)',
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . 'TEST_INCLUDE_GROUP_FALSE',
                    'Included Group for member {member} that is not in {group}',
                    ['group' => $group->Title,
                        'member' => $member ? $member->FirstName : '(none)',
                    ]
                ));
            }
        }

        return false;
    }

    public function evaluatePermission(?Member $member): bool
    {
        $permission = $this->Permission;

        if ($permission) {
            if ($this->ExcludePermission) {
                if (!Permission::checkMember($member, $permission)) {
                    $this->addExceptionData(_t(
                        self::class . 'TEST_EXCLUDE_PERMISSION',
                        'Excluded Permission for member {member} that is not in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission,
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . 'TEST_EXCLUDE_PERMISSION_FALSE',
                    'Excluded Permission for member {member} that is in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission,
                    ]
                ));
            } else {
                if ($member !== null &&Permission::checkMember($member, $permission)) {
                    $this->addExceptionData(_t(
                        self::class . 'TEST_INCLUDE_PERMISSION',
                        'Included Permission for member {member} that is in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission,
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . 'TEST_INCLUDE_PERMISSION_FALSE',
                    'Included Permission for member {member} that is not in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission,
                    ]
                ));
            }
        }

        return false;
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

        if ($testCases) {
            foreach ($testCases as $test) {
                $this->setCurrentTest($test);
                $sessionLog = $test->getSessionLog();
                $requestLog = $test->getRequestLog();

                if (!$requestLog || !$sessionLog) {
                    continue;
                }

                self::evaluate($sessionLog, $requestLog, $this);
                $test->LastRun = DBDatetime::now()->Rfc2822();
                $test->Result = $this->getExceptionData();
                $test->write();
            }
        }

        return $this->getExceptionData();
    }

    public function getCurrentUser(): ?Member
    {
        $member = Security::getCurrentUser();

        if ($this->currentTest) {
            $member = $this->currentTest->MemberID ? $this->currentTest->Member() : null;
        }

        return $member;
    }

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public function getLoginAttemps(Member $member)
    {
        $time = DBDatetime::now()->modify('+' . $this->LoginAttemptsStartOffset . ' seconds')->format(
            'y-MM-dd HH:mm:ss'
        );
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

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
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

        return RequestLog::get()->filter($filter);
    }

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public function getSessionLogs(SessionLog $sessionLog, Member $member)
    {
        if ($this->currentTest) {
            $sessionLogs = ArrayList::create();
            $sessionLogs->push($sessionLog);
        } else {
            $sessionLogs = SessionLog::getMemberSessions($member);
        }

        return $sessionLogs;
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

    public function importGroup(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['Group']) {
            return;
        }

        $csv = trim($csv);

        $groups = Group::get()->filter('Code', $csv);

        if ($groups->exists()) {
            $group = $groups->first();
        } else {
            // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
            $group = Group::create([
                'Code' => $csv,
                'Title' => $csv,
                'Description' => 'Added by roadblock rule import',
            ]);
            $group->write();
        }

        $this->GroupID = $group->ID;
    }

    public function importRoadblockRequestType(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['RoadblockRequestType']) {
            return;
        }

        $csv = trim($csv);

        $requestTypes = RoadblockRequestType::get()->filter('Title', $csv);

        if ($requestTypes) {
            $requestType = $requestTypes->first();
        } else {
            // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
            $requestType = RoadblockRequestType::create([
                'Title' => $csv,
                'Status' => 'Disabled',
            ]);
            $requestType->write();
        }

        $this->RoadblockRequestTypeID = $requestType->ID;
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, self $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            $rule->addExceptionData(_t(
                self::class . 'TEST_DISABLED',
                '{rule} is disabled',
                ['rule' => $rule->Title]
            ));

            return true;
        }

        $rule->addExceptionData(_t(
            self::class . 'TEST_ENABLED',
            '{rule} is enabled',
            ['rule' => $rule->Title]
        ));

        $member = $rule->getCurrentUser();

        if ($rule->Level === 'Global') {
            if (self::evaluateSession($sessionLog, $requestLog, $rule, true)) {
                $rule->addExceptionData(_t(self::class . 'TEST_GLOBAL_TRUE', 'Global evaluation true'));

                return true;
            }

            $rule->addExceptionData(_t(self::class . 'TEST_GLOBAL_FALSE', 'Global evaluation false'));
        } elseif ($rule->Level === 'Member') {
            if (!$member) {
                $rule->addExceptionData(_t(self::class . 'TEST_NO_MEMBER', 'No member'));

                return true;
            }

            $rule->addExceptionData(_t(
                self::class . 'TEST_MEMBER',
                'Member {firstName} has been found',
                ['firstName' => $member->FirstName]
            ));

            if ($rule->LoginAttemptsNumber) {
                $logins = $rule->getLoginAttemps($member);

                if (!$logins) {
                    $rule->addExceptionData(_t(self::class . 'TEST_NO_LOGIN_ATTEMPTS', 'There is no login attempt'));

                    return true;
                }

                if ($logins->count() <= $rule->LoginAttemptsNumber) {
                    $rule->addExceptionData(_t(
                        self::class . 'TEST_LOGIN_ATTEMPTS_COUNT',
                        'Login attempt count of {loginCount} is less than or equal to ' .
                        'Login Attempt Number of {loginAttemptNumber}',
                        [
                            'loginAttemptNumber' => $rule->LoginAttemptsNumber,
                            'loginCount' => $logins->count(),
                        ]
                    ));

                    return true;
                }

                $rule->addExceptionData(_t(
                    self::class . 'TEST_LOGIN_ATTEMPTS_COUNT_FALSE',
                    'Login attempt count of {loginCount} is greater than ' .
                    'Login Attempt Number of {loginAttemptNumber}',
                    [
                        'loginAttemptNumber' => $rule->LoginAttemptsNumber,
                        'loginCount' => $logins->count(),
                    ]
                ));
            }

            $extension = $rule->extend('updateEvaluateMember', $sessionLog, $requestLog, $rule);

            if ($extension) {
                $status = max($extension);

                if ($status) {
                    $rule->addExceptionData(_t(
                        self::class . 'TEST_EXTEND_MEMBER',
                        'Extend evaluate member is true'
                    ));

                    return true;
                }
            }

            $rule->addExceptionData(_t(
                self::class . 'TEST_EXTEND_MEMBER_FALSE',
                'Extend evaluate member is false'
            ));

            //loop all sessions for member
            $sessionLogs = $rule->getSessionLogs($sessionLog, $member);

            if ($sessionLogs) {
                foreach ($sessionLogs as $memberSessionLog) {
                    $status = self::evaluateSession($memberSessionLog, $requestLog, $rule);

                    if ($status) {
                        $rule->addExceptionData(_t(
                            self::class . 'TEST_MEMBER_SESSION',
                            'Member evaluate session is true'
                        ));

                        return true;
                    }
                }
            }

            $rule->addExceptionData(_t(
                self::class . 'TEST_MEMBER_SESSION_FALSE',
                'Member evaluate is false'
            ));
        } else {
            if (self::evaluateSession($sessionLog, $requestLog, $rule)) {
                return true;
            }
        }

        $rule->addExceptionData(_t(
            self::class . 'TEST_FALSE',
            'Evaluate is false'
        ));

        return false;
    }

    public static function evaluateSession(
        SessionLog $sessionLog,
        RequestLog $requestLog,
        self $rule,
        bool $global = false
    ): bool {
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
                $permission = in_array($rule->IPAddress, ['Allowed', 'Allowed for group', 'Allowed for permission'])
                    ? 'Allowed'
                    : 'Denied';
                $ipAddresses = $rule
                    ->RoadblockRequestType()
                    ->RoadblockIPRules()
                    ->filter(['Permission' => $permission])
                    ->column('IPAddress');

                if (!$ipAddresses) {
                    $rule->addExceptionData(_t(
                        self::class . 'TEST_NO_IPADDRESS',
                        'No IP addresses of type {allowed} set for {requestType}',
                        ['allowed' => $permission, 'requestType' => $rule->RoadblockRequestType()->Title]
                    ));

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
                        $rule->addExceptionData(_t(
                            self::class . 'TEST_IPADDRESS_ALLOWED',
                            'IP address of type {global} is allowed for {requestType}',
                            [
                                'global' => $ipAddress,
                                'requestType' => $rule->RoadblockRequestType()->Title,
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

                        $rule->addExceptionData(_t(
                            self::class . 'TEST_IPADDRESS_ALLOWED_FALSE',
                            'IP address of type {global} failed permission for {requestType}',
                            [
                                'global' => $ipAddress,
                                'requestType' => $rule->RoadblockRequestType()->Title,
                            ]
                        ));
                    }
                } else {
                    if ($global) {
                        if (!in_array($filter['IPAddress'], $ipAddresses)) {
                            $rule->addExceptionData(_t(
                                self::class . 'TEST_IPADDRESS_DENIE',
                                'IP address of type {global} is not denied for {requestType}',
                                [
                                    'global' => $filter['IPAddress'],
                                    'requestType' => $rule->RoadblockRequestType()->Title,
                                ]
                            ));

                            return true;
                        }

                        $rule->addExceptionData(_t(
                            self::class . 'TEST_IPADDRESS_DENIE_FALSE',
                            'IP address of type {global} is denied for {requestType}',
                            [
                                'global' => $filter['IPAddress'],
                                'requestType' => $rule->RoadblockRequestType()->Title,
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
                $rule->addExceptionData(_t(
                    self::class . 'TEST_NO_TYPE',
                    'No requests of type {type}, verb {verb}, ipaddress {ipAddress}',
                    [
                        'ipAddress' => $rule->IPAddress,
                        'type' => $rule->RoadblockRequestType()->Title,
                        'verb' => $rule->Verb,
                    ]
                ));

                return true;
            }

            if ($requestLogs->count() < $rule->Count) {
                $rule->addExceptionData(_t(
                    self::class . 'TEST_TYPE_COUNT',
                    'Request count of {typeCount} is less than {typeNumber} for verb {verb}, ipaddress {ipAddress}',
                    [
                        'ipAddress' => $rule->IPAddress,
                        'typeCount' => $requestLogs->count(),
                        'typeNumber' => $rule->Count,
                        'verb' => $rule->Verb,
                    ]
                ));

                return true;
            }

            $rule->addExceptionData(_t(
                self::class . 'TEST_TYPE_COUNT_FALSE',
                'Request count of {typeCount} is greater than or equal ' .
                'to {typeNumber} for verb {verb}, ipaddress {ipAddress}',
                [
                    'ipAddress' => $rule->IPAddress,
                    'typeCount' => $requestLogs->count(),
                    'typeNumber' => $rule->Count,
                    'verb' => $rule->Verb,
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
            $rule->addExceptionData(_t(
                self::class . 'TEST_EXTEND_SESSION',
                'Extend evaluate session is true'
            ));

            return true;
        }

        $rule->addExceptionData(_t(
            self::class . 'TEST_EXTEND_SESSION_FALSE',
            'Extend evaluate session is false'
        ));

        return false;
    }

    public static function broadcastOnBlock(self $rule, RequestLog $requestLog): void
    {
        if (!$requestLog->IPAddressBroadcastOnBlock) {
            return;
        }

        $ipAddress = RoadblockIPRule::get()->filter([
            'IPAddress' => $requestLog->IPAddress,
            'Permission' => 'Denied',
        ])->first();

        if (!$ipAddress) {
            $ipAddress = RoadblockIPRule::create([
                'Description' => _t(
                    self::class . '.BROADCAST_DESCRIPTION',
                    'Auto blocking from {rule).',
                    ['rule' => $rule->Title]
                ),
                'IPAddress' => $requestLog->IPAddress,
                'Permission' => 'Denied',
            ]);
        }

        $rules = self::get()->filter([
            'IPAddressReceiveOnBlock' => 1,
        ]);

        foreach ($rules as $rule) {
            $rule->RoadblockRequestType()->RoadblockIPRules()->add($ipAddress);
        }
    }

    public static function runTests(): void
    {
        $rules = self::get()->filter(['Status' => 'Enabled']);

        if (!$rules) {
            return;
        }

        foreach ($rules as $rule) {
            $rule->testRule();
        }
    }

}
