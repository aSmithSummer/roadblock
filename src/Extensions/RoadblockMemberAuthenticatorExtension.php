<?php

namespace Roadblock\Extensions;

use Roadblock\Model\RequestLog;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\LoginAttempt;

/**
 * Tracks a session.
 */
class RoadblockMemberAuthenicatorExtension extends DataExtension
{
    public function updateLoginAttempt(LoginAttempt $attempt, array $data, HTTPRequest $request): void
    {
        $requestLog = RequestLog::getCurrentRequest();

        if ($requestLog) {
            $attempt->RequestLogID = $requestLog->ID;
        }
    }

}
