<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class RequestLog extends DataObject
{

    use UseragentNiceTrait;

    public static array $verbs = [
        'CONNECT' => 'CONNECT',
        'DELETE' => 'DELETE',
        'GET' => 'GET',
        'HEAD' => 'HEAD',
        'OPTIONS' => 'OPTIONS',
        'PATCH' => 'PATCH',
        'POST' => 'POST',
        'TRACE' => 'TRACE',
    ];

    private static array $db = [
        'IPAddress' => 'Varchar(16)',
        'URL' => 'Text',
        'UserAgent' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'Types' => 'Varchar(512)',
        'Status' => 'Varchar(8)',
        'StatusDescription' => 'Varchar(256)',
    ];

    private static array $has_one = [
        'SessionLog' => SessionLog::class,
    ];

    private static array $belongs_to = [
        'LoginAttempt' => LoginAttempt::class,
    ];

    private static string $table_name = 'RequestLog';

    private static string $plural_name = 'Requests';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'Created' => 'Time',
        'IPAddress' => 'IP Address',
        'Status' => 'Status Code',
        'URL' => 'URL',
        'FriendlyUserAgent' => 'User Agent',
        'Types' => 'Request types',
        'LoginAttemptStatus' => 'Login status',
    ];

    private static string $default_sort = 'Created DESC';

    private static array $searchable_fields = [
        'Status',
        'URL',
        'IPAddress',
        'Types',
        'Verb',
        'UserAgent',
    ];

    public static function getCurrentRequest(): ?self
    {
        $sessionLog = self::getCurrentSession();

        //if there is a controller check the url matches.
        $controller = Controller::curr();

        $request = $controller ? $sessionLog->Requests()->filter(
            ['URL' => $controller->getRequest()->getURL()]
        )->first() : $sessionLog->Requests()->first();

        return $request ?: null;
    }

    public function getLoginAttemptStatus(): string
    {
        $attempt = LoginAttempt::get()->filter(['RequestLogID' => $this->ID])->first();

        if ($attempt) {
            return $attempt->Status;
        }

        return '';
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any');
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return false;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return false;
    }

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
                'IPAddress' => $ipAddress,
                'URL' => $url,
                'UserAgent' => $userAgent,
                'Verb' => $_SERVER['REQUEST_METHOD'],
                'Types' => RoadblockURLRule::getURLTypes($url),
            ];

            $requestLog = self::create($requestData);
            $requestLog->extend('updateCaptureRequestData', $requestData, $request);

            $requestLog->write();

            $sessionData = [
                'IPAddress' => $ipAddress,
                'LastAccessed' => $requestLog->Created,
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
            throw new HTTPResponse_Exception('Error logging request.', 404);
        }

        return [$member, $sessionLog, $requestLog];
    }

    public static function getCurrentSession(): SessionLog
    {
        $sessionIdentifier = session_id();

        //if authenticating a new session is created, use cookie to update
        $cookieIdentifier = $_COOKIE['PHPSESSID'] ?? $sessionIdentifier;

        $sessionLog = SessionLog::get()->filter(['SessionIdentifier' => $cookieIdentifier])->first();

        if (!$sessionLog) {
            //start a new session log
            $sessionLog = SessionLog::create(['SessionIdentifier' => $cookieIdentifier]);
        }

        return $sessionLog;
    }

}
