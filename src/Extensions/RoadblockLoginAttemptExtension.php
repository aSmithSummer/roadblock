<?php

namespace aSmithSummer\Roadblock\Extensions;

use aSmithSummer\Roadblock\Model\RequestLog;
use SilverStripe\ORM\DataExtension;

/**
 * Tracks a session.
 */
class RoadblockLoginAttemptExtension extends DataExtension
{

    private static array $db = [
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'RequestLog' => RequestLog::class, // only linked if the member actually exists
    ];

}
