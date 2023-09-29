<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
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

    /**
     * @var string
     */
    private static $table_name = 'Roadblock';

    private static array $summary_fields = [
        'MemberName' => 'Name',
        'SessionAlias' => 'Session',
        'IPAddress' => 'IP Address',
        'FriendlyUserAgent' => 'User Agent',
        'LastAccessed.Nice' => 'Last accessed',
        'Expiry.Nice' => 'Expiry',
        'Score' => 'Score',
        'AdminOverride.Nice' => 'Admin override',
    ];

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

    public static function evaluate(SessionLog $session, RequestLog $request): string
    {
        $rules = RoadblockRule::get()->filter(['Status' => 'Enabled']);

        $member = Security::getCurrentUser();

        $list = ArrayList::create();

        $obj = null;

        $new = '';

        foreach($rules as $rule) {
            $ok = $rule::evaluate($session, $request, $rule);

            if (!$ok) {
                if ($obj === null) {
                    $data = [
                        'IPAddress' => $session->IPAddress,
                        'UserAgent' => $session->UserAgent,
                        'SessionLogID' => $session->ID,
                        'SessionIdentifier' => $session->SessionIdentifier,
                        'SessionAlias' => $session->SessionAlias,
                        'MemberID' => $member ? $member->ID : 0,
                        'MemberName' => $member ? $member->getTitle() : 0,
                        'LastAccessed' => $session->LastAccessed,
                    ];
                    $obj = self::updateOrCreate($data);

                    if (!$obj->ID) {
                        if (self::config()->get('email_notify_on_partial')) {
                            $new = 'partial';
                        }
                        $obj->write();
                    }
                }

                $exceptionData = [
                    'URL' => $request->URL,
                    'Verb' => $request->Verb,
                    'IPAddress' => $request->IPAddress,
                    'UserAgent' => $request->UserAgent,
                    'RoadblockRequestType' => $request->RoadblockRequestType()->Title,
                    'RoadblockID' => $obj->ID,
                ];
                $exception = RoadblockException::create($exceptionData);
                $rule->RoadblockExceptions()->add($exception);
                $list->push($rule);
            }
        }

        if ($list->count()) {
            $rulesOrig = $obj->Rules();

            forEach($list as $rule) {
                if ($rule->Score === 0.00) {
                    //rules with 0 score block just the request without adding to the score.
                    $obj->write();
                    throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
                }

                if (!$rulesOrig->filter(['ID' => $rule->ID])->exists()) {
                    $obj->Rules()->add($rule);
                    if (self::recalculate($obj, $rule) && self::config()->get('email_notify_on_blocked')) {
                        $new = 'full';
                    };
                } else if ($rule->Cumulative === 'Yes'){
                    if (self::recalculate($obj, $rule) && self::config()->get('email_notify_on_blocked')) {
                        $new = 'full';
                    };
                }
            }

            $obj->write();
        }

        return $new;

    }

    public static function recalculate(Roadblock $obj, RoadblockRule $rule): bool
    {
        $score = $obj->Score;

        $score += $rule->Score;

        $response = self::captureExpiry($obj, $score);

        $obj->Score = $score;

        return $response;
    }

    public static function recalculateAll(Roadblock $obj): bool
    {
        $score = 0.0;

        foreach ($obj->Rules() as $rule) {
            if ($rule->Cumulative === 'Yes') {
                $num = $rule->filter(['RoadblockExceptions.SessionIdentifier' => $obj->SessionIdentifier])->count();
                $score += ($rule->Score * $num);
            } else {
                $score += $rule->Score;
            }
        }

        $blocked = self::captureExpiry($obj, $score);

        $obj->Score = $score;

        return $blocked;
    }

    public static function captureExpiry(Roadblock $obj, float $score): bool
    {
        if ($obj->Score < self::$threshold && $score > self::$threshold) {
            $expiryInterval = self::config()->get('expiry_interval');

            if ($expiryInterval) {
                $date = DBDatetime::create()
                    ->modify($obj->LastAccessed)
                    ->modify('+' . (Int) $expiryInterval . ' seconds');
                $obj->Expiry = $date->format('y-MM-dd HH:mm:ss');
            }

            return true;
        }

        return false;
    }

    public static function checkOK(SessionLog $sessionLog): bool
    {
        $filter = [
            'AdminOverride' => 0,
            'Score:GreaterThan' => self::$threshold,
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
        $objs = Roadblock::get()->filter([
           'SessionIdentifier' => $data['SessionIdentifier'],
        ]);

        if ($objs->exists()) {
            if ($objs->count() > 1 ) {
                //throw error
                return null;
            }
            return $objs->first()->update($data);
        }

        $roadblock = Roadblock::create($data);

        return $roadblock;
    }

    public static function sendPartialNotification(Member $member, SessionLog $sessionLog): bool
    {
        if (self::config()->get('email_notify_on_partial')) {
            $from = self::config()->get('email_from');
            $to = self::config()->get('email_to');
            $subject = _t("ROADBLOCK.NOTIFY_PARTIAL_SUBJECT","Notification of new partial IP block");
            $body = _t(
                "ROADBLOCK.NOTIFY_PARTIAL_BODY",
                "A new roadblock has been created for the IP address, name (if known): {IPAddress}, {Name}",
                [
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                ]
            );

            $email = new Email($from, $to, $subject, $body);
            return $email->send();
        }

        return false;
    }

    public static function sendBlockedNotification(Member $member, SessionLog $sessionLog): bool
    {
        if (self::config()->get('email_notify_on_blocked')) {
            $from = self::config()->get('email_from');
            $to = self::config()->get('email_to');
            $subject = _t("ROADBLOCK.NOTIFY_BLOCKED_SUBJECT", "Notification of IP block");
            $body = _t(
                "ROADBLOCK.NOTIFY_BLOCKED_BODY",
                "A roadblock has been enforced for the IP address, name (if known): {IPAddress}, {Name}",
                [
                    'IPAddress' => $sessionLog->IPAddress,
                    'Name' => $member ? $member->getTitle() : 0,
                ]
            );

            $email = new Email($from, $to, $subject, $body);
            return $email->send();
        }

        return false;
    }

}
