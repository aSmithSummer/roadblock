<?php

namespace aSmithSummer\Roadblock\Extensions;

use aSmithSummer\Roadblock\Model\RequestLog;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\LoginAttempt;

class RoadblockMemberAuthenticatorExtension extends DataExtension
{

    public function updateLoginAttempt(LoginAttempt $attempt, array $data, HTTPRequest $request): void
    {
        $attempt->UserAgent = $_SERVER['HTTP_USER_AGENT'];

        $requestLog = RequestLog::getCurrentRequest();

        if (!$requestLog) {
            return;
        }

        $attempt->RequestLogID = $requestLog->ID;
    }

}
