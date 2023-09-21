<?php

namespace Roadblock\Model;

use App\Extensions\SiteConfigExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Silverstripe\ORM\ArrayList;
use SilverStripe\Security\Security;

/**
 * Tracks a session.
 */
class RoadblockRule extends DataObject
{

    private static array $db = [
        'Level' => "Enum('Member,Session','Session')",
        'LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
        'LoginAttemptsNumber' => 'Int',
        'LoginAttemptsStartOffset' => 'Int',
        'Type' => "Enum('Admin,Dev,API,File,Personal,Registration,Export,General,Staff,Bad','General)",
        'TypeCount' => 'Int',
        'TypeStartOffset' => 'Int',
        'Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
        'VerbCount' => 'Int',
        'VerbStartOffset' => 'Int',
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    /*
     *
        'Age' => "Enum('Any,Under18,Over65','Any')",
        'Country' => "Enum('Any,NZ,Overseas','Any')",
        'Network' => "Enum('Any,Internal,External','Any')",
        'TrustedDevicesCount' => 'Int',
     */

    private static $has_one = [
        'Group' => Group::class,
    ];

    private static $belongs_many_many = [
        'Roadblock' => Roadblock::class,
    ];

    private static $has_many = [
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static string $table_name = 'RoadblockRule';

    private static array $summary_fields = [
        'Level' => 'Level',
        'LoginAttemptsStatus' => 'LoginAttemptsStatus',
        'Type' => 'Type',
        'Verb' => 'Verb',
        'Score' => 'Score',
        'Cumulative' => 'Cumulative',
        'Status' => 'Status',
    ];
    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
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
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    public static function evaluate(SessionLog $session, RequestLog $request, RoadblockRule $rule): bool
    {
        if ($rule->Status === 'Disabled') {
            return true;
        }

        if ($rule->Level === 'Member') {
            $member = Security::getCurrentUser();

            if (!$member) {
                return true;
            }

            /*
            $age = $member->calculateCurrentAge();

            if (self::Age === 'Under18' && $age >= 18) {
                return true;
            }

            if (self::Age === 'Over65' && $age < 65) {
                return true;
            }
            */

            if ($rule->LoginAttemptsNumber) {
                $time = DBDatetime::now()->modify('+' . $rule->LoginAttemptsStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
                $filter = [
                    'MemberID' => $member->ID,
                    'Created:GreaterThan' => $time,
                ];

                if ($rule->LoginAttemptStatus !== 'Any') {
                    $filter['Status'] = $rule->LoginAttemptStatus;
                }
                $logins = LoginAttempt::get()->filter($filter);

                if (!$logins) {
                    return true;
                }

                if ($logins->count() <= $rule->LoginAttemptsNumber) {
                    return true;
                }
            }
        }

        /*
        if (self::Country === 'NZ' && $request->Country !== 'NZ') {
            return true;
        }

        if (self::Country === 'Overseas' && $request->Country === 'NZ') {
            return true;
        }
        */

        if ($rule->Type !== 'Any') {
            $time = DBDatetime::now()->modify('+' . $rule->TypeStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
            $filter = [
                'SessionLogID' => $session->ID,
                'Created:GreaterThan' => $time,
                'Type' => $rule->Type,
            ];

            $requests = RequestLog::get()->filter($filter);

            if (!$requests) {
                return true;
            }

            if ($requests->count() <= $rule->TypeCount) {
                return true;
            }
        }

        if ($rule->Verb !== 'Any') {
            $time = DBDatetime::now()->modify('+' . $rule->VerbStartOffset . ' seconds')->format('y-MM-dd HH:mm:ss');
            $filter = [
                'SessionLogID' => $session->ID,
                'Created:GreaterThan' => $time,
                'Verb' => $rule->Verb,
            ];

            $requests = RequestLog::get()->filter($filter);

            if (!$requests) {
                return true;
            }

            if ($requests->count() <= $rule->VerbCount) {
                return true;
            }
        }

        /*
        if (self::Network !== 'Any') {
            $internalIPs = SiteConfigExtension::getInternalIps();
            if (in_array($request->IPAddress, $internalIPs)) {
                if (self::Network === 'Internal') {
                    return true;
                }
            } else {
                if (self::Network === 'External') {
                    return true;
                }
            }
        }

        if (self::TrustedDevicesCount < $session->TrustedDevices()->count()) {
            return true;
        }
        */

        $exception = RoadblockException::create([
            'URL' => $request->URL,
            'Verb' => $request->Verb,
            'IPAddress' => $request->IPAddress,
            'Country' => $request->Country,
            'UserAgent' => $request->UserAgent,
            'Type' => $request->Type,
        ]);

        self::RoadblockExceptions()->add($exception);

        return false;
    }

}
