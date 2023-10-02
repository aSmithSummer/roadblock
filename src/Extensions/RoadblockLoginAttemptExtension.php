<?php

namespace Roadblock\Extensions;

use Roadblock\Model\RequestLog;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Member;

/**
 * Tracks a session.
 */
class RoadblockLoginAttemptExtension extends DataExtension
{

    private static array $has_one = [
        'RequestLog' => RequestLog::class, // only linked if the member actually exists
    ];

}
