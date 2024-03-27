<?php

namespace aSmithSummer\Roadblock\Model;

use ReflectionClass;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Control\HTTPResponse;
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
        'Title' => 'Varchar(64)',
        'Level' => "Enum('Global,Member,Session','Session')",
        'LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
        'LoginAttemptsNumber' => 'Int',
        'LoginAttemptsStartOffset' => 'Int',
        'Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
        'IPAddress' => "Enum('Any,Allowed,Allowed for group, Allowed for permission,Denied','Any)",
        'StatusCodes' => 'Varchar(255)',
        'Count' => 'Int',
        'StartOffset' => 'Int',
        'IPAddressBroadcastOnBlock' => 'Boolean',
        'IPAddressReceiveOnBlock' => 'Boolean',
        'ExcludeGroup' => 'Boolean',
        'ExcludeUnauthenticated' => 'Boolean',
        'ExcludePermission' => 'Boolean',
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'ExpiryOverride' => 'Int',
        'Status' => "Enum('Enabled,Disabled','Disabled')",
        'Permission' => 'Varchar(255)',
        'NotifyIndividuallySubject' => 'Varchar(255)',
        'NotifyMemberContent' => 'HTMLText',
    ];

    private static array $has_one = [
        'Group' => Group::class,
    ];

    private static array $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
        'RoadblockRuleInspectors' => RoadblockRuleInspector::class,
    ];

    private static array $many_many = [
        'RoadblockRequestTypes' => RoadblockRequestType::class,
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
        'getDescriptionNice' => 'Description',
        'getOnTriggerNice' => 'On triggering',
        'getRoadblockCount' => 'Roadblocks',
        'RoadblockExceptions.Count' => 'Exceptions',
        'RoadblockRuleInspectors.Count' => 'Tests',
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

        $fields->removeByName('StatusCodes');

        $response = new ReflectionClass(HTTPResponse::class);
        $options = $response->getStaticPropertyValue('status_codes');

        $statusCodes = ListboxField::create('StatusCodes', 'Status codes', $options);
        $fields->insertAfter('IPAddress', $statusCodes);

        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        $order = [
            'Verb' => 'LoginAttemptsStartOffset',
            'Cumulative' => 'Permission',
            'Score' => 'Cumulative',
            'ExpiryOverride' => 'Score',
            'Status' => 'ExpiryOverride',
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
                self::class . '.EDIT_INSTRUCTIONS',
                'If any field group evaluates to true, the rule is pass without triggering an exception.'
            )
        );

        $descriptions = [
            'Count' => _t(self::class . '.EDIT_TYPE2_DESCRIPTION', 'And number of requests greater than or ' .
                'equal to<br/>Set to 1 with offset set to 0 to just evaluate this request'),
            'Cumulative' => _t(self::class . '.EDIT_CUMULATIVE_DESCRIPTION', 'Cumulative scores add each time, ' .
                'non-cumulative will only count once.'),
            'ExcludeGroup' => _t(self::class . '.EDIT_GROUP_DESCRIPTION', 'If excluded, authenticated members ' .
                'in this group will fail'),
            'ExcludePermission' => _t(self::class . '.EDIT_PERMISSION_DESCRIPTION', 'If excluded, ' .
                'authenticated members with permission and unauthenticated members with this permission will fail' .
                '<br/>If not excluded authenticated members without permission will fail and unauthenticated members ' .
                'will pass'),
            'ExcludeUnauthenticated' => _t(self::class . '.EDIT_GROUP2_DESCRIPTION', 'If excluded, ' .
                'unauthenticated members in this group will fail'),
            'IPAddress' => _t(self::class . '.EDIT_IPADDRESSL_DESCRIPTION', 'Allowed = list of IP addresses ' .
                'attached to request type with \'Allowed\'. If in this list will pass.' .
                '<br/>Allowed for group = Allowed combined with group logic.' .
                '<br/>Allowed for permission = Allowed combined with permission logic.' .
                '<br/>Denied = list of IP addresses attached to request type with \'Denied\'. ' .
                'If in this list will fail (if no superceeding success, and no allow for same ip in the request type).'),
            'IPAddressBroadcastOnBlock' => _t(self::class . '.EDIT_IPADDRESS2_DESCRIPTION', 'If blocked will ' .
                'add IP address automatically to recieve on block rule\'s request type'),
            'IPAddressReceiveOnBlock' => _t(self::class . '.EDIT_IPADDRESS3_DESCRIPTION', 'If a block occurs ' .
                'somewhere else, it will be added to this rule\'s request type.'),
            'Level' => _t(self::class . '.EDIT_LEVEL_DESCRIPTION', 'Global = IPAddress, Member = member, ' .
                'Session = current session.'),
            'LoginAttemptsNumber' => _t(self::class . '.EDIT_LOGIN2_DESCRIPTION', 'And number of requests ' .
                'greater than or equal to'),
            'LoginAttemptsStartOffset' => _t(self::class . '.EDIT_LOGIN3_DESCRIPTION', 'Within the last x ' .
                    'seconds<br/>Set to 0 for just this request'),
            'LoginAttemptsStatus' => _t(self::class . '.EDIT_LOGIN_DESCRIPTION', 'Login attempt attached to a ' .
                'request of this status<br/>Level of member will look at history for authenticated member' .
                '<br/>Level of Global or Session will look at IPAddress if no member'),
            'Score' => _t(self::class . '.EDIT_SCORE_DESCRIPTION', 'Score contributes to the roadblock record. ' .
                '<br/>Scores over 100.00 will block the session.' .
                '<br/>Scores of 0.00 will block the session.' .
                '<br/>Scores under 0.00 will reduce score and provide info notification.'),
            'StartOffset' => _t(self::class . '.EDIT_TYPE3_DESCRIPTION', 'Within the last x seconds' .
                '<br/>Set to 0 for just this request'),
            'StatusCodes' => _t(self::class . '.EDIT_STATUS_CODE_DESCRIPTION', 'The response status. ' .
               '<br/><strong>If set then this rule will run after the request has been processed.</strong>'),
        ];

        $fields->insertBefore('Title', $instructions);

        foreach ($descriptions as $fieldName => $description) {
            $field = $fields->dataFieldByName($fieldName);
            $field->setDescription($description);
        }

        return $fields;
    }

    public function getRoadblockCount(): int
    {
        return Roadblock::get()->filter([
            'Score:GreaterThanOrEqual' => 100.00,
            'Rules.Title' => $this->Title,
        ])->count();
    }

    public function getDescriptionNice(): DBHTMLText
    {
        $level = $this->Level === 'Global' ? 'IP address' : $this->Level;
        $login = '';

        if ($this->LoginAttemptsNumber) {
            $loginType = $this->LoginAttemptsStatus === 'Any' ? '' : $this->LoginAttemptsStatus;
            $login = 'With <strong>' . $this->LoginAttemptsNumber . ' ' . $loginType . '</strong> login attempts in ' .
                'the last <strong>' . $this->LoginAttemptsStartOffset . '</strong> seconds.<br/>';
        }

        $verb = '';

        if ($this->Verb !== 'Any') {
            $verb = 'The request action is a <strong>' . $this->Verb . '</strong><br/>';
        }

        $group = '';

        if ($this->group()->exists()) {
            $group = 'The member <strong>' . ($this->ExcludedGroup ? 'is' : 'is not') . '</strong> in the group ' .
                '<strong>' . $this->group()->Title . '</strong>';

            if ($this->ExcludeUnauthenticated) {
                $group .= ' or there is <strong>no authenticated member</strong>.';
            }
            $group .= '<br/>';
        }

        $permission = '';

        if ($this->Permission) {
            $permission = 'The member <strong>' . ($this->ExcludePermission ? 'does' : 'does not') . '</strong> ' .
                'have the permission <strong>' . $this->Permission . '</strong><br/>';
        }

        $count = '';

        if ($this->Count) {
            $count .= 'The number of requests in the last <strong>' . $this->StartOffset . '</strong> seconds ' .
            'is greater or equal to <strong>' . $this->Count . '</strong><br/>';
        }

        $ip = '';

        if ($this->IPAddress !== 'Any') {
            $ip .= 'The ip address is <strong>' . $this->IPAddress . '</strong><br/>';
        }

        $status = '';

        if ($this->StatusCodes) {
            $status .= 'The response status is <strong>' . $this->StatusCodes  . '</strong><br/>' .
                '<strong>**NB** This rule will be run only after the request has been processed.</strong><br/>';
        }

        $receive = '';

        if ($this->IPAddressReceiveOnBlock) {
            $receive .= 'When another rule broadcasts an IP to be blocked this rule will add it to the list of ' .
                'denied IP addresses.<br/>';
        }

        $text = _t(
            self::class . '.DESCRIPTION',
            '<p>Any request looking at a <strong>{Level}</strong>, where the request is of the type(s) ' .
                '<strong>{Types}</strong>. <br/>{Login}{Verb}{Count}{IPAddress}{Status}' .
                '{Group}{Permission}{Receive}</p>'
            ,[
                'Level' => $level,
                'Types' => $this->getRoadblockRequestTypesCSV(),
                'Login' => $login,
                'Verb' => $verb,
                'IPAddress' => $ip,
                'Count' => $count,
                'Group' => $group,
                'Permission' => $permission,
                'Receive' => $receive,
                'Status' => $status,
            ]
        );

        $this->extend('getDescriptionNice', $text);

        $html = DBHTMLText::create();
        $html->setValue($text);

        return $html;
    }

    public function getOnTriggerNice(): DBHTMLText
    {
        $score = '<li>If no roadblock record exists a new one will be created.</li>';

        $cumulative = '';

        if ($this->Cumulative === 'Yes') {
            $cumulative .= ' <strong>if this is the first break of the rule</strong>';
        }

        switch (true) {
            case $this->Score < 0.0:
                $score .= '<li><strong>' . $this->Score . '</strong> will be subtracted from the roadblock.</li>';
                $score .= '<li>If the roadblock has a score over <strong>' . Roadblock::$threshold . '</strong> the ' .
                    'current request will be blocked.</li>';

                break;

            case $this->Score == 0.0:
                $score .= '<li>The current request will be blocked but the score will not change.</li>';

                break;

            case $this->Score > 0.0:
                $score .= '<li><strong>' . $this->Score . '</strong> will be added to the roadblock'
                    . $cumulative . '.</li>';
                $score .= '<li>If the roadblock has a score over <strong>' . Roadblock::$threshold . '</strong> the ' .
                    'current request will be blocked.</li>';
        }

        $broadcast = '';

        if ($this->IPAddressBroadcastOnBlock) {
            $broadcast .= '<li>The ip address will be added to the block list of any other rules listening for' .
                'this event</li>';
        }

        $notify = '';

        if ($this->NotifyIndividuallySubject) {
            $notify = 'The member will receive an email with the subject ' . $this->NotifyIndividuallySubject;
        }

        $text = _t(
            self::class . '.ON_TRIGGER',
            '<ul>{Score}{Broadcast}{Notification}</ul>'
            ,[
                'Score' => $score,
                'Broadcast' => $broadcast,
                'Notification' => $notify,
            ]
        );

        $html = DBHTMLText::create();
        $html->setValue($text);

        return $html;
    }

    public function getRoadblockRequestTypesCSV(): string
    {
        $responseArray = $this->RoadblockRequestTypes()->column('Title');

        return implode(',', $responseArray);
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
            'StatusCodes' => 'StatusCodes',
            'IPAddress' => 'IPAddress',
            'IPAddressBroadcastOnBlock' => 'IPAddressBroadcastOnBlock',
            'IPAddressReceiveOnBlock' => 'IPAddressReceiveOnBlock',
            'ExcludeGroup' => 'ExcludeGroup',
            'ExcludeUnauthenticated' => 'ExcludeUnauthenticated',
            'ExcludePermission' => 'ExcludePermission',
            'Score' => 'Score',
            'Cumulative' => 'Cumulative',
            'Status' => 'Status',
            'ExpiryOverride' => 'ExpiryOverride',
            'Group.Code' => 'Group',
            'Permission' => 'Permission',
            'getRoadblockRequestTypesCSV' => 'RoadblockRequestTypes',
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
            if ($this->ExcludeGroup && $this->ExcludeUnauthenticated) {
                if (($member && !$member->inGroup($group)) || !$member) {
                    $this->addExceptionData(_t(
                        self::class . '.TEST_EXCLUDE_GROUP_EXCLUDE_UNAUTHENTICATED',
                        'Excluded Group for member {member} or Included unauthenticated that is not in {group}',
                        [
                            'group' => $group->Title,
                            'member' => $member ? $member->FirstName : '(none)',
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_EXCLUDE_GROUP_EXCLUDE_UNAUTHENTICATED_FALSE',
                    'Excluded Group for member {member}, or excluded unauthenticated that is in {group}',
                    [
                        'group' => $group->Title,
                        'member' => $member ? $member->FirstName : '(none)',
                    ]
                ));
            } else if ($this->ExcludeGroup) {
                if ($member && !$member->inGroup($group)) {
                    $this->addExceptionData(_t(
                        self::class . '.TEST_EXCLUDE_2_GROUP',
                        'Excluded Group for member {member} that is not in {group}',
                        ['group' => $group->Title,
                            'member' => $member ? $member->FirstName : '(none)',
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_EXCLUDE_2_GROUP_FALSE',
                    'Excluded Group for member {member} that is in {group}',
                    [
                        'group' => $group->Title,
                        'member' => $member ? $member->FirstName : '(none)',
                    ]
                ));
            } else if($this->ExcludeUnauthenticated) {
                if (!$member) {
                    $this->addExceptionData(_t(
                        self::class . '.TEST_EXCLUDE_3_GROUP',
                        'Included unauthenticated for Group {group}',
                        [
                            'group' => $group->Title,
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_EXCLUDE_3_GROUP_FALSE',
                    'Exluded unauthenticated for Group {group}',
                    ['group' => $group->Title,
                        'member' => $member ? $member->FirstName : '(none)',
                    ]
                ));
            } else {
                if ($member && $member->inGroup($group)) {
                    $this->addExceptionData(_t(
                        self::class . '.TEST_INCLUDE_GROUP',
                        'Included Group for member {member} that is in {group}',
                        ['group' => $group->Title,
                            'member' => $member ? $member->FirstName : '(none)',
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_INCLUDE_GROUP_FALSE',
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
                        self::class . '.TEST_EXCLUDE_PERMISSION',
                        'Excluded Permission for member {member} that is not in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission,
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_EXCLUDE_PERMISSION_FALSE',
                    'Excluded Permission for member {member} that is in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission,
                    ]
                ));
            } else {
                if ($member !== null &&Permission::checkMember($member, $permission)) {
                    $this->addExceptionData(_t(
                        self::class . '.TEST_INCLUDE_PERMISSION',
                        'Included Permission for member {member} that is in {permission}',
                        ['member' => $member ? $member->FirstName : '(none)',
                            'permission' => $permission,
                        ]
                    ));

                    return true;
                }

                $this->addExceptionData(_t(
                    self::class . '.TEST_INCLUDE_PERMISSION_FALSE',
                    'Included Permission for member {member} that is not in {permission}',
                    ['member' => $member ? $member->FirstName : '(none)',
                        'permission' => $permission,
                    ]
                ));
            }
        }

        return false;
    }

    public function evaluateLoginAttempts(?Member $member, ?RequestLog $requestLog, string $time): bool
    {
        $loginAttemptsNumber = $this->LoginAttemptsNumber;

        if ($loginAttemptsNumber) {
            $logins = $this->getLoginAttempts($member, $requestLog, $time);

            if (!$logins) {
                $this->addExceptionData(_t(self::class . '.TEST_NO_LOGIN_ATTEMPTS', 'There is no login attempt'));

                return true;
            }

            if ($logins->count() <= $loginAttemptsNumber) {
                $this->addExceptionData(_t(
                    self::class . '.TEST_LOGIN_ATTEMPTS_COUNT',
                    'Login attempt count of {loginCount} is less than or equal to ' .
                    'Login Attempt Number of {loginAttemptNumber}',
                    [
                        'loginAttemptNumber' => $loginAttemptsNumber,
                        'loginCount' => $logins->count(),
                    ]
                ));

                return true;
            }

            $this->addExceptionData(_t(
                self::class . '.TEST_LOGIN_ATTEMPTS_COUNT_FALSE',
                'Login attempt count of {loginCount} is greater than ' .
                'Login Attempt Number of {loginAttemptNumber}',
                [
                    'loginAttemptNumber' => $loginAttemptsNumber,
                    'loginCount' => $logins->count(),
                ]
            ));
        }

        return false;
    }

    public function prepareCurrentTest(?RoadblockRuleInspector $test): void
    {
        $this->resetExceptionData();
        $test->prepareCurrentTest();
        $this->currentTest = $test;
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
                $this->prepareCurrentTest($test);
                $sessionLog = $test->getSessionLog();
                $requestLog = $test->getRequestLog();

                if (!$requestLog || !$sessionLog) {
                    $test->LastRun = $sessionLog->LastAccessed;
                    $test->Result = $this->getExceptionData();
                    $test->write();

                    continue;
                }

                self::evaluate($sessionLog, $requestLog, $this);
                $test->LastRun = $sessionLog->LastAccessed;
                $test->Result = $this->getExceptionData();
                $test->write();
            }
        }

        return $this->getExceptionData();
    }

    public function testInspector(RoadblockRuleInspector $inspector, $updateInspector = true): string
    {
        if (!$inspector) {
            return '';
        }

        $this->prepareCurrentTest($inspector);
        $sessionLog = $inspector->getSessionLog();
        $requestLog = $inspector->getRequestLog();

        if (!$requestLog || !$sessionLog) {
            $inspector->LastRun = $sessionLog->LastAccessed;
            $inspector->Result = $this->getExceptionData();
            $inspector->write();
        }

        self::evaluate($sessionLog, $requestLog, $this);

        if ($updateInspector) {
            $inspector->LastRun = $sessionLog->LastAccessed;
            $inspector->Result = $this->getExceptionData();
            $inspector->write();
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
    public function getLoginAttempts(?Member $member, ?RequestLog $requestLog, string $time)
    {
        $filter = [];

        if ($member) {
            $filter['MemberID'] = $member->ID;
        } else if($requestLog) {
            $filter['IP'] = $requestLog->IPAddress;
        }

        if ($this->LoginAttemptsStatus !== 'Any') {
            $filter['Status'] = $this->LoginAttemptsStatus;
        }

        $test = $this->currentTest;

        if ($test) {
            return $test->getLoginAttempts()
                ->filter($filter)
                ->filterByCallback(function ($loginAttempt) use ($time) {
                    return $loginAttempt->Created >= $time;
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

    public function importRoadblockRequestTypes(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RoadblockRequestTypes']) {
            return;
        }

        // Removes all relationships with request type
        $this->RoadblockRequestTypes()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifier) {
            $filter = ['Title' => $identifier];
            $roadblockRequestTypes = RoadblockRequestType::get()->filter($filter);

            if (!$roadblockRequestTypes) {
                continue;
            }

            foreach ($roadblockRequestTypes as $roadblockRequestType) {
                $this->RoadblockRequestTypes()->add($roadblockRequestType);
            }
        }
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, self $rule): bool
    {
        if ($rule->getCurrentTest() === null &&
            ($rule->Status === 'Disabled' || !$rule->RoadblockRequestTypes()->exists())) {
            $rule->addExceptionData(_t(
                self::class . '.TEST_DISABLED',
                '{rule} is disabled',
                ['rule' => $rule->Title]
            ));

            return true;
        }

        $rule->addExceptionData(_t(
            self::class . '.TEST_ENABLED',
            '{rule} is enabled',
            ['rule' => $rule->Title]
        ));

        $member = $rule->getCurrentUser();

        if ($rule->Level === 'Global') {
            if (self::evaluateSession($sessionLog, $requestLog, $rule, true)) {
                $rule->addExceptionData(_t(self::class . '.TEST_GLOBAL_TRUE', 'Global evaluation true'));

                return true;
            }

            $rule->addExceptionData(_t(self::class . '.TEST_GLOBAL_FALSE', 'Global evaluation false'));
        } elseif ($rule->Level === 'Member') {
            if (!$member) {
                $rule->addExceptionData(_t(self::class . '.TEST_NO_MEMBER', 'No member'));

                return true;
            }

            $rule->addExceptionData(_t(
                self::class . '.TEST_MEMBER',
                'Member {firstName} has been found',
                ['firstName' => $member->FirstName]
            ));

            $time = DBDatetime::create()->modify($sessionLog->LastAccessed);
            $loginTime = $time->modify('-' . $rule->LoginAttemptsStartOffset . ' seconds')
                ->format('y-MM-dd HH:mm:ss');
            if ($rule->evaluateLoginAttempts($member, $requestLog, $loginTime)) {
                return true;
            }

            $extension = $rule->extend('updateEvaluateMember', $sessionLog, $requestLog, $rule);

            if ($extension) {
                $status = max($extension);

                if ($status) {
                    $rule->addExceptionData(_t(
                        self::class . '.TEST_EXTEND_MEMBER',
                        'Extend evaluate member is true'
                    ));

                    return true;
                }
            }

            $rule->addExceptionData(_t(
                self::class . '.TEST_EXTEND_MEMBER_FALSE',
                'Extend evaluate member is false'
            ));

            //loop all sessions for member
            $sessionLogs = $rule->getSessionLogs($sessionLog, $member);

            if ($sessionLogs) {
                foreach ($sessionLogs as $memberSessionLog) {
                    $status = self::evaluateSession($memberSessionLog, $requestLog, $rule);

                    if ($status) {
                        $rule->addExceptionData(_t(
                            self::class . '.TEST_MEMBER_SESSION',
                            'Member evaluate session is true'
                        ));

                        return true;
                    }
                }
            }

            $rule->addExceptionData(_t(
                self::class . '.TEST_MEMBER_SESSION_FALSE',
                'Member evaluate is false'
            ));
        } else {
            if (self::evaluateSession($sessionLog, $requestLog, $rule)) {
                return true;
            }
        }

        $rule->addExceptionData(_t(
            self::class . '.TEST_FALSE',
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
        if ($rule->getCurrentTest() === null && $rule->Status === 'Disabled') {
            return true;
        }

        $member = $rule->getCurrentUser();

        $types = $rule->RoadblockRequestTypes()->column('Title');

        $filter = [
            'Types:PartialMatch' => $types,
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

            // TODO make this one call
            $ipAddresses = [];

            foreach($rule->RoadblockRequestTypes() as $requestType) {
                $newIpAddresses = $requestType->RoadblockIPRules()
                    ->filter([
                        'Permission' => $permission,
                        'Status' => 'Enabled'
                    ])
                    ->column('IPAddress');
                if ($permission === 'Denied') {
                    $excludedIPAddresses = $requestType->RoadblockIPRules()
                        ->filter([
                            'Permission' => 'Allowed',
                            'Status' => 'Enabled'
                        ])
                        ->column('IPAddress');
                    $newIpAddresses = array_diff($newIpAddresses, $excludedIPAddresses);
                }
                $ipAddresses = array_merge($ipAddresses, $newIpAddresses);
            }

            if (!$ipAddresses) {
                $rule->addExceptionData(_t(
                    self::class . '.TEST_NO_IPADDRESS',
                    'No IP addresses of type {allowed} set for {requestTypes}',
                    ['allowed' => $permission, 'requestTypes' => $rule->getRoadblockRequestTypesCSV()]
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
                        self::class . '.TEST_IPADDRESS_ALLOWED',
                        'IP address of type {global} is allowed for {requestTypes}',
                        [
                            'global' => $ipAddress,
                            'requestTypes' => $rule->getRoadblockRequestTypesCSV(),
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
                        self::class . '.TEST_IPADDRESS_ALLOWED_FALSE',
                        'IP address of type {global} failed permission for {requestTypes}',
                        [
                            'global' => $ipAddress,
                            'requestTypes' => $rule->getRoadblockRequestTypesCSV(),
                        ]
                    ));
                }
            } else {
                if ($global) {
                    if (!in_array($filter['IPAddress'], $ipAddresses)) {
                        $rule->addExceptionData(_t(
                            self::class . '.TEST_IPADDRESS_DENIE',
                            'IP address of type {global} is not denied for {requestTypes}',
                            [
                                'global' => $filter['IPAddress'],
                                'requestTypes' => $rule->getRoadblockRequestTypesCSV(),
                            ]
                        ));

                        return true;
                    }

                    $rule->addExceptionData(_t(
                        self::class . '.TEST_IPADDRESS_DENIE_FALSE',
                        'IP address of type {global} is denied for {requestTypes}',
                        [
                            'global' => $filter['IPAddress'],
                            'requestTypes' => $rule->getRoadblockRequestTypesCSV(),
                        ]
                    ));
                } else {
                    $filter['IPAddress'] = $ipAddresses;
                }
            }
        }

        $status = '';

        if ($rule->StatusCodes) {
            $filter['StatusCode'] = explode(',', trim($rule->StatusCodes ?? '', '[]'));
            $status = 'status ' . $rule->StatusCodes . ', ';
        }

        $time = DBDatetime::create()->modify($sessionLog->LastAccessed);
        $requestTime = $time->modify('-' . $rule->StartOffset . ' seconds')
            ->format('y-MM-dd HH:mm:ss');
        $requestLogs = $rule->getRequestLogs($filter, $requestTime);

        if ($exclude) {
            $requestLogs->exclude($exclude);
        }

        if (!$requestLogs->count()) {
            $rule->addExceptionData(_t(
                self::class . '.TEST_NO_TYPE',
                'No requests of {status}type {types}, verb {verb}, ipaddress {ipAddress}',
                [
                    'status' => $status,
                    'ipAddress' => $rule->IPAddress,
                    'types' => $rule->getRoadblockRequestTypesCSV(),
                    'verb' => $rule->Verb,
                ]
            ));

            return true;
        }

        if ($requestLogs->count() < $rule->Count) {
            $rule->addExceptionData(_t(
                self::class . '.TEST_TYPE_COUNT',
                'Request count of {typeCount} is less than {typeNumber} for {status}verb {verb}, ipaddress {ipAddress}',
                [
                    'ipAddress' => $rule->IPAddress,
                    'typeCount' => $requestLogs->count(),
                    'typeNumber' => $rule->Count,
                    'status' => $status,
                    'verb' => $rule->Verb,
                ]
            ));

            return true;
        }

        $rule->addExceptionData(_t(
            self::class . '.TEST_TYPE_COUNT_FALSE',
            'Request count of {typeCount} is greater than or equal ' .
            'to {typeNumber} for {status}verb {verb}, ipaddress {ipAddress}',
            [
                'ipAddress' => $rule->IPAddress,
                'typeCount' => $requestLogs->count(),
                'typeNumber' => $rule->Count,
                'status' => $status,
                'verb' => $rule->Verb,
            ]
        ));

        if ($rule->IPAddress !== 'Allowed for group' && $rule->evaluateGroup($member)) {
            return true;
        }

        if ($rule->IPAddress !== 'Allowed for permission' && $rule->evaluatePermission($member)) {
            return true;
        }

        $exts = $rule->extend('updateEvaluateSession', $sessionLog, $requestLog, $rule, $global);
        $status = $exts ? max($exts) : false;

        if ($status) {
            $rule->addExceptionData(_t(
                self::class . 'TEST_EXTEND_SESSION',
                'Extend evaluate session is true'
            ));

            return true;
        }

        $rule->addExceptionData(_t(
            self::class . '.TEST_EXTEND_SESSION_FALSE',
            'Extend evaluate session is false'
        ));

        $time = DBDatetime::create()->modify($sessionLog->LastAccessed);
        $loginTime = $time->modify('-' . $rule->LoginAttemptsStartOffset . ' seconds')
            ->format('y-MM-dd HH:mm:ss');
        if ($rule->Level === 'Session' && $rule->evaluateLoginAttempts(null, $requestLog, $loginTime)) {
            return true;
        }

        return false;
    }

    public static function broadcastOnBlock(self $rule, RequestLog $requestLog): void
    {
        if (!$rule->IPAddressBroadcastOnBlock) {
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
            $requestTypes = $rule->RoadblockRequestTypes();

            foreach($requestTypes as $type) {
                $type->RoadblockIPRules()->add($ipAddress);
            }
        }
    }

    public static function runTests(): void
    {
        $rules = self::get();

        if (!$rules) {
            return;
        }

        foreach ($rules as $rule) {
            $rule->testRule();
        }
    }

}
