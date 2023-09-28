<?php

namespace Roadblock\Gateways;

use Roadblock\Model\RequestLog;
use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockURLRule;
use Roadblock\Model\SessionLog;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Dev\DevBuildController;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

class SessionLogMiddleware implements HTTPMiddleware
{

    public function process(HTTPRequest $request, callable $delegate)
    {
        if ($request->getURL() ==='dev/build') {
            return $delegate($request);
        }

        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        $new = '';

        if ($requestLog) {
            $new = RoadBlock::evaluate($sessionLog, $requestLog);
        }

        if ($new) {
            $dummyController = new Controller();
            $dummyController->setRequest($request);
            $dummyController->pushCurrent();

            if ($new === 'partial') {
                RoadBlock::sendPartialNotification($member, $sessionLog);
            } else {
                RoadBlock::sendBlockedNotification($member, $sessionLog);
            }

            $dummyController->popCurrent();
        }

        if (RoadBlock::checkOK($sessionLog)) {
            return $delegate($request);
        }

        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

}
