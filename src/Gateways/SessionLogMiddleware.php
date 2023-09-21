<?php

namespace Roadblock\Gateways;

use App\Model\CountryIPRange;
use Roadblock\Model\RequestLog;
use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockURLRules;
use Roadblock\Model\SessionLog;
use App\Model\TrustLog;
use Exception;
use SilverStripe\Control\Cookie;
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

        [$session, $requestLog] = $this->logRequest($request);
        $this->evaluateRequest($session, $requestLog);

        if ($this->checkScore($session)) {
            return $delegate($request);
        }
        return $delegate($request);
        throw new HTTPResponse_Exception('Page Not Found. Please try again later.', 404);
    }

    private function logRequest(HTTPRequest $request): array
    {
        $sessionIdentifier = session_id();
        $session = SessionLog::get()->filter(['SessionIdentifier' => $sessionIdentifier])->first();

        try {
            $ipAddress = $request->getIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $url = $request->getURL();

            $requestLog = RequestLog::create([
                'URL' => $url,
                'Verb' => $_SERVER['REQUEST_METHOD'],
                'IP' => $ipAddress,
                'UserAgent' => $userAgent,
                'Type' => RoadblockURLRules::getURLType($url),
            ]);

            $requestLog->write();

            if (!$session) {
                //start a new session log
                $session = SessionLog::create(['SessionIdentifier' => $sessionIdentifier]);
                $session->setSessionAlias();
            }

            $session->update([
                'LastAccessed' => DBDatetime::now()->Rfc2822(),
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
                'Country' => $country,
            ]);

            $member = Security::getCurrentUser();

            if ($member !== null) {
                $session->MemberID = $member->ID;
            }

            $session->write();

            $requestLog->SessionID = $session->ID;
            $requestLog->write();
        } catch (Exception $e) {
            //$this->block();
        }

        return [$session,$requestLog];
    }

    private function evaluateRequest(SessionLog $sessionLog, RequestLog $request): bool
    {
        return RoadBlock::evaluate($sessionLog, $request);
    }

    private function checkScore(SessionLog $session): bool
    {
        return RoadBlock::check($session);
    }
}
