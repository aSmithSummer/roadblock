<?php

namespace aSmithSummer\Roadblock\Model;

use aSmithSummer\Roadblock\Traits\UseragentNiceTrait;
use Ramsey\Uuid\Uuid;
use SilverStripe\Control\Session;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\SessionManager\Models\LoginSession;

class SessionLog extends DataObject
{

    use UseragentNiceTrait;

    private static array $db = [
        'LastAccessed' => 'DBDatetime',
        'SessionIdentifier' => 'Varchar(45)',
        'SessionAlias' => 'Varchar(15)',
        'IPAddress' => 'Varchar(16)',
        'UserAgent' => 'Text',
        'NumberOfRequests' => 'Int',
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
        'NumberOfRequests' => 'Requests',
    ];

    private static $searchable_fields = [
        'SessionAlias',
        'IPAddress',
        'Created' => [
            'title' => 'Started',
        ],
        'LastAccessed',
        'UserAgent',
        'CustomMember' => [
            'title' => 'Member',
            'field' => TextField::class,
            'match_any' => [
                'Member.FirstName',
                'Member.Surname',
            ]
        ],
        'Requests.URL',
        'Requests.Types',
        'NumberOfRequests' => 'GreaterThanOrEqualFilter',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('SessionIdentifier');

        $fields->addFieldToTab('Root.Main', LiteralField::create(
            'RequestInfo',
            $this->getRequestBreakdown()
        ));

        return $fields;
    }

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
        $this->SessionAlias = substr(base64_encode(Uuid::uuid4()->getBytes()), 0, 15);
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

    public function getRequestBreakdown(): string
    {
        return sprintf(
            '<h2>Request info</h2>' .
            '<h3>Status codes</h3>%s' .
            '<h3>Request types</h3>%s' .
            '<h3>Verb</h3>%s',
            $this->getRequestBreakdownForField('StatusCode'),
            $this->getRequestBreakdownForField('Types'),
            $this->getRequestBreakdownForField('Verb')
        );
    }

    public function getRequestBreakdownForField(string $field): string
    {
        $html = '<ul>';
        $rawArray = $this->Requests()->column($field);
        $values = [];

        // as types are comma seperated, split these out
        foreach( $rawArray as $rawValue) {
            $values = array_merge($values, explode(',', $rawValue ?? ''));
        }

        $values = array_unique($values);

        foreach ($values as $value) {
            if (!$value) {
                continue;
            }

            $html .= sprintf(
                '<li><strong>%s: </strong> %s</li>',
                $value,
                $this->Requests()->filter([$field . ':PartialMatch' => $value])->count()
            );
        }

        return $html . '</ul>';
    }

}
