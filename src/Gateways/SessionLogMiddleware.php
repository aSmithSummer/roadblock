<?php

namespace aSmithSummer\Roadblock\Gateways;

use aSmithSummer\Roadblock\Model\RequestLog;
use aSmithSummer\Roadblock\Model\Roadblock;
use aSmithSummer\Roadblock\Model\SessionLog;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Security\Member;
use SilverStripe\Core\Config\Configurable;

class SessionLogMiddleware implements HTTPMiddleware
{
    use Configurable;

    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        if ($requestLog) {
            $this->evaluate($member, $sessionLog, $requestLog, $request);
        }

        $response = $delegate($request);

        if ($requestLog) {
            $requestLog->Status = $response->getStatusCode();
            $requestLog->StatusDescription = $response->getStatusDescription();
            $requestLog->write();
            $this->evaluate($member, $sessionLog, $requestLog, $request);
        }

        return $response;
    }

    private function generateBlockedResponse(?Controller $dummyController): void
    {
        if (self::config()->get('show_error_on_blocked')) {
            $controller = $dummyController ?? Controller::curr();
            $controller->httpError(404);
        }

        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

    private function evaluate(
        ?Member $member,
        SessionLog $sessionLog,
        RequestLog $requestLog,
        HTTPRequest $request ): void
    {
        //only evaluate logged requests to avoid restricting generic or approved urls
        [$notify, $roadblock] = RoadBlock::evaluate($sessionLog, $requestLog, $request);

        $roadblocks = RoadBlock::getCurrentRoadblocks($sessionLog);

        if ($roadblocks->exists()) {
            $notify = 'single';
        }

        if (!$roadblock) {
            $roadblock = $roadblocks->sort('LastNotified DESC')->first();
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
                    if ($requestLog->Status) {
                        RoadBlock::sendInfoNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    }

                    break;

                case 'partial':
                    if ($requestLog->Status) {
                        RoadBlock::sendPartialNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    }

                    break;

                case 'latest':
                    if ($requestLog->Status) {
                        RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    }

                    break;

                case 'full':
                    RoadBlock::sendBlockedNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    $this->generateBlockedResponse($dummyController);

                case 'single':
                    RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    $this->generateBlockedResponse($dummyController);
            }

            $dummyController?->popCurrent();
        }
    }

}
