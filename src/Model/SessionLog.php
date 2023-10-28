<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use Ramsey\Uuid\Uuid;
use SilverStripe\Control\Session;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\SessionManager\Models\LoginSession;

class SessionLog extends DataObject
{

    use UseragentNiceTrait;

    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
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
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $indexes = [
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueSessionSessionIdentifier' => [
            'type' => 'unique',
            'columns' => ['SessionIdentifier'],
        ],
        // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
        'UniqueSessionSessionAlias' => [
            'type' => 'unique',
            'columns' => ['SessionAlias'],
        ],
    ];

    private static string $default_sort = 'LastAccessed DESC';
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $summary_fields = [
        'SessionAlias' => 'Identifier',
        'IPAddress' => 'IP Address',
        'Created' => 'Started',
        'LastAccessed' => 'Last Accessed',
        'FriendlyUserAgent' => 'User Agent',
        'Member.getTitle' => 'Member',
    ];

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        // phpcs:ignore SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed
        if (!$this->SessionAlias) {
            $this->createSessionAlias();
        }
    }

    public function createSessionAlias(): void
    {
        $this->SessionAlias = md5(Uuid::uuid4()->toString());
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $this->member()->canView();
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

    public static function getCurrentSessions(Member $member): DataList
    {
        $sessionLifetime = static::getSessionLifetime();
        $maxAge = DBDatetime::now()->getTimestamp() - $sessionLifetime;

        return $member->SessionsLogs()->filter([
            'LastAccessed:GreaterThan' => date('Y-m-d H:i:s', $maxAge),
        ]);
    }

    public static function getMemberSessions(Member $member): DataList
    {
        return self::get()->filter([
            'MemberID' => $member->ID,
        ]);
    }

    public static function getSessionLifetime(): int
    {
        $lifetime = Session::config()->get('timeout');

        return $lifetime ?: LoginSession::config()->get('default_session_lifetime');
    }

}
