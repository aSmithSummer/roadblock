<?php

namespace Roadblock\Model;

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
    public static float $threshold = 100.0;

    public static int $expiryInterval = 3;

    private static array $db = [
        'IP' => 'Varchar(11)',
        'Country' => 'Varchar(2)',
        'UserAgent' => 'Text',
        'SessionIdentifier' => 'Varchar(45)',
        'SessionAlias' => 'Varchar(15)',
        'Exipry' => 'DBDatetime',
        'MemberIdentifier' => 'Int',
        'MemberName' => 'Varchar(50)',
        'LastAccessed' => 'DBDatetime',
    ];

    private static array $has_one = [
        'SessionLog' => SessionLog::class,
        'Member' => Member::class,
    ];

    private static array $many_many = [
        'Rules' => RoadblockRule::class,
    ];

    /**
     * @var string
     */
    private static $table_name = 'Roadblock';

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

    public static function evaluate(SessionLog $session, RequestLog $request): bool
    {
        $rules = RoadblockRule::get()->filter(['Status' => 'Enabled']);

        $member = Security::getCurrentUser();

        $list = ArrayList::create();

        foreach($rules as $rule) {
            $ok = $rule::evaluate($session, $request, $rule);

            if (!$ok) {
                $exception = RoadblockException::create($request);
                $rule->Exceptions->add($exception);
                $list->push($rule);
            }
        }

        if ($list->count()) {
            $data = [
                'IPAddress' => $session->IPAddress,
                'Country' => $session->Country,
                'UserAgent' => $session->UserAgent,
                'SessionLogID' => $session->SessionID,
                'SessionIdentifier' => $session->SessionIdentifier,
                'MemberIdentifier' => $member ? $member->ID : 0,
                'MemberName' => $member ? $member->getFullName() : 0,
                'LastAccessed' => $session->LastAccessed,
            ];
            $obj = self::updateOrCreate($data);
            $rulesOrig = $obj->Rules();

            $recalculateYesNo = false;

            forEach($list as $rule) {
                if (!$rulesOrig->get()->filter(['ID' => $rule->ID])) {
                    $obj->add($rule);
                    self::recalculate($obj, $rule);
                } else if ($rule->Cumulative === 'Yes'){
                    self::recalculate($obj, $rule);
                }
            }

            $obj->write();
            return false;
        }

        return true;

    }

    public static function recalculate(Roadblock $obj, RoadblockRule $rule): void
    {
        $score = $obj->Score;

        $score += $rule->Score;

        self::captureExpiry($obj, $score);

        $obj->Score = $score;
    }

    public static function recalculateAll(Roadblock $obj): void
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

        self::captureExpiry($obj, $score);

        $obj->Score = $score;
    }

    public static function captureExpiry(Roadblock $obj, float $score): void
    {
        if ($obj->Score < self::$threshold && $score > self::$threshold) {
            $date = DBDatetime::create()->modify($obj->LastAccessed)->modify('+' . self::$expiryInterval . ' days');
        }

        $obj->Expiry = $date->format('y-MM-dd HH:mm:ss');
    }

    public static function checkOK(SessionLog $session): bool
    {
        $filter = ['SessionIdentifier' => $session->SessionIdentifier];
        $member = Security::getCurrentUser();

        if ($member) {
            $filter = ['MemberIdentifier' => $member->ID];
        }

        $filter['Exipry:GreaterThan'] = $session->LastAccessed;

        return self::get()->filter($filter)->exists();
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

        return Roadblock::create($data);
    }

}
