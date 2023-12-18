<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Services\EmailService;
use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
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
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $has_one = [
        'SessionLog' => SessionLog::class,
        'Member' => Member::class,
    ];

    private static array $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static array $many_many = [
        'Rules' => RoadblockRule::class,
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $defaults = [
        'Expiry' => null,
        'Score' => 0.00,
        'AdminOverride' => false,
        'CycleCount' => 0,
    ];

    private static string $table_name = 'Roadblock';

    private static string $plural_name = 'Roadblocks';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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

    public static function updateOrCreate(array $data): ?self
    {
        $roadblocks = self::get()->filter([
            'SessionIdentifier' => $data['SessionIdentifier'],
        ]);

        if ($roadblocks->exists()) {
            if ($roadblocks->count() > 1) {
                //throw error
                return null;
            }

            return $roadblocks->first()->update($data);
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
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
        ];
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog, HTTPRequest $request): array
    {
        $rules = RoadblockRule::get()->filter(['Status' => 'Enabled']);

        $member = Security::getCurrentUser();

        $list = ArrayList::create();

        $roadblock = null;

        $status = '';

        foreach ($rules as $rule) {
            $ok = $rule::evaluate($sessionLog, $requestLog, $rule);

            if ($ok) {
                continue;
            }

            if (!$rule->currentTest && $rule->getCurrentUser() && $rule->NotifyIndividuallySubject) {
                $to = $member->Email;
                $subject = $rule->NotifyIndividuallySubject;
                $body = $rule->NotifyMemberContent;

                $emailService = EmailService::create();
                $emailService->updateIndividualNotification($member, $sessionLog, $requestLog, $to, $subject, $body);

                if (Controller::has_curr()) {
                    $email = $emailService->createEmail();
                    $email->send();
                } else {
                    $dummyController = new Controller();
                    $dummyController->setRequest($request);
                    $dummyController->pushCurrent();
                    $email = $emailService->createEmail();
                    $email->send();
                    $dummyController->popCurrent();
                }
            }

            if ($roadblock === null) {
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
            }

            $exceptionData = [
                'Created' => $sessionLog->LastAccessed,
                'Description' => $rule->getExceptionData(),
                'IPAddress' => $requestLog->IPAddress,
                'RoadblockID' => $roadblock->ID,
                'Types' => $requestLog->Types,
                'URL' => $requestLog->URL,
                'UserAgent' => $requestLog->UserAgent,
                'Verb' => $requestLog->Verb,
            ];

            $exception = RoadblockException::create($exceptionData);
            $exception->extend('updateEvaluateRoadblockExceptionData', $exceptionData);

            $rule->RoadblockExceptions()->add($exception);
            $list->push($rule);
        }

        if ($list->count()) {
            $status = $status ?: 'latest';
            $rulesOrig = $roadblock->Rules();

            foreach ($list as $rule) {
                if ($rule->Score === 0.00) {
                    $status = 'single';

                    continue;
                }

                if ($rule->Score < 0.00) {
                    $status = 'info';
                    $roadblock->Rules()->add($rule);
                    self::recalculate($roadblock, $rule);
                    self::captureExpiry($roadblock, $rule->Score);

                    continue;
                }

                if (!$rulesOrig->filter(['ID' => $rule->ID])->exists()) {
                    $roadblock->Rules()->add($rule);
                    self::recalculateExpiryInterval($roadblock);
                    self::recalculate($roadblock, $rule);

                    if (self::captureExpiry($roadblock, $rule->Score)) {
                        if (self::config()->get('email_notify_on_blocked')) {
                            $status = in_array($status, ['info', 'single']) ? $status : 'full';
                        }
                        RoadblockRule::broadcastOnBlock($rule, $requestLog);
                    }
                } elseif ($rule->Cumulative === 'Yes') {
                    self::recalculate($roadblock, $rule);

                    if (self::captureExpiry($roadblock, $rule->Score)) {
                        if (self::config()->get('email_notify_on_blocked')) {
                            $status = in_array($status, ['info', 'single']) ? $status : 'full';
                        }
                        RoadblockRule::broadcastOnBlock($rule, $requestLog);
                    }
                } else {
                    self::captureExpiry($roadblock, $rule->Score);
                    $roadblock->Score = max($roadblock->Score, $rule->Score);
                }
            }

            $roadblock->write();
        }

        return [$status, $roadblock];
    }

    public static function recalculate(self &$roadblock, RoadblockRule $rule): void
    {
        $score = $roadblock->Score;
        $score += $rule->Score;
        $roadblock->Score = max(0, $score);
    }

    public static function recalculateAll(self $roadblock): void
    {
        $score = 0.0;

        foreach ($roadblock->Rules() as $rule) {
            if ($rule->Cumulative === 'Yes') {
                $num = $rule->filter(
                    ['RoadblockExceptions.SessionIdentifier' => $roadblock->SessionIdentifier]
                )->count();
                $score += $rule->Score * $num;
            } else {
                $score += $rule->Score;
            }
        }

        $roadblock->Score = $score;
    }

    public static function captureExpiry(self &$roadblock, float $score): bool
    {
        if ($roadblock->Score < self::$threshold && $score >= self::$threshold) {
            $expiryInterval = self::getCurrentExpiryInterval($roadblock);

            if ($expiryInterval) {
                $date = DBDatetime::create()
                    ->modify($roadblock->LastAccessed)
                    ->modify('+' . $expiryInterval . ' seconds');
                $roadblock->Expiry = $date->format('y-MM-dd HH:mm:ss');
            }

            return true;
        }

        return false;
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

    public static function activeRoadblock(self $roadblock, SessionLog $sessionLog): bool
    {
        return $roadblock->ExpiryInterval < 0
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

    public static function sendInfoNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?self $roadblock,
        RequestLog $requestLog,
        HTTPRequest $request
    ): bool {
        if (self::config()->get('email_notify_on_info')) {
            $subject = _t(self::class . '.NOTIFY_INFO_SUBJECT', 'Roadblock info notification');
            $exceptions = $roadblock ? self::getExceptions($roadblock, $sessionLog) : [];
            $data = $request->requestVars();

            if (isset($data['SecurityID'])) {
                unset($data['SecurityID']);
            }

            $body = _t(
                self::class . '.NOTIFY_INFO_BODY',
                'A information only request has been attempted for the IP address, name (if known): ' .
                '{IPAddress}, {Name}<br/>Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: ' .
                '<br/><code>{Exeptions}</code>',
                [
                    'Data' => json_encode($data),
                    'Exeptions' => json_encode($exceptions),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateInfoNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($sessionLog, $roadblock, $emailService);

            if ($success && self::config()->get('email_notify_on_info_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_INFO_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateMemberInfoNotification(
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
            $exceptions = $roadblock ? self::getExceptions($roadblock, $sessionLog) : [];
            $data = $request->requestVars();

            if (isset($data['SecurityID'])) {
                unset($data['SecurityID']);
            }

            $body = _t(
                self::class . '.NOTIFY_PARTIAL_BODY',
                'A new roadblock has been created for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/>' .
                '<code>{Exeptions}</code>',
                [
                    'Data' => json_encode($data),
                    'Exeptions' => json_encode($exceptions),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateInfoNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($sessionLog, $roadblock, $emailService);

            if ($member && $success && self::config()->get('email_notify_on_partial_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_PARTIAL_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateMemberPartialNotification(
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
            $exceptions = $roadblock ? self::getExceptions($roadblock, $sessionLog) : [];
            $data = $request->requestVars();

            if (isset($data['SecurityID'])) {
                unset($data['SecurityID']);
            }

            $body = _t(
                self::class . '.NOTIFY_BLOCKED_BODY',
                'A roadblock has been enforced for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/>' .
                '<code>{Exeptions}</code>',
                [
                    'Data' => '<pre>' . json_encode($data) . '</pre>',
                    'Exeptions' => json_encode($exceptions),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateBlockedNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($sessionLog, $roadblock, $emailService);

            if ($success && self::config()->get('email_notify_on_blocked_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_BLOCKED_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateMemberBlockedNotification(
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
            $exceptions = $roadblock ? self::getExceptions($roadblock, $sessionLog) : [];
            $data = $request->requestVars();

            if (isset($data['SecurityID'])) {
                unset($data['SecurityID']);
            }

            $body = _t(
                self::class . '.NOTIFY_LATEST_BODY',
                'A blocked request has been attempted for the IP address, name (if known): ' .
                '{IPAddress}, {Name}<br/>Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: ' .
                '<br/><code>{Exeptions}</code>',
                [
                    'Data' => json_encode($data),
                    'Exeptions' => json_encode($exceptions),
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'URL' => $requestLog->URL,
                    'Verb' => $requestLog->Verb,
                ]
            );

            $emailService = EmailService::create();
            $emailService->updateLatestNotification($member, $sessionLog, $roadblock, $requestLog, $subject, $body);

            $success = self::sendNotification($sessionLog, $roadblock, $emailService);

            if ($member && $success && self::config()->get('email_notify_on_latest_member')) {
                $to = $member->Email;

                if (!$to) {
                    return $success;
                }

                $subject = _t(self::class . '.NOTIFY_LATEST_MEMBER_SUBJECT', 'Suspicious activity detected');

                $memberEmailService = EmailService::create();
                $memberEmailService->updateMemberLatestNotification(
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

    public static function getExceptions(self $roadblock, SessionLog $sessionLog): array
    {
        $exceptions = [];
        $rules = $roadblock->RoadblockExceptions()->filter(['Created' => $sessionLog->LastAccessed]);

        foreach ($rules as $exception) {
            $roadblockRule = $exception->RoadblockRule();
            $exceptions[] = $roadblockRule->Title;
        }

        return $exceptions;
    }

    public static function sendNotification(SessionLog $sessionLog, ?self $roadblock, EmailService $emailService): bool
    {
        $notifyInterval = self::config()->get('email_notify_frequency');
        $date = DBDatetime::create()
            ->modify($roadblock->LastNotified ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if ($now->getTimestamp() >= $date->getTimestamp()) {
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
            $exceptions = [];
            $rules = $roadblock->RoadblockExceptions()->filter(['Created' => $sessionLog->LastAccessed]);

            foreach ($rules as $exception) {
                $roadblockRule = $exception->RoadblockRule();

                if (!$roadblockRule->NotifyMemberContent || $roadblockRule->NotifyIndividuallySubject) {
                    continue;
                }

                $exceptions[] = $roadblockRule->Title . '<br/>' . $roadblockRule->NotifyMemberContent;
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
