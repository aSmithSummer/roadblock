<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPResponse_Exception;
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
        'MemberName' => 'Varchar(50)',
        'LastAccessed' => 'DBDatetime',
        'Score' => 'Float',
        'AdminOverride' => 'Boolean',
        'CycleCount' => 'Int',
        'LastNotified' => 'DBDatetime',
    ];

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

    private static $defaults = [
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

    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
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
        return Permission::check('ADMIN', 'any');
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
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
        ];
    }

    public static function evaluate(SessionLog $sessionLog, RequestLog $requestLog): array
    {
        $rules = RoadblockRule::get()->filter(['Status' => 'Enabled']);

        $member = Security::getCurrentUser();

        $list = ArrayList::create();

        $roadblock = null;

        $new = '';

        foreach($rules as $rule) {
            $ok = $rule::evaluate($sessionLog, $requestLog, $rule);

            if (!$ok) {
                if ($roadblock === null) {
                    $roadblockData = [
                        'IPAddress' => $sessionLog->IPAddress,
                        'UserAgent' => $sessionLog->UserAgent,
                        'SessionLogID' => $sessionLog->ID,
                        'SessionIdentifier' => $sessionLog->SessionIdentifier,
                        'SessionAlias' => $sessionLog->SessionAlias,
                        'MemberID' => $member ? $member->ID : 0,
                        'MemberName' => $member ? $member->getTitle() : 0,
                        'LastAccessed' => $sessionLog->LastAccessed,
                    ];

                    $roadblock = self::updateOrCreate($roadblockData);

                    $roadblock->extend('updateEvaluateRoadblockData', $roadblockData);

                    if (!$roadblock->ID) {
                        if (self::config()->get('email_notify_on_partial')) {
                            $new = 'partial';
                        }
                        $roadblock->write();
                    }
                }

                $exceptionData = [
                    'Created' => $sessionLog->LastAccessed,
                    'URL' => $requestLog->URL,
                    'Verb' => $requestLog->Verb,
                    'IPAddress' => $requestLog->IPAddress,
                    'UserAgent' => $requestLog->UserAgent,
                    'RoadblockRequestType' => $requestLog->RoadblockRequestType()->Title,
                    'RoadblockID' => $roadblock->ID,
                ];

                $exception = RoadblockException::create($exceptionData);
                $exception->extend('updateEvaluateRoadblockExceptionData', $exceptionData);

                $rule->RoadblockExceptions()->add($exception);
                $list->push($rule);
            }
        }

        if ($list->count()) {
            $new = 'latest';
            $rulesOrig = $roadblock->Rules();

            forEach($list as $rule) {
                if ($rule->Score === 0.00) {
                    //rules with 0 score block just the request without adding to the score.
                    $roadblock->write();

                    $dummyController = new Controller();
                    $dummyController->pushCurrent();
                    RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog);
                    $dummyController->popCurrent();

                    throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
                }

                if (!$rulesOrig->filter(['ID' => $rule->ID])->exists()) {
                    $roadblock->Rules()->add($rule);
                    if (self::recalculate($roadblock, $rule) && self::config()->get('email_notify_on_blocked')) {
                        $new = 'full';
                        RoadblockRule::broadcastOnBlock($rule, $requestLog);
                    }
                } else if ($rule->Cumulative === 'Yes'){
                    if (self::recalculate($roadblock, $rule) && self::config()->get('email_notify_on_blocked')) {
                        $new = 'full';
                        RoadblockRule::broadcastOnBlock($rule, $requestLog);
                    }
                }
            }

            $roadblock->write();
        }

        return [$new, $roadblock];

    }

    public static function recalculate(Roadblock $roadblock, RoadblockRule $rule): bool
    {
        $score = $roadblock->Score;

        $score += $rule->Score;

        $response = self::captureExpiry($roadblock, $score);

        $roadblock->Score = $score;

        return $response;
    }

    public static function recalculateAll(Roadblock $roadblock): bool
    {
        $score = 0.0;

        foreach ($roadblock->Rules() as $rule) {
            if ($rule->Cumulative === 'Yes') {
                $num = $rule->filter(['RoadblockExceptions.SessionIdentifier' => $roadblock->SessionIdentifier])->count();
                $score += ($rule->Score * $num);
            } else {
                $score += $rule->Score;
            }
        }

        $blocked = self::captureExpiry($roadblock, $score);

        $roadblock->Score = $score;

        return $blocked;
    }

    public static function captureExpiry(Roadblock $roadblock, float $score): bool
    {
        if ($roadblock->Score < self::$threshold && $score > self::$threshold) {
            $expiryInterval = self::config()->get('expiry_interval');

            if ($expiryInterval) {
                $date = DBDatetime::create()
                    ->modify($roadblock->LastAccessed)
                    ->modify('+' . (Int) $expiryInterval . ' seconds');
                $roadblock->Expiry = $date->format('y-MM-dd HH:mm:ss');
            }

            return true;
        }

        return false;
    }

    public static function checkOK(SessionLog $sessionLog): bool
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
        $response = true;

        if ($list->exists()) {
            foreach ($list as $roadblock) {
                if ($roadblock->Expiry === null || $roadblock->Expiry > $sessionLog->LastAccessed) {
                    $response = false;
                    continue;
                }

                //if roadblock has expired subtrract one time interval and 100.00 score
                $roadblock->Score -= self::$threshold;
                $expiry = DBDatetime::create()
                    ->modify($roadblock->Expiry)
                    ->modify('+' . (Int) self::config()->get('expiry_interval') . ' seconds');
                $roadblock->Expiry = $expiry->format('y-MM-dd HH:mm:ss');
                $roadblock->CycleCount += 1;

                $roadblock->write();

                $response = $roadblock->Score > self::$threshold ? false : $response;
            }
        }

        return $response;
    }

    public static function updateOrCreate(array $data): ?Roadblock
    {
        $roadblocks = Roadblock::get()->filter([
           'SessionIdentifier' => $data['SessionIdentifier'],
        ]);

        if ($roadblocks->exists()) {
            if ($roadblocks->count() > 1 ) {
                //throw error
                return null;
            }
            return $roadblocks->first()->update($data);
        }

        return Roadblock::create($data);
    }

    public static function sendPartialNotification(?Member $member, SessionLog $sessionLog, ?Roadblock $roadblock, RequestLog $requestLog): bool
    {
        $notifyInterval = self::config()->get('email_notify_frequency');
        $date = DBDatetime::create()
            ->modify($roadblock->LastNotified ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if (self::config()->get('email_notify_on_partial') && ($now->getTimestamp() >= $date->getTimestamp())) {
            $from = self::config()->get('email_from');
            $to = self::config()->get('email_to');
            $exceptions = [];
            $rules = $roadblock->RoadblockExceptions()->filter(['Created' => $sessionLog->LastAccessed]);

            foreach($rules as $exception){
                $exceptions = $exception->RoadblockRule()->Title;
            }

            $subject = _t("ROADBLOCK.NOTIFY_PARTIAL_SUBJECT","Notification of new partial IP block");
            $body = _t(
                'ROADBLOCK.NOTIFY_PARTIAL_BODY',
                'A new roadblock has been created for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/><code>{Exeptions}</code>',
                [
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'Verb' => $requestLog->Verb,
                    'URL' => $requestLog->URL,
                    'Data' => json_encode($_REQUEST),
                    'Exeptions' => json_encode($exceptions),
                ]
            );

            $email = new Email($from, $to, $subject, $body);
            if ($email->send()) {
                $roadblock->LastNotified = $now->format('y-MM-dd HH:mm:ss');
                $roadblock->write();

                return true;
            }
        }

        return false;
    }

    public static function sendBlockedNotification(?Member $member, SessionLog $sessionLog, ?Roadblock $roadblock, RequestLog $requestLog): bool
    {
        $notifyInterval = self::config()->get('email_notify_frequency');
        $date = DBDatetime::create()
            ->modify($roadblock->LastNotified ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if (self::config()->get('email_notify_on_blocked') && ($now->getTimestamp() >= $date->getTimestamp())) {
            $from = self::config()->get('email_from');
            $to = self::config()->get('email_to');
            $exceptions = [];
            $rules = $roadblock->RoadblockExceptions()->filter(['Created' => $sessionLog->LastAccessed]);

            foreach($rules as $exception){
                $exceptions = $exception->RoadblockRule()->Title;
            }

            $subject = _t("ROADBLOCK.NOTIFY_BLOCKED_SUBJECT", "Notification of IP block");
            $body = _t(
                'ROADBLOCK.NOTIFY_BLOCKED_BODY',
                'A roadblock has been enforced for the IP address, name (if known): {IPAddress}, {Name}' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/><code>{Exeptions}</code>',
                [
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'Verb' => $requestLog->Verb,
                    'URL' => $requestLog->URL,
                    'Data' => json_encode($_REQUEST),
                    'Exeptions' => json_encode($exceptions),
                ]
            );

            $email = new Email($from, $to, $subject, $body);
            if ($email->send()) {
                $roadblock->LastNotified = $now->format('y-MM-dd HH:mm:ss');
                $roadblock->write();

                return true;
            }

            return false;
        }

        return false;
    }

    public static function sendLatestNotification(?Member $member, SessionLog $sessionLog, ?Roadblock $roadblock, RequestLog $requestLog): bool
    {
        $notifyInterval = self::config()->get('email_notify_frequency');
        $date = DBDatetime::create()
            ->modify($roadblock->LastNotified ?? '')
            ->modify('+' . (Int) $notifyInterval . ' seconds');

        $now = DBDatetime::create()->now();

        if (self::config()->get('email_notify_on_latest') && ($now->getTimestamp() >= $date->getTimestamp())) {
            $from = self::config()->get('email_from');
            $to = self::config()->get('email_to');
            $subject = _t("ROADBLOCK.NOTIFY_LATEST_SUBJECT", "Notification of new activity");
            $exceptions = [];
            $rules = $roadblock->RoadblockExceptions()->filter(['Created' => $sessionLog->LastAccessed]);

            foreach($rules as $exception){
                $exceptions = $exception->RoadblockRule()->Title;
            }

            $body = _t(
                'ROADBLOCK.NOTIFY_LATEST_BODY',
                'A blocked request has been attempted for the IP address, name (if known): {IPAddress}, {Name}<br/>' .
                'Verb: {Verb}<br/>URL: {URL}<br/>Data: <br/><code>{Data}</code><br/>Rules: <br/><code>{Exeptions}</code>',
                [
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                    'Verb' => $requestLog->Verb,
                    'URL' => $requestLog->URL,
                    'Data' => json_encode($_REQUEST),
                    'Exeptions' => json_encode($exceptions),
                ]
            );

            $email = new Email($from, $to, $subject, $body);
            if ($email->send()) {
                $roadblock->LastNotified = $now->format('y-MM-dd HH:mm:ss');
                $roadblock->write();

                return true;
            }

            return false;
        }

        return false;
    }

}
