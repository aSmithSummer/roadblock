<?php

namespace Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class RoadblockURLRule extends DataObject
{

    private static array $db = [
        'Title' => 'Varchar(64)',
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
        'Order' => 'Int',
    ];

    private static string $table_name = 'RoadblockURLRule';

    private static string $plural_name = 'URL Rules';

    private static array $indexes = [
        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
        'Order' => true,
    ];

    private static string $default_sort = 'Order';

    private static array $summary_fields = [
        'Title' => 'Title',
        'Pregmatch' => 'Rule',
        'Status' => 'Status',
        'RoadblockRequestType.Title' => 'Type',
    ];

    private static array $has_one = [
        'RoadblockRequestType' => RoadblockRequestType::class,
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    public function getExportFields(): array
    {
        $fields =  [
            'Title' => 'Title',
            'Pregmatch' => 'Pregmatch',
            'Status' => 'Status',
            'RoadblockRequestType.Title' => 'RoadblockRequestType.Title',
            'Order' => 'Order',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public static function getURLType(string $url): int
    {
        $urlRules = self::get()->filter(['Status' => 'Enabled']);

        if ($urlRules) {
            foreach ($urlRules as $urlRule) {
                if (preg_match($urlRule->Pregmatch, $url)) {
                    return $urlRule->RoadblockRequestTypeID;
                }
            }
        }
        return 0;
    }
}
