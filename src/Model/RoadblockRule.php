<?php

namespace Roadblock\Model;

use App\Extensions\SiteConfigExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
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
        'Age' => "Enum('Any,Under18,Over65','Any')",
        'Country' => "Enum('Any,NZ,Overseas','Any')",
        'LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
        'LoginAttemptsNumber' => 'Int',
        'LoginAttemptsStartOffest' => 'Int',
        'Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
        'IPCount' => 'Int',
        'Network' => "Enum('Any,Internal,External','Any')",
        'TrustedDevicesCount' => 'Int',
        'Score' => 'Float',
        'Cumulative' => "Enum('Yes,No','No')",
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static $has_one = [
        'Group' => Group::class,
    ];

    private static $belongs_many_many = [
        'Roadblock' => Roadblock::class,
    ];

    private static $has_many = [
        'URLRules' => RoadblockURLRule::class,
        'RoadblockExceptions' => RoadblockException::class,
    ];

    private static string $table_name = 'RoadblockRule';


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

    public static function evaluate(SessionLog $session, RequestLog $request): bool
    {
        if (self::Status === 'Disabled') {
            return true;
        }

        if (self::level === 'Member') {
            $member = Security::getCurrentUser();

            $age = $member->calculateCurrentAge();

            if (self::Age === 'Under18' && $age >= 18) {
                return true;
            }

            if (self::Age === 'Over65' && $age < 65) {
                return true;
            }

            if (self::LoginAttemptsNumber) {
                $time = DBDatetime::now()->modify('+' . self::LoginAttemptsStartOffest . ' days')->format('y-MM-dd HH:mm:ss');
                $filter = [
                    'MemberID' => $member->ID,
                    'Created.GreeaterThan' => $time,
                ];

                if (self::LoginAttemptsNumber !== 'Any') {
                    $filter['LoginAttemptStatus'] = self::LoginAttemptsNumber;
                }
                $logins = LoginAttempt::get()->filter($filter);

                if (!$logins) {
                    return true;
                }

                if ($logins->count() <= self::LoginAttemptsNumber) {
                    return true;
                }
            }
        }

        if (self::Country === 'NZ' && $request->Country !== 'NZ') {
            return true;
        }

        if (self::Country === 'Overseas' && $request->Country === 'NZ') {
            return true;
        }

        if (self::Verb !== 'Any' && $request->Country !== self::Verb) {
            return true;
        }

        if (self::Verb !== 'Any' && $request->Country !== self::Verb) {
            return true;
        }

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
