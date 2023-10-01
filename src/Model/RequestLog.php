<?php

namespace Roadblock\Model;

use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Silverstripe\ORM\ArrayList;
use SilverStripe\Security\Security;

/**
 * Tracks a session.
 */
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
        'LoginAttempt' => LoginAttempt::class,
        'SessionLog' => SessionLog::class,
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    private static string $table_name = 'RequestLog';

    private static array $summary_fields = [
        'Created' => 'Time',
        'IPAddress' => 'IP Address',
        'URL' => 'URL',
        'FriendlyUserAgent' => 'User Agent',
        'RoadblockRequestType.Title' => 'Request type',
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

        $sessionIdentifier = session_id();

        $url = $request->getURL();

        foreach (self::config()->get('ignore_urls') as $pattern) {
            if (preg_match($pattern, $url)) {
                return [null, null, null];
            }
        }

        //if authenticating a new session is created, use cookie to update
        $cookieIdentifier = isset($_COOKIE['PHPSESSID']) ? $_COOKIE['PHPSESSID'] : $sessionIdentifier;

        $sessionLog = SessionLog::get()->filter(['SessionIdentifier' => $cookieIdentifier])->first();

        if (!$sessionLog) {
            //start a new session log
            $sessionLog = SessionLog::create(['SessionIdentifier' => $sessionIdentifier]);
        }

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
                'LastAccessed' => DBDatetime::now()->Rfc2822(),
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
            ];

            if ($sessionIdentifier !== $cookieIdentifier) {
              $sessionData['SessionIdentifier'] = $sessionIdentifier;
            }

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

    /**
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return false;
    }

}
