<?php

namespace Roadblock\Model;

use Ramsey\Uuid\Uuid;
use Roadblock\Traits\UseragentNiceTrait;
use SilverStripe\Control\Session;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
/**
 * Tracks a session.
 */
class SessionLog extends DataObject
{
    use UseragentNiceTrait;

    private static array $db = [
        'LastAccessed' => 'DBDatetime',
        'SessionIdentifier' => 'Varchar(45)',
        'SessionAlias' => 'Varchar(15)',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $has_many = [
        'Requests' => RequestLog::class,
    ];

    private static string $table_name = 'SessionLog';

    private static string $plural_name = 'Sessions';

    private static array $indexes = [
        'UniqueSessionSessionIdentifier' => [
            'type' => 'unique',
            'columns' => ['SessionIdentifier'],
        ],
        'UniqueSessionSessionAlias' => [
            'type' => 'unique',
            'columns' => ['SessionAlias'],
        ],
    ];

    private static string $default_sort = 'LastAccessed DESC';

    private static array $summary_fields = [
        'SessionAlias' => 'Identifier',
        'IPAddress' => 'IP Address',
        'Created' => 'Started',
        'LastAccessed' => 'Last Accessed',
        'FriendlyUserAgent' => 'User Agent',
        'Member.getTitle' => 'Member',
    ];

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->SessionAlias) {
            $this->createSessionAlias();
        }

        if ($this->isChanged('UserAgent')) {
            //report
        }

        if ($this->isChanged('IPAddress')) {
            //report
        }
    }

    public function createSessionAlias()
    {
        $this->SessionAlias = md5(Uuid::uuid4()->toString());
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

    public function canDelete($member = null): bool
    {
        return false;
    }

    public static function getCurrentSessions(Member $member): DataList
    {
        $sessionLifetime = static::getSessionLifetime();
        $maxAge = DBDatetime::now()->getTimestamp() - $sessionLifetime;
        $currentSessions = $member->SessionsLogs()->filter([
            'LastAccessed:GreaterThan' => date('Y-m-d H:i:s', $maxAge)
        ]);
        return $currentSessions;
    }

    public static function getMemberSessions(Member $member): DataList
    {
        $sessions = self::get()->filter([
            'MemberID' => $member->ID,
        ]);
        return $sessions;
    }

    public static function getSessionLifetime(): int
    {
        if ($lifetime = Session::config()->get('timeout')) {
            return $lifetime;
        }

        return LoginSession::config()->get('default_session_lifetime');
    }

}
