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

/**
 * SessionLogMiddleware run shortly after member authentication. This is the core class that implements the loggin
 * of session and request information, evaluation of the current request according to the rules set up
 * and implements blocking of request if required.
 */

class SessionLogMiddleware implements HTTPMiddleware
{
    use Configurable;

    /**
     * In order this function:
     * Logs the request and session information, associating it with an authenticated member if appropriate
     * evaluates the current request to see if it violates any rules
     * Allows (delegates) the further processing of the request if no error
     * Assigns the http response to the request's log
     *
     * @param HTTPRequest $request
     * @param callable $delegate
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        [$member, $sessionLog, $requestLog] = RequestLog::capture($request);

        if ($requestLog) {
            // do first check as soon as possible after authentication
            self::evaluate($member, $sessionLog, $requestLog, $request);
        }

        $response = $delegate($request);

        if ($requestLog) {
            // do second check on just rules that evaluate a status code after the fact
            $requestLog->StatusCode = $response->getStatusCode();
            $requestLog->StatusDescription = $response->getStatusDescription();
            $requestLog->write();
            self::evaluate($member, $sessionLog, $requestLog, $request);
        }

        return $response;
    }

    /**
     *  Returns ether a 404 error page or an error message based on config settings
     *
     * @param Controller|null $dummyController
     * @return void
     * @throws HTTPResponse_Exception
     */
    public static function generateBlockedResponse(?Controller $dummyController): void
    {
        if (self::config()->get('show_error_on_blocked')) {
            $controller = $dummyController ?? Controller::curr();
            $controller->httpError(404);
        }

        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

    /**
     * Evaluates the current request then check to see if the request should be blocked
     * Sends a email notification on various exceptions / status changes
     *
     * @param Member|null $member
     * @param SessionLog $sessionLog
     * @param RequestLog $requestLog
     * @param HTTPRequest $request
     * @param string|null $middleware
     * @return void
     * @throws HTTPResponse_Exception
     */
    public static function evaluate(
        ?Member $member,
        SessionLog $sessionLog,
        RequestLog $requestLog,
        HTTPRequest $request,
        ?string $middleware = null
    ):void
    {
        // only evaluate logged requests to avoid restricting generic or approved urls
        [$notify, $roadblock] = RoadBlock::evaluate($sessionLog, $requestLog, $request, $middleware);

        // fetch any reasons why the request should be blocked
        $roadblocks = RoadBlock::getCurrentRoadblocks($sessionLog);

        if ($roadblocks->exists()) {
            $notify = 'single';
        }

        if (!$roadblock) {
            // if the current request is not blocked but the member or session is blocked elsewhere fetch that
            $roadblock = $roadblocks->sort('LastNotified DESC')->first();
        }

        if ($notify) {

            // as there might not yet be a controller, add a dummy controller to allow email to proceed
            $dummyController = null;

            if (!Controller::has_curr()) {
                $dummyController = new Controller();
                $dummyController->setRequest($request);
                $dummyController->pushCurrent();
            }

            switch ($notify) {
                case 'info':
                    // Notify info only is created when the score of a rule is set to zero.
                    RoadBlock::sendInfoNotification($member, $sessionLog, $roadblock, $requestLog, $request);

                    break;

                case 'partial':
                    // Notify on first creation of a roadblock (partial) triggered when the request
                    // gains a score but is not yet at the threshold.
                    RoadBlock::sendPartialNotification($member, $sessionLog, $roadblock, $requestLog, $request);

                    break;

                case 'latest':
                    // Notify latest will notify any ongoing activity while a roadblock is in effect.
                    RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog, $request);

                    break;

                case 'full':
                    // Notify on blocked will send a notification when the roadblock crosses the threshold.
                    RoadBlock::sendBlockedNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    self::generateBlockedResponse($dummyController);

                case 'single':
                    // Notify latest will notify any ongoing activity while a roadblock is in effect.
                    RoadBlock::sendLatestNotification($member, $sessionLog, $roadblock, $requestLog, $request);
                    self::generateBlockedResponse($dummyController);
            }

            $dummyController?->popCurrent();
        }
    }

}
