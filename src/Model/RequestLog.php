<?php

namespace Roadblock\Model;

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

    private static array $db = [
        'URL' => 'Text',
        'Verb' => 'Enum("POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD")',
        'IPAddress' => 'Varchar(11)',
        'UserAgent' => 'Text',
        'Type' => "Enum('Admin,Dev,API,File,Personal,Registration,Export,General,Staff,Bad','General)",
    ];

    private static array $has_one = [
        'LoginAttempt' => LoginAttempt::class,
        'SessionLog' => SessionLog::class,
    ];

    private static string $table_name = 'RequestLog';

    private static array $summary_fields = [
        'Created' => 'Time',
        'URL' => 'URL',
    ];

    private static string $default_sort = 'Created DESC';

    private static array $searchable_fields = [
        'URL',
    ];

    public static function capture(HTTPRequest $request): array
    {
        $sessionIdentifier = session_id();
        $sessionLog = SessionLog::get()->filter(['SessionIdentifier' => $sessionIdentifier])->first();

        if (!$sessionLog) {
            //start a new session log
            $sessionLog = SessionLog::create(['SessionIdentifier' => $sessionIdentifier]);
        }

        $url = $request->getURL();

        foreach (self::config()->get('ignore_urls') as $pattern) {
            if (preg_match($pattern, $url)) {
                return [null, $sessionLog, null];
            }
        }

        try {
            $ipAddress = $request->getIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $url = $request->getURL();

            $requestLog = RequestLog::create([
                'URL' => $url,
                'Verb' => $_SERVER['REQUEST_METHOD'],
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
                'Type' => RoadblockURLRule::getURLType($url),
            ]);

            $requestLog->write();

            $sessionLog->update([
                'LastAccessed' => DBDatetime::now()->Rfc2822(),
                'IPAddress' => $ipAddress,
                'UserAgent' => $userAgent,
            ]);

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
