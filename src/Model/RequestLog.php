<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RequestLog extends DataObject
{
    use UseragentNiceTrait;

    public static array $verbs = [
        'POST' => 'POST',
        'GET' => 'GET',
        'DELETE' => 'DELETE',
        'CONNECT' => 'CONNECT',
        'OPTIONS' => 'OPTIONS',
        'TRACE' => 'TRACE',
        'PATCH' => 'PATCH',
        'HEAD' => 'HEAD',
    ];

    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'SessionLog' => SessionLog::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static array $belongs_to = [
        'LoginAttempt' => LoginAttempt::class,
    ];

    private static string $table_name = 'RequestLog';

    private static string $plural_name = 'Requests';

    private static array $summary_fields = [
        'Created' => 'Time',
        'IPAddress' => 'IP Address',
        'URL' => 'URL',
        'FriendlyUserAgent' => 'User Agent',
        'RoadblockRequestType.Title' => 'Request type',
        'LoginAttemptStatus' => 'Login status',
    ];

    private static string $default_sort = 'Created DESC';

    private static array $searchable_fields = [
        'URL',
        'IPAddress',
        'RoadblockRequestType.Title',
        'Verb',
        'UserAgent',
    ];

    public static function capture(HTTPRequest $request): array
    {
        //if not logged in create our own session
        if (!$request->getSession()->isStarted()) {
            $request->getSession()->start($request);
        }

        $url = $request->getURL();

        foreach (self::config()->get('ignore_urls') as $pattern) {
            if (preg_match($pattern, $url)) {
                return [null, null, null];
            }
        }

        $sessionLog = self::getCurrentSession();

        try {
            $ipAddress = $request->getIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $url = $request->getURL();

            $requestData = [
                'URL' => $url,
                'Verb' => $_SERVER['REQUEST_METHOD'],
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
                'RoadblockRequestTypeID' => RoadblockURLRule::getURLType($url),
            ];

            $requestLog = RequestLog::create($requestData);
            $requestLog->extend('updateCaptureRequestData', $requestData, $request);

            $requestLog->write();

            $sessionData = [
                'LastAccessed' => $requestLog->Created,
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
            ];

            $sessionLog->update($sessionData);
            $sessionLog->extend('updateCaptureSessionData', $sessionData);

            $member = Security::getCurrentUser();

            if ($member !== null) {
                $sessionLog->MemberID = $member->ID;
            }

            $sessionLog->write();

            $requestLog->SessionLog = $sessionLog->ID;
            $requestLog->write();
        } catch (Exception $e) {
            //$this->block();
        }

        return [$member, $sessionLog, $requestLog];
    }

    public function getLoginAttemptStatus(): string
    {
        $attempt = LoginAttempt::get()->filter(['RequestLogID' => $this->ID])->first();

        if ($attempt) {
            return $attempt->Status;
        }

        return '';
    }

    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public static function getCurrentSession(): SessionLog
    {
        $sessionIdentifier = session_id();

        //if authenticating a new session is created, use cookie to update
        $cookieIdentifier = isset($_COOKIE['PHPSESSID']) ? $_COOKIE['PHPSESSID'] : $sessionIdentifier;

        $sessionLog = SessionLog::get()->filter(['SessionIdentifier' => $cookieIdentifier])->first();

        if (!$sessionLog) {
            //start a new session log
            $sessionLog = SessionLog::create(['SessionIdentifier' => $cookieIdentifier]);
        }

        //for CLI session_id will be blank
        if ($sessionIdentifier && $sessionIdentifier !== $cookieIdentifier) {
            $sessionData['SessionIdentifier'] = $sessionIdentifier;
        }

        return $sessionLog;
    }

    public static function getCurrentRequest(): ?RequestLog
    {
        $sessionLog = self::getCurrentSession();

        //if there is a controller check the url matches.
        $controller = Controller::curr();

        $request = null;

        if ($controller) {
            $request = $sessionLog->Requests()->filter(['URL' => $controller->getRequest()->getURL()])->first();
        } else {
            $request = $sessionLog->Requests()->first();
        }

        return $request ?: null;
    }

}
