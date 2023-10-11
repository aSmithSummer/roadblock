<?php

namespace Roadblock\Gateways;

use Roadblock\Model\RequestLog;
use Roadblock\Model\Roadblock;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;

class SessionLogMiddleware implements HTTPMiddleware
{

    public function process(HTTPRequest $request, callable $delegate)
    {
        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        if ($requestLog) {
            //only evaluate logged requests to avoid restricting generic or approved urls
            [$notify, $roadblock] = RoadBlock::evaluate($sessionLog, $requestLog);

            if ($notify) {
                $dummyController = new Controller();
                $dummyController->setRequest($request);
                $dummyController->pushCurrent();

                switch($notify) {
                    case 'partial':
                        RoadBlock::sendPartialNotification($member, $sessionLog, $roadblock, $requestLog);

                        break;
                    case 'full':
                        RoadBlock::sendBlockedNotification($member, $sessionLog, $roadblock, $requestLog);

                        break;
                    case 'latest':
                        RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog);
                }

                $dummyController->popCurrent();
            }

            if (RoadBlock::checkOK($sessionLog)) {
                return $delegate($request);
            }

            $dummyController = new Controller();
            $dummyController->setRequest($request);
            $dummyController->pushCurrent();
            RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog);
            $dummyController->popCurrent();
            
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.HTTP_EXCEPTION_MESSAGE',"Page Not Found. Please try again later."), 404);
        }

        return $delegate($request);
    }

}
