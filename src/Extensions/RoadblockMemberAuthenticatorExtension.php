<?php

namespace Roadblock\Extensions;

use Roadblock\Model\RequestLog;
use Roadblock\Model\SessionLog;
use RoadblockMember\Model\TrustedDevice;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Security;

/**
 * Tracks a session.
 */
class RoadblockMemberAuthenicatorExtension extends DataExtension
{
    public function updateLoginAttempt(LoginAttempt $attempt, array $data, HTTPRequest $request): void
    {
        $sessionIdentifier = session_id();

        //if authenticating a new session is created, use cookie to update
        $cookieIdentifier = isset($_COOKIE['PHPSESSID']) ? $_COOKIE['PHPSESSID'] : $sessionIdentifier;

        $requestLog = RequestLog::get()->filter(['SessionLog.SessionIdentifier' => $cookieIdentifier])->first();

        if ($requestLog) {
            $attempt->RequestLogID = $requestLog->ID;
        }
    }

}
