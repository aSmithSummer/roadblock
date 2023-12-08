<?php

namespace aSmithSummer\Roadblock\Gateways;

use aSmithSummer\Roadblock\Model\RequestLog;
use aSmithSummer\Roadblock\Model\Roadblock;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;

class SessionLogMiddleware implements HTTPMiddleware
{
    use Configurable;

    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        if ($requestLog) {
            //only evaluate logged requests to avoid restricting generic or approved urls
            [$notify, $roadblock] = RoadBlock::evaluate($sessionLog, $requestLog, $request);

            if (!RoadBlock::checkOK($sessionLog)) {
                $notify = 'single';
            }

            if ($notify) {

                $dummyController = null;

                if (!Controller::has_curr()) {
                    $dummyController = new Controller();
                    $dummyController->setRequest($request);
                    $dummyController->pushCurrent();
                }

                switch ($notify) {
                    case 'info':
                        RoadBlock::sendInfoNotification($member, $sessionLog, $roadblock, $requestLog);

                        break;

                    case 'partial':
                        RoadBlock::sendPartialNotification($member, $sessionLog, $roadblock, $requestLog);

                        break;

                    case 'full':
                        RoadBlock::sendBlockedNotification($member, $sessionLog, $roadblock, $requestLog);
                        $this->generateBlockedResponse();

                        break;

                    case 'latest':
                        RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog);
                        $this->generateBlockedResponse();

                        break;

                    case 'single':
                        RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog);

                        $this->generateBlockedResponse($dummyController);
                }

                if ($dummyController) {
                    $dummyController->popCurrent();
                }
            }
        }

        return $delegate($request);
    }

    public function generateBlockedResponse(?Controller $dummyController): void
    {
        if (self::config()->get('show_error_on_blocked')) {
            $controller = $dummyController ?? Controller::curr();
            $controller->httpError(404);
        }

        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

}
