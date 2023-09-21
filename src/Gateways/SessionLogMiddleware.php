<?php

namespace Roadblock\Gateways;

use Roadblock\Model\RequestLog;
use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockURLRule;
use Roadblock\Model\SessionLog;
use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

class SessionLogMiddleware implements HTTPMiddleware
{

    public function process(HTTPRequest $request, callable $delegate)
    {
        if ($request->getURL() ==='dev/build') {
            return $delegate($request);
        }
        
        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        if ($requestLog) {
            RoadBlock::evaluate($sessionLog, $requestLog);
        }

        if (!RoadBlock::checkOK($sessionLog)) {
            return $delegate($request);
        }

        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

}
