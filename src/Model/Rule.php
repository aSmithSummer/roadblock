<?php

namespace aSmithSummer\Roadblock\Model;

use ReflectionClass;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
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

class Rule extends DataObject
{
    use Configurable;
    private static array $other_middleware = [];

    private ?RuleInspector $currentAssessment = null;

    private array $infringementData = [];

    private static array $db = [
        'Title' => 'Varchar(100)',
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
        'Middleware' => 'Varchar(256)',
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
        'Infringements' => Infringement::class,
        'RuleInspectors' => RuleInspector::class,
    ];

    private static array $many_many = [
        'RequestTypes' => RequestType::class,
    ];

    private static array $belongs_many_many = [
        'Roadblocks' => Roadblock::class,
    ];

    private static string $table_name = 'Rule';

    private static string $plural_name = 'Rules';

    private static array $indexes = [

        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'getRuleDescriptionHTML' => 'Description',
        'getTriggeringDescriptionHTML' => 'On triggering',
        'getRoadblockCount' => 'Roadblocks',
        'Infringements.Count' => 'Infringements',
        'RuleInspectors.Count' => 'Assessments',
        'Status' => 'Status',
    ];

    private static string $default_sort = 'Title';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('Permission');
        $fields->removeByName('Controller');
        $fields->removeByName('StatusCodes');

        $permissions = Permission::get()->columnUnique('Code');
        $permissions[] = 'CMS_ACCESS';
        $permissions[] = 'CMS_ACCESS_LeftAndMain';
        sort($permissions);
        $permissions = array_combine($permissions, $permissions);

        $permission = DropdownField::create('Permission', 'Permission', $permissions)
            ->setHasEmptyDefault(true)->setEmptyString('(none)');
        $fields->insertAfter('ExcludeUnauthenticated', $permission);

        $middleware = DropdownField::create(
            'Middleware',
            'Middleware gateway',
            self::config()->get('other_middleware')
        )->setHasEmptyDefault(true)->setEmptyString('SessionLogMiddleware (default)');

        $fields->insertAfter('ExcludePermission', $middleware);

        $response = new ReflectionClass(HTTPResponse::class);
        $statusCodes = ListboxField::create(
            'StatusCodes',
            'Status codes',
            $response->getStaticPropertyValue('status_codes')
        );
        $fields->insertAfter('IPAddress', $statusCodes);

        $this->setFieldOrder($fields);

        $instructions = literalField::create(
            'Instructions',
            _t(
                self::class . '.EDIT_INSTRUCTIONS',
                'If any field group evaluates to true, the rule is pass without triggering an infringement.'
            )
        );

        $fields->insertBefore('Title', $instructions);

        $this->setFieldDescriptions($fields);

        return $fields;
    }

    public function setFieldOrder(FieldList $fields): FieldList
    {
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
            'Middleware' => 'ExcludePermission',
        ];

        foreach ($order as $fieldName => $after) {
            $field = $fields->dataFieldByName($fieldName);
            $fields->insertAfter($after, $field);
        }

        return $fields;
    }

    public function setFieldDescriptions(FieldList $fields): FieldList
    {
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
            'Middleware' => _t(self::class . '.EDIT_MIDDLEWARE_DESCRIPTION', 'When the rule is to be run, ' .
                'the roadblock evaluate function must be run from a subsequent middleware for this to work.'),
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

    public function getRuleDescriptionHTML(): DBHTMLText
    {
        $level = $this->Level === 'Global' ? 'IP address' : $this->Level;
        $login = '';

        if ($this->LoginAttemptsNumber) {
            $login = sprintf(
            'With <strong>%s %s</strong> login attempts in the last <strong>%s</strong> seconds.<br/>',
                $this->LoginAttemptsNumber,
                $this->LoginAttemptsStatus === 'Any' ? '' : $this->LoginAttemptsStatus,
                $this->LoginAttemptsStartOffset
            );
        }

        $verb = '';

        if ($this->Verb !== 'Any') {
            $verb = sprintf(
                'The request action is a <strong>%s</strong><br/>',
                $this->Verb
            );
        }

        $group = '';

        if ($this->group()->exists()) {
            $group = sprintf(
            'The member <strong>%s</strong> in the group <strong>%s</strong>',
                $this->ExcludedGroup ? 'is' : 'is not',
                $this->group()->Title
            );

            if ($this->ExcludeUnauthenticated) {
                $group .= ' or there is <strong>no authenticated member</strong>.';
            }
            $group .= '<br/>';
        }

        $permission = '';

        if ($this->Permission) {
            $permission = sprintf(
            'The member <strong>%s</strong> have the permission <strong>%s</strong><br/>',
                $this->ExcludePermission ? 'does' : 'does not',
                $this->Permission
            );
        }

        $count = '';

        if ($this->Count) {
            $count .= sprintf(
                'The number of requests in the last <strong>%s</strong> seconds is greater or equal to ' .
                '<strong>%s</strong><br/>',
                $this->StartOffset,
                $this->Count
            );
        }

        $ip = '';

        if ($this->IPAddress !== 'Any') {
            $ip .= sprintf(
                'The ip address is <strong>%s</strong><br/>',
                $this->IPAddress
            );
        }

        $status = '';

        if ($this->StatusCodes) {
            $status .= sprintf(
                'The response status is <strong>%s</strong><br/>' .
                '<strong>**NB** This rule will be run only after the request has been processed.</strong><br/>',
                $this->StatusCodes
            );
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
                'Types' => $this->getRequestTypesForCSV(),
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

        $this->extend('updateDescriptionForGridField', $text);

        $html = DBHTMLText::create();
        $html->setValue($text);

        return $html;
    }

    public function getTriggeringDescriptionHTML(): DBHTMLText
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
            $broadcast .= '<li>The ip address will be added to the block list of any other rules listening for ' .
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

    public function getRequestTypesForCSV(): string
    {
        return implode(',', $this->RequestTypes()->column('Title'));
    }

    /**
     * Custom export for group code as default behaviour is to include class name of group class if groupID is set to zero
     *
     * @return string
     */
    public function getGroupForExportCSV(): string
    {
        if ($this->GroupID === 0) {
            return '';
        }

        return $this->Group()->Code;
    }

    public function getExportFields(): array
    {

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
            'getGroupForExportCSV' => 'Group',
            'Permission' => 'Permission',
            'getRequestTypesForCSV' => 'RequestTypes',
            'NotifyIndividuallySubject' => 'NotifyIndividuallySubject',
            'NotifyMemberContent' => 'NotifyMemberContent',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function evaluateGroup(?Member $member): bool
    {
        $group = $this->Group();

        if (!$group || !$group->ID) {
            return false;
        }

        $returnValue = false;

        if ($this->ExcludeGroup && $this->ExcludeUnauthenticated) {
            if (!$member || !$member->inGroup($group)) {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_GROUP_EXCLUDE_UNAUTHENTICATED';
                $infringementText = 'Excluded Group for member {member} or Included unauthenticated that is not in {group}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_GROUP_EXCLUDE_UNAUTHENTICATED_FALSE';
                $infringementText = 'Excluded Group for member {member}, or excluded unauthenticated that is in {group}';
            }
        } else if ($this->ExcludeGroup) {
            if ($member && !$member->inGroup($group)) {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_2_GROUP';
                $infringementText = 'Excluded Group for member {member} that is not in {group}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_2_GROUP_FALSE';
                $infringementText = 'Excluded Group for member {member} that is in {group}';
            }
        } else if($this->ExcludeUnauthenticated) {
            if (!$member) {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_3_GROUP';
                $infringementText = 'Included unauthenticated for Group {group}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_3_GROUP_FALSE';
                $infringementText = 'Exluded unauthenticated for Group {group}';
            }
        } else {
            if ($member && $member->inGroup($group)) {
                $entity = self::class . '.ASSESSMENT_INCLUDE_GROUP';
                $infringementText = 'Included Group for member {member} that is in {group}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_INCLUDE_GROUP_FALSE';
                $infringementText = 'Included Group for member {member} that is not in {group}';
            }
        }

        $this->addInfringementData(_t(
            $entity,
            $infringementText,
            [
                'group' => $group->Title,
                'member' => $member ? $member->FirstName : '(none)',
            ]
        ));

        return $returnValue;
    }

    public function evaluatePermission(?Member $member): bool
    {
        $permission = $this->Permission;

        if (!$permission) {
            return false;
        }

        $returnValue = false;

        if ($this->ExcludePermission) {
            if (!Permission::checkMember($member, $permission)) {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_PERMISSION';
                $infringementText = 'Excluded Permission for member {member} that is not in {permission}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_EXCLUDE_PERMISSION_FALSE';
                $infringementText = 'Excluded Permission for member {member} that is in {permission}';
            }
        } else {
            if ($member !== null && Permission::checkMember($member, $permission)) {
                $entity = self::class . '.ASSESSMENT_INCLUDE_PERMISSION';
                $infringementText = 'Included Permission for member {member} that is in {permission}';
                $returnValue = true;
            } else {
                $entity = self::class . '.ASSESSMENT_INCLUDE_PERMISSION_FALSE';
                $infringementText = 'Included Permission for member {member} that is not in {permission}';
            }
        }

        $this->addInfringementData(_t(
            $entity,
            $infringementText,
            ['member' => $member ? $member->FirstName : '(none)',
                'permission' => $permission,
            ]
        ));

        return $returnValue;
    }

    public function evaluateLoginAttempts(?Member $member, ?RequestLog $requestLog, string $time): bool
    {
        $loginAttemptsNumber = $this->LoginAttemptsNumber;

        if (!$loginAttemptsNumber) {
            return false;
        }

        $returnValue = false;

        $logins = $this->getLoginAttempts($member, $requestLog, $time);

        if (!$logins) {
            $this->addInfringementData(_t(self::class . '.ASSESSMENT_NO_LOGIN_ATTEMPTS', 'There is no login attempt'));

            return true;
        }

        if ($logins->count() <= $loginAttemptsNumber) {
            $entity = self::class . '.ASSESSMENT_LOGIN_ATTEMPTS_COUNT';
            $infringementText = 'Login attempt count of {loginCount} is less than or equal to ' .
                'Login Attempt Number of {loginAttemptNumber}';
            $returnValue = true;
        } else {
            $entity = self::class . '.ASSESSMENT_LOGIN_ATTEMPTS_COUNT_FALSE';
            $infringementText = 'Login attempt count of {loginCount} is greater than ' .
                'Login Attempt Number of {loginAttemptNumber}';
        }

        $this->addInfringementData(_t(
            $entity,
            $infringementText,
            [
                'loginAttemptNumber' => $loginAttemptsNumber,
                'loginCount' => $logins->count(),
            ]
        ));

        return $returnValue;
    }

    public function prepareCurrentAssessment(?RuleInspector $assessment): void
    {
        $this->resetInfringementData();
        $assessment->prepareCurrentAssessment();
        $this->currentAssessment = $assessment;
    }

    public function getcurrentAssessment(): ?RuleInspector
    {
        return $this->currentAssessment;
    }

    public function assessRule(): string
    {
        $assessmentCases = $this->RuleInspectors();

        if ($assessmentCases) {
            foreach ($assessmentCases as $assessment) {
                $this->prepareCurrentAssessment($assessment);
                $sessionLog = $assessment->getSessionLog();
                $requestLog = $assessment->getRequestLog();

                if (!$requestLog || !$sessionLog) {
                    $assessment->LastRun = $sessionLog->LastAccessed;
                    $assessment->Result = $this->getInfringementData();
                    $assessment->write();

                    continue;
                }

                self::evaluate($sessionLog, $requestLog, $this);
                $assessment->LastRun = $sessionLog->LastAccessed;
                $assessment->Result = $this->getInfringementData();
                $assessment->write();
            }
        }

        return $this->getInfringementData();
    }

    public function assessmentResult(RuleInspector $inspector, $updateInspector = true): ?string
    {
        if (!$inspector) {
            return '';
        }

        $this->prepareCurrentAssessment($inspector);
        $sessionLog = $inspector->getSessionLog();
        $requestLog = $inspector->getRequestLog();

        if (!$requestLog) {
            $inspector->LastRun = $sessionLog->LastAccessed;
            $inspector->Result = $this->getInfringementData();
            $inspector->write();
        }

        self::evaluate($sessionLog, $requestLog, $this);

        if ($updateInspector) {
            $inspector->LastRun = $sessionLog->LastAccessed;
            $inspector->Result = $this->getInfringementData();
            $inspector->write();
        }

        return $this->getInfringementData();
    }

    public function getCurrentUser(): ?Member
    {
        $member = Security::getCurrentUser();

        if ($this->currentAssessment) {
            $member = $this->currentAssessment->MemberID ? $this->currentAssessment->Member() : null;
        }

        return $member;
    }

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint

    /**
     * return a list of login attempts for the current user, or if using the assessment feature a list of
     * login attempts simulated in the inspectors. As the latter is an arraylist we use a callback for backward
     * compatibility with CMS 5.0 and lower
     *
     * @param Member|null $member
     * @param RequestLog|null $requestLog
     * @param string $time
     * @return ArrayList|\SilverStripe\ORM\DataList
     */
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

        $assessment = $this->currentAssessment;

        if ($assessment) {
            return $assessment->getLoginAttempts()
                ->filter($filter)
                ->filterByCallback(function ($loginAttempt) use ($time) {
                    // for backward compatibility with arraylist and time filters in CMS <= 5.0.0
                    return $loginAttempt->Created >= $time;
                });
        }

        $filter['Created:GreaterThanOrEqual'] = $time;

        return LoginAttempt::get()->filter($filter);
    }

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint

    /**
     *  return a list of reqest logs for the current user, or if using the assessment feature a list of
     *  request logs simulated in the inspectors. As the latter is an arraylist we use a callback for backward
     *  compatibility with CMS 5.0 and lower
     *
     * @param array $filter
     * @param string $time
     * @return ArrayList|\SilverStripe\ORM\DataList
     */
    public function getRequestLogs(array $filter, string $time)
    {
        $assessment = $this->currentAssessment;

        if ($assessment) {
            return $assessment->getRequestLogs()
                ->filter($filter)
                ->filterByCallback(function ($requestLog) use ($time) {
                    // for backward compatibility with arraylist and time filters in CMS <= 5.0.0
                    return $requestLog->Created >= $time;
                });
        }

        $filter['Created:GreaterThanOrEqual'] = $time;

        return RequestLog::get()->filter($filter);
    }

    //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public function getSessionLogs(SessionLog $sessionLog, Member $member)
    {
        if ($this->currentAssessment) {
            $sessionLogs = ArrayList::create();
            $sessionLogs->push($sessionLog);
        } else {
            $sessionLogs = SessionLog::getMemberSessions($member);
        }

        return $sessionLogs;
    }

    public function resetInfringementData(): void
    {
        $this->infringementData = [];
    }

    public function addInfringementData(string $infringementData): void
    {
        $array = $this->infringementData;
        $array[] = $infringementData;
        $this->infringementData = $array;
    }

    public function getInfringementData(): string
    {
        return implode(PHP_EOL, $this->infringementData);
    }

    /**
     *  For bulk csv import, column is group code, will create code if not found
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     * @throws \SilverStripe\ORM\ValidationException
     */
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

            $group = Group::create([
                'Code' => $csv,
                'Title' => $csv,
                'Description' => 'Added by roadblock rule import',
            ]);
            $group->write();
        }

        $this->GroupID = $group->ID;
    }

    /**
     *  For bulk csv import, column is comma separated list of request type titles within the cell
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
    public function importRequestTypes(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RequestTypes']) {
            return;
        }

        // Removes all relationships with request type
        $this->RequestTypes()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifier) {
            $filter = ['Title' => $identifier];
            $requestTypes = RequestType::get()->filter($filter);

            if (!$requestTypes) {
                continue;
            }

            foreach ($requestTypes as $requestType) {
                $this->RequestTypes()->add($requestType);
            }
        }
    }

    /**
     * Evaluate the rule against the current session, request and user
     * - Global level rules are intended to be at the request level only
     * - Member level rules are intended to be at the session level for all sessions attributed to a member
     * - Session level rules will look at the current session, the user does not need to be authenticated
     *
     * @param SessionLog $sessionLog
     * @param RequestLog $requestLog
     * @param Rule $rule
     * @return bool
     */
    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, self $rule): bool
    {
        if ($rule->getcurrentAssessment() === null &&
            ($rule->Status === 'Disabled' || !$rule->RequestTypes()->exists())) {
            $rule->addInfringementData(_t(
                self::class . '.ASSESSMENT_DISABLED',
                '{rule} is disabled',
                ['rule' => $rule->Title]
            ));

            return true;
        }

        $rule->addInfringementData(_t(
            self::class . '.ASSESSMENT_ENABLED',
            '{rule} is enabled',
            ['rule' => $rule->Title]
        ));

        $member = $rule->getCurrentUser();

        if ($rule->Level === 'Global') {
            if (self::evaluateSession($sessionLog, $requestLog, $rule, true)) {
                $rule->addInfringementData(_t(self::class . '.ASSESSMENT_GLOBAL_TRUE', 'Global evaluation true'));

                return true;
            }

            $rule->addInfringementData(_t(self::class . '.ASSESSMENT_GLOBAL_FALSE', 'Global evaluation false'));
        } elseif ($rule->Level === 'Member') {
            if (!$member) {
                // Any rule assigned to the member level only applies to authenticated members
                $rule->addInfringementData(_t(self::class . '.ASSESSMENT_NO_MEMBER', 'No member'));

                return true;
            }

            $rule->addInfringementData(_t(
                self::class . '.ASSESSMENT_MEMBER',
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
                    $rule->addInfringementData(_t(
                        self::class . '.ASSESSMENT_EXTEND_MEMBER',
                        'Extend evaluate member is true'
                    ));

                    return true;
                }

                $rule->addInfringementData(_t(
                    self::class . '.ASSESSMENT_EXTEND_MEMBER_FALSE',
                    'Extend evaluate member is false'
                ));
            }

            //loop all sessions for member
            $sessionLogs = $rule->getSessionLogs($sessionLog, $member);

            if ($sessionLogs) {
                foreach ($sessionLogs as $memberSessionLog) {
                    if (self::evaluateSession($memberSessionLog, $requestLog, $rule)) {
                        $rule->addInfringementData(_t(
                            self::class . '.ASSESSMENT_MEMBER_SESSION',
                            'Member evaluate session is true'
                        ));

                        return true;
                    }
                }
            }

            $rule->addInfringementData(_t(
                self::class . '.ASSESSMENT_MEMBER_SESSION_FALSE',
                'Member evaluate session is false'
            ));
        } else {
            if (self::evaluateSession($sessionLog, $requestLog, $rule)) {
                return true;
            }
        }

        $rule->addInfringementData(_t(
            self::class . '.ASSESSMENT_FALSE',
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
        if ($rule->getcurrentAssessment() === null && $rule->Status === 'Disabled') {
            return true;
        }

        $member = $rule->getCurrentUser();

        $types = $rule->RequestTypes()->column('Title');

        $requestFilter = [
            'Types:PartialMatch' => $types,
        ];

        if ($global) {
            $requestFilter['IPAddress'] = $requestLog->IPAddress;
        } else {
            $requestFilter['SessionLogID'] = $sessionLog->ID;
        }

        if ($rule->Verb !== 'Any') {
            $requestFilter['Verb'] = $rule->Verb;
        }

        // IF the permission for the rule is not set to 'Denied' we use exclude for IP addresses rather than filter
        $exclude = [];

        if ($rule->IPAddress !== 'Any') {
            $permission = in_array($rule->IPAddress, ['Allowed', 'Allowed for group', 'Allowed for permission'])
                ? 'Allowed'
                : 'Denied';

            // TODO make this one call
            $requestTypeIPAddresses = [];

            foreach($rule->RequestTypes() as $requestType) {
                // collate IP addresses for each request type, if the rule's permission is 'Denied' we ignore
                // 'Allowed' ip addresses
                $newIPAddresses = [];

                $newRanges = $requestType->IPRules()
                    ->filter([
                        'Permission' => $permission,
                        'Status' => 'Enabled'
                    ])
                    ->map('FromIPNumber', 'ToIPNumber')
                    ->toArray();

                foreach ($newRanges as $from => $to) {
                    $ips = IPRule::getIPsForRange($from, $to);
                    $newIPAddresses = array_merge($newIPAddresses, $ips);
                }

                $newIPAddresses = array_unique($newIPAddresses);

                if ($permission === 'Denied') {
                    $excludedIPAddresses = [];

                    $excludedRanges = $requestType->IPRules()
                        ->filter([
                            'Permission' => 'Allowed',
                            'Status' => 'Enabled'
                        ])
                        ->map('FromIPNumber', 'ToIPNumber')
                        ->toArray();

                    foreach ($excludedRanges as $from => $to) {
                        $ips = IPRule::getIPsForRange($from, $to);
                        $excludedIPAddresses = array_merge($excludedIPAddresses, $ips);
                    }

                    $excludedIPAddresses = array_unique($excludedIPAddresses);
                    
                    $newIPAddresses = array_diff($newIPAddresses, $excludedIPAddresses);
                }
                $requestTypeIPAddresses = array_merge($requestTypeIPAddresses, $newIPAddresses);
            }

            $requestTypeIPAddresses = array_unique($requestTypeIPAddresses);

            if (!$requestTypeIPAddresses) {
                $rule->addInfringementData(_t(
                    self::class . '.ASSESSMENT_NO_IPADDRESS',
                    'No IP addresses of type {permission} set for {requestTypes}',
                    ['permission' => $permission, 'requestTypes' => $rule->getRequestTypesForCSV()]
                ));

                return true;
            }

            if ($permission === 'Allowed') {
                if ($global) {
                    $ipAddress = $requestFilter['IPAddress'];
                } else {
                    $ipAddress = $requestLog->IPAddress;
                    $exclude['IPAddress'] = $requestTypeIPAddresses;
                }

                if (in_array($ipAddress, $requestTypeIPAddresses)) {
                    $rule->addInfringementData(_t(
                        self::class . '.ASSESSMENT_IPADDRESS_ALLOWED',
                        'IP address of type {global} is allowed for {requestTypes}',
                        [
                            'global' => $ipAddress,
                            'requestTypes' => $rule->getRequestTypesForCSV(),
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

                    $rule->addInfringementData(_t(
                        self::class . '.ASSESSMENT_IPADDRESS_ALLOWED_FALSE',
                        'IP address of type {global} failed permission for {requestTypes}',
                        [
                            'global' => $ipAddress,
                            'requestTypes' => $rule->getRequestTypesForCSV(),
                        ]
                    ));
                }
            } else {
                if ($global) {
                    // permission set to 'Denied' and global
                    if (!in_array($requestFilter['IPAddress'], $requestTypeIPAddresses)) {
                        $rule->addInfringementData(_t(
                            self::class . '.ASSESSMENT_IPADDRESS_DENIE',
                            'IP address of type {global} is not denied for {requestTypes}',
                            [
                                'global' => $requestFilter['IPAddress'],
                                'requestTypes' => $rule->getRequestTypesForCSV(),
                            ]
                        ));

                        return true;
                    }

                    $rule->addInfringementData(_t(
                        self::class . '.ASSESSMENT_IPADDRESS_DENIE_FALSE',
                        'IP address of type {global} is denied for {requestTypes}',
                        [
                            'global' => $requestFilter['IPAddress'],
                            'requestTypes' => $rule->getRequestTypesForCSV(),
                        ]
                    ));
                } else {
                    // permission set to 'Denied' and not global
                    $requestFilter['IPAddress'] = $requestTypeIPAddresses;
                }
            }
        }

        $status = '';

        if ($rule->StatusCodes) {
            $requestFilter['StatusCode'] = explode(',', trim($rule->StatusCodes ?? '', '[]'));
            $status = 'status ' . $rule->StatusCodes . ', ';
        }

        $time = DBDatetime::create()->modify($sessionLog->LastAccessed);
        $requestTime = $time->modify('-' . $rule->StartOffset . ' seconds')
            ->format('y-MM-dd HH:mm:ss');
        $requestLogs = $rule->getRequestLogs($requestFilter, $requestTime);

        if ($exclude) {
            $requestLogs->exclude($exclude);
        }

        if (!$requestLogs->count()) {
            $rule->addInfringementData(_t(
                self::class . '.ASSESSMENT_NO_TYPE',
                'No requests of {status}type {types}, verb {verb}, ipaddress {ipAddress}',
                [
                    'status' => $status,
                    'ipAddress' => $rule->IPAddress,
                    'types' => $rule->getRequestTypesForCSV(),
                    'verb' => $rule->Verb,
                ]
            ));

            return true;
        }

        if ($requestLogs->count() < $rule->Count) {
            $rule->addInfringementData(_t(
                self::class . '.ASSESSMENT_TYPE_COUNT',
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

        $rule->addInfringementData(_t(
            self::class . '.ASSESSMENT_TYPE_COUNT_FALSE',
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
            $rule->addInfringementData(_t(
                self::class . 'ASSESSMENT_EXTEND_SESSION',
                'Extend evaluate session is true'
            ));

            return true;
        }

        $rule->addInfringementData(_t(
            self::class . '.ASSESSMENTT_EXTEND_SESSION_FALSE',
            'Extend evaluate session is false'
        ));

        $time = DBDatetime::create()->modify($sessionLog->LastAccessed);
        $loginTime = $time->modify('-' . $rule->LoginAttemptsStartOffset . ' seconds')
            ->format('y-MM-dd HH:mm:ss');
        if (in_array($rule->Level, ['Global', 'Session']) &&
            $rule->evaluateLoginAttempts(null, $requestLog, $loginTime)) {
            return true;
        }

        return false;
    }

    public static function broadcastOnBlock(self $rule, RequestLog $requestLog): void
    {
        if (!$rule->IPAddressBroadcastOnBlock) {
            return;
        }

        $ipRule = IPRule::get()->filter([
            'IPAddress' => $requestLog->IPAddress,
            'Permission' => 'Denied',
        ])->first();

        if (!$ipRule) {
            $ipRule = IPRule::create([
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
            $requestTypes = $rule->RequestTypes();

            foreach($requestTypes as $type) {
                $type->IPRules()->add($ipRule);
            }
        }
    }

    public static function runAssessments(): void
    {
        $rules = self::get();

        if (!$rules) {
            return;
        }

        foreach ($rules as $rule) {
            $rule->assessRule();
        }
    }

}
