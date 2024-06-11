<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Services\EmailService;
use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Tracks a session.
 */
class Roadblock extends DataObject
{

    use UseragentNiceTrait;

    public static float $threshold = 100.0;

    private static array $db = [
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'SessionIdentifier' => 'Varchar(45)',
        'SessionAlias' => 'Varchar(15)',
        'Expiry' => 'DBDatetime',
        'ExpiryInterval' => 'Int',
        'MemberName' => 'Varchar(50)',
        'LastAccessed' => 'DBDatetime',
        'Score' => 'Float',
        'AdminOverride' => 'Boolean',
        'CycleCount' => 'Int',
        'LastNotified' => 'DBDatetime',
        'LastNotifiedMember' => 'DBDatetime',
        'Controller' => 'Varchar(256)',
    ];

    private static array $has_one = [
        'SessionLog' => SessionLog::class,
        'Member' => Member::class,
    ];

    private static array $has_many = [
        'Infringements' => Infringement::class,
    ];

    private static array $many_many = [
        'Rules' => Rule::class,
    ];

    private static array $defaults = [
        'Expiry' => null,
        'Score' => 0.00,
        'AdminOverride' => false,
        'CycleCount' => 0,
    ];

    private static string $table_name = 'Roadblock';

    private static string $plural_name = 'Roadblocks';

    private static array $summary_fields = [
        'Member.Title' => 'Name',
        'SessionAlias' => 'Session',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'LastAccessed.Nice' => 'Last accessed',
        'Expiry.Nice' => 'Expiry',
        'Score' => 'Score',
        'AdminOverride.Nice' => 'Admin override',
    ];

    private static string $default_sort = 'LastAccessed DESC';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('SessionIdentifier');

        return $fields;
    }

    /**
     * @throws Exception
     */
    public static function updateOrCreate(array $data): self
    {
        $roadblocks = self::get()->filter([
            'SessionIdentifier' => $data['SessionIdentifier'],
        ]);

        if ($roadblocks->exists()) {
            if ($roadblocks->count() > 1) {
                throw new Exception('Duplicate session identifiers found', '404');
            }

            $record = $roadblocks->first();
            $record->update($data);

            return $record;
        }

        return self::create($data);
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return false;
    }

    public static function getExportFields(): array
    {

        return [
            'IPAddress' => 'IPAddress',
            'UserAgent' => 'UserAgent',
            'SessionIdentifier' => 'SessionIdentifier',
            'SessionAlias' => 'SessionAlias',
            'Expiry' => 'Expiry',
            'MemberName' => 'MemberName',
            'LastAccessed' => 'LastAccessed',
            'Score' => 'Score',
            'AdminOverride' => 'AdminOverride',
            'CycleCount' => 'CycleCount',
            'SessionLog.SessionAlias' => 'SessionLog.SessionAlias',
            'Member.Title' => 'Member.Title',
            'Controller' => 'Contrioller',
        ];
    }

    /**
     * Returns an array of status and roadblock
     * Status can be:
     * 'latest' - a further infringement
     * 'info' - for scores less than zero
     * 'single' - block just this request for rules with a score of ero
     * Roadblock can be null if no infringements for this request, ession or member
     *
     * @param SessionLog $sessionLog
     * @param RequestLog $requestLog
     * @param HTTPRequest $request
     * @param string|null $middleware
     * @return array
     * @throws \SilverStripe\ORM\ValidationException
     */
    public static function evaluate(
        SessionLog $sessionLog,
        RequestLog $requestLog,
        HTTPRequest $request,
        ?string $middleware = null
    ): array
    {
        $filter = ['Status' => 'Enabled'];

        if ($requestLog->StatusCode) {
            $filter['StatusCodes:PartialMatch'] = $requestLog->StatusCode;
        }

        $filter['Middleware'] = $middleware;

        $rules = Rule::get()->filter($filter);

        $member = Security::getCurrentUser();

        $list = ArrayList::create();

        $roadblock = null;

        $status = '';

        foreach ($rules as $rule) {
            if ($rule::evaluate($sessionLog, $requestLog, $rule)) {
                continue;
            }

            if (!$rule->currentAssessment && $rule->getCurrentUser() && $rule->NotifyIndividuallySubject) {
                // notification email to admin if the rule requests it, sent for each infringement
                self::notifyIndividually(
                    $member,
                    $rule,
                    $sessionLog,
                    $requestLog,
                    $request
                );
            }

            if ($roadblock === null) {
                //find existing roadblock or create a new one
                $roadblock = self::generateRoadblock($member, $sessionLog);
            }

            // add the infringement to the rule
            $rule = self::generateInfringement(
                $roadblock,
                $rule,
                $sessionLog,
                $requestLog
            );

            $list->push($rule);
        }

        if ($list->count()) {
            $status = 'latest';
            $rulesOrig = $roadblock->Rules();

            foreach ($list as $rule) {
                if ($rule->Score === 0.00) {
                    // infringement for just this request
                    $status = 'single';

                    continue;
                }

                if ($rule->Score < 0.00) {
                    // cases less than zero are for rules which reduce the roadblock score, eg recovery url
                    $status = $status === 'single' ?: 'info';
                    $roadblock->Rules()->add($rule);
                    self::recalculate($roadblock, $rule);

                    if (self::hasPassedThreshold($roadblock)) {
                        self::updateExpiry($roadblock);
                    }

                    continue;
                }

                if (!$rulesOrig->filter(['ID' => $rule->ID])->exists()) {
                    // add any positive score to the roadblock for the first time
                    $roadblock->Rules()->add($rule);
                    self::recalculateExpiryInterval($roadblock);
                    self::recalculate($roadblock, $rule);

                    if (self::hasPassedThreshold($roadblock)) {
                        self::updateExpiry($roadblock);

                        if (self::config()->get('email_notify_on_blocked')) {
                            // status of full for email notification of new block
                            $status = in_array($status, ['info', 'single']) ? $status : 'full';
                        }

                        Rule::broadcastOnBlock($rule, $requestLog);
                    }
                } elseif ($rule->Cumulative === 'Yes') {
                    self::recalculate($roadblock, $rule);

                    if (self::hasPassedThreshold($roadblock)) {
                        self::updateExpiry($roadblock);

                        // status of full for email notification of new block from cumulative activity
                        if (self::config()->get('email_notify_on_blocked')) {
                            $status = in_array($status, ['info', 'single']) ? $status : 'full';
                        }
                        Rule::broadcastOnBlock($rule, $requestLog);
                    }
                } else {
                    if (self::hasPassedThreshold($roadblock)) {
                        self::updateExpiry($roadblock);
                    }

                    // set the new score for non - cumulative records below the threshold
                    $roadblock->Score = max($roadblock->Score, $rule->Score);
                }
            }

            $roadblock->write();
        }

        return [$status, $roadblock];
    }

    public static function generateRoadblock(
        ?Member $member,
        SessionLog $sessionLog
    ): self {
        $roadblockData = [
            'IPAddress' => $sessionLog->IPAddress,
            'LastAccessed' => $sessionLog->LastAccessed,
            'MemberID' => $member ? $member->ID : 0,
            'MemberName' => $member ? $member->getTitle() : 0,
            'SessionAlias' => $sessionLog->SessionAlias,
            'SessionIdentifier' => $sessionLog->SessionIdentifier,
            'SessionLogID' => $sessionLog->ID,
            'UserAgent' => $sessionLog->UserAgent,
        ];

        $roadblock = self::updateOrCreate($roadblockData);

        $roadblock->extend('updateEvaluateRoadblockData', $roadblockData);

        if (!$roadblock->ID) {
            if (self::config()->get('email_notify_on_partial')) {
                $status = 'partial';
            }

            $roadblock->write();
        }

        return $roadblock;
    }

    public static function generateInfringement(
        self $roadblock,
        Rule       $rule,
        SessionLog $sessionLog,
        RequestLog $requestLog
    ): Rule {
        $infringementData = [
            'Created' => $sessionLog->LastAccessed,
            'Description' => $rule->getInfringementData(),
            'IPAddress' => $requestLog->IPAddress,
            'RoadblockID' => $roadblock->ID,
            'Types' => $requestLog->Types,
            'StatusCode' => $requestLog->StatusCode,
            'URL' => $requestLog->URL,
            'UserAgent' => $requestLog->UserAgent,
            'Verb' => $requestLog->Verb,
        ];

        $infringement = Infringement::create($infringementData);
        $infringement->extend('updateEvaluateInfringementData', $infringementData);

        $rule->Infringements()->add($infringement);

        return $rule;
    }

    public static function recalculate(self &$roadblock, Rule $rule): void
    {
        $score = $roadblock->Score;
        $score += $rule->Score;
        $roadblock->Score = max(0, $score);
    }

    public static function hasPassedThreshold(self &$roadblock): bool
    {
        return $roadblock->original['Score'] < self::$threshold && ($roadblock->Score) >= self::$threshold;
    }

    public static function updateExpiry(self &$roadblock)
    {
        $expiryInterval = self::getCurrentExpiryInterval($roadblock);

        if ($expiryInterval) {
            $date = DBDatetime::create()
                ->modify($roadblock->LastAccessed)
                ->modify('+' . $expiryInterval . ' seconds');
            $expiry = $date->getTimestamp();

            if (!$roadblock->Expiry || $roadblock->Expiry->getTimestamp() < $expiry) {
                $roadblock->Expiry = $date->format('y-MM-dd HH:mm:ss');
            }
        }
    }

    public static function recalculateExpiryInterval(self &$roadblock): void
    {
        //find and save most stringent expiry date
        $rules = $roadblock->Rules();
        $expiryInterval = 0;

        if ($rules->exists()) {
            foreach ($rules as $rule) {
                if ($rule->ExpiryOverride === -1) {
                    $roadblock->ExpiryInterval = -1;
                    $roadblock->write();

                    return;
                }

                $expiryInterval = max($rule->ExpiryOverride, $expiryInterval);
            }
        }

        $roadblock->ExpiryInterval = $expiryInterval;
        $roadblock->write();
    }

    public static function getCurrentExpiryInterval(self $roadblock): int
    {
        return $roadblock->ExpiryInterval ?: self::config()->get('expiry_interval');
    }

    /**
     * Roadblock expiry can be (int) seconds in the future, -1 for indefinite or null
     *
     * @param Roadblock $roadblock
     * @param SessionLog $sessionLog
     * @return bool
     */
    public static function activeRoadblock(self $roadblock, SessionLog $sessionLog): bool
    {
        return $roadblock->ExpiryInterval === -1
            || $roadblock->Expiry === null
            || $roadblock->Expiry > $sessionLog->LastAccessed;
    }

    public static function getCurrentRoadblocks(SessionLog $sessionLog): ArrayList
    {
        $filter = [
            'AdminOverride' => 0,
            'Score:GreaterThanOrEqual' => self::$threshold,
        ];
        $member = Security::getCurrentUser();

        if ($member) {
            $filter['MemberID'] = $member->ID;
        } else {
            $filter['SessionIdentifier'] = $sessionLog->SessionIdentifier;
        }

        $list = self::get()->filter($filter);
        $response = new ArrayList();

        if ($list->exists()) {
            foreach ($list as $roadblock) {
                if (self::activeRoadblock($roadblock, $sessionLog)) {
                    $response->push($roadblock);

                    continue;
                }

                $roadblock->Score -= self::$threshold;

                if ($roadblock->Score > self::$threshold) {
                    $response->push($roadblock);
                }

                //if roadblock has expired subtract one time interval and 100.00 score
                $expiry = DBDatetime::create()
                    ->modify($roadblock->Expiry)
                    ->modify('+' . self::getCurrentExpiryInterval($roadblock) . ' seconds');
                $roadblock->Expiry = $expiry->format('y-MM-dd HH:mm:ss');
                $roadblock->CycleCount += 1;

                $roadblock->write();
            }
        }

        return $response;
    }

    public static function notifyIndividually(
        ?Member     $member,
        Rule        $rule,
        SessionLog  $sessionLog,
        RequestLog  $requestLog,
        HTTPRequest $request
    ): void {
        $to = $member->Email;
        $subject = $rule->NotifyIndividuallySubject;
        $body = $rule->NotifyMemberContent;

        $emailService = EmailService::create();
        $emailService->updateNotification($member, $sessionLog, null, $requestLog, $subject, $body, $to);

        if (Controller::has_curr()) {
            $email = $emailService->createEmail();
            $email->send();
        } else {
            $emailController = new Controller();
            $emailController->setRequest($request);
            $emailController->pushCurrent();
            $email = $emailService->createEmail();
            $email->send();
            $emailController->popCurrent();
        }
    }


    public static function prepareEmailSend(
        ?self $roadblock,
        SessionLog $sessionLog,
        HTTPRequest $request,
        RequestLog $requestLog
    ): array {
        $infringements = $roadblock ? self::getRoadblockInfringements($roadblock, $sessionLog->LastAccessed) : [];
        $data = $request->requestVars();

        if (isset($data['SecurityID'])) {
            unset($data['SecurityID']);
        }

        $statusCode = '';

        if ($requestLog->StatusCode) {
            $statusCode .= 'Status: ' . $requestLog->StatusCode . '<br/>';
        }

        return [$infringements, $statusCode];
    }

    public static function sendInfoNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?self $roadblock,
        RequestLog $requestLog,
        HTTPRequest $request
    ): bool {
        if (self::config()->get('email_notify_on_info')) {
            $subject = _t(self::class . '.NOTIFY_INFO_SUBJECT', 'Roadblock info notification');
            [$infringements, $statusCode] = self::prepareEmailSend(
                $roadblock,
                $sessionLog,
                $request,
                $requestLog
            );

            $body = _t(
                self::class . '.NOTIFY_INFO_BODY',
                'A information only request has been attempted for the IP address, name (if known): ' .
                '{IPAddress}, {Name}<br/>Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: ' .
                '<br/><code>{Infringements}</code>',
                [
                    'Data' => json_encode($request->requestVars()),
                    'Infringements' => json_encode($infringements),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Status' => $statusCode,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($roadblock, $emailService);

            if ($success && self::config()->get('email_notify_on_info_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_INFO_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateNotification(
                    $member,
                    $sessionLog,
                    $roadblock,
                    $requestLog,
                    $to,
                    $subject,
                    $body
                );

                self::sendMemberNotification($sessionLog, $roadblock, $emailService);
            }
        }

        return false;
    }

    public static function sendPartialNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?self $roadblock,
        RequestLog $requestLog,
        HTTPRequest $request
    ): bool {
        if (self::config()->get('email_notify_on_partial')) {
            $subject = _t(self::class . '.NOTIFY_PARTIAL_SUBJECT', 'Roadblock activity recorded for first time');
            [$infringements, $statusCode] = self::prepareEmailSend(
                $roadblock,
                $sessionLog,
                $request,
                $requestLog
            );

            $body = _t(
                self::class . '.NOTIFY_PARTIAL_BODY',
                'A new roadblock has been created for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/>' .
                '<code>{Infringements}</code>',
                [
                    'Data' => json_encode($request->requestVars()),
                    'Infringements' => json_encode($infringements),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Status' => $statusCode,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateInfoNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($roadblock, $emailService);

            if ($member && $success && self::config()->get('email_notify_on_partial_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_PARTIAL_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateNotification(
                    $member,
                    $sessionLog,
                    $roadblock,
                    $requestLog,
                    $to,
                    $subject,
                    $body
                );

                self::sendMemberNotification($sessionLog, $roadblock, $emailService);
            }
        }

        return false;
    }

    public static function sendBlockedNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?self $roadblock,
        RequestLog $requestLog,
        HTTPRequest $request
    ): bool {
        if (self::config()->get('email_notify_on_blocked')) {
            $subject = _t(self::class . '.NOTIFY_BLOCKED_SUBJECT', 'Roadblock blocked for the first time');
            [$infringements, $statusCode] = self::prepareEmailSend(
                $roadblock,
                $sessionLog,
                $request,
                $requestLog
            );

            $body = _t(
                self::class . '.NOTIFY_BLOCKED_BODY',
                'A roadblock has been enforced for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/>' .
                '<code>{Infringements}</code>',
                [
                    'Data' => '<pre>' . json_encode($request->requestVars()) . '</pre>',
                    'Infringements' => json_encode($infringements),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Status' => $statusCode,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($roadblock, $emailService);

            if ($success && self::config()->get('email_notify_on_blocked_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_BLOCKED_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateNotification(
                    $member,
                    $sessionLog,
                    $roadblock,
                    $requestLog,
                    $to,
                    $subject,
                    $body
                );

                self::sendMemberNotification($sessionLog, $roadblock, $emailService);
            }
        }

        return false;
    }

    public static function sendLatestNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?self $roadblock,
        RequestLog $requestLog,
        HTTPRequest $request
    ): bool {
        if (self::config()->get('email_notify_on_latest')) {
            $subject = _t(self::class . '.NOTIFY_LATEST_SUBJECT', 'Roadblock notification of additional activity');
            [$infringements, $statusCode] = self::prepareEmailSend(
                $roadblock,
                $sessionLog,
                $request,
                $requestLog
            );

            $body = _t(
                self::class . '.NOTIFY_LATEST_BODY',
                'A blocked request has been attempted for the IP address, name (if known): ' .
                '{IPAddress}, {Name}<br/>Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: ' .
                '<br/><code>{Infringements}</code>',
                [
                    'Data' => json_encode($request->requestVars()),
                    'Infringements' => json_encode($infringements),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Status' => $statusCode,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($roadblock, $emailService);

            if ($member && $success && self::config()->get('email_notify_on_latest_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_LATEST_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateNotification(
                    $member,
                    $sessionLog,
                    $roadblock,
                    $requestLog,
                    $to,
                    $subject,
                    $body
                );

                self::sendMemberNotification($sessionLog, $roadblock, $emailService);
            }
        }

        return false;
    }

    public static function getRoadblockInfringements(self $roadblock, string $lastAccessed): array
    {
        $infringements = [];
        $rules = $roadblock->Infringements()->filter(['Created:GreaterThanOrEqual' => $lastAccessed]);

        foreach ($rules as $infringement) {
            $rule = $infringement->Rule();
            $infringements[] = $rule->Title;
        }

        return $infringements;
    }

    public static function sendNotification(?self $roadblock, EmailService $emailService): bool
    {
        $notifyInterval = self::config()->get('email_notify_frequency');
        $lastNotifiedDate = DBDatetime::create()
            ->modify($roadblock->LastNotified ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if ($now->getTimestamp() >= $lastNotifiedDate->getTimestamp()) {
            $email = $emailService->createEmail();

            $email->send();

            if ($roadblock) {
                $roadblock->LastNotified = $now->format('y-MM-dd HH:mm:ss');
                $roadblock->write();
            }

            return true;
        }

        return false;
    }

    public static function sendMemberNotification(
        SessionLog $sessionLog,
        ?self $roadblock,
        EmailService $emailService
    ): bool {
        $notifyInterval = self::config()->get('email_notify_frequency_member');
        $date = DBDatetime::create()
            ->modify($roadblock->LastNotifiedMember ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if ($now->getTimestamp() >= $date->getTimestamp()) {
            $rules = $roadblock->Infringements()->filter(['Created' => $sessionLog->LastAccessed]);

            foreach ($rules as $infringement) {
                $rule = $infringement->Rule();

                if (!$rule->NotifyMemberContent || $rule->NotifyIndividuallySubject) {
                    continue;
                }
            }

            $email = $emailService->createEmail();

            $email->send();
            $roadblock->LastNotifiedMember = $now->format('y-MM-dd HH:mm:ss');
            $roadblock->write();

            return true;
        }

        return false;
    }

}
