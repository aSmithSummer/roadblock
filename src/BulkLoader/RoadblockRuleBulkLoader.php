<?php

namespace Roadblock\BulkLoader;

use Roadblock\Model\RoadblockRequestType;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

class RoadblockRuleBulkLoader extends CsvBulkLoader
{

    public $duplicateChecks = [
        'Title' => 'Title'
    ];

    public $relationCallbacks = [
        'Group.Code' => [
            'relationname' => 'Group',
            'callback' => 'getGroupByCode',
        ],
        'Permission.Code' => [
            'relationname' => 'Group',
            'callback' => 'getPermissionByCode',
        ],
        'RoadblockRequestType.Title' => [
            'relationname' => 'RoadblockRequestType',
            'callback' => 'getRoadblockRequestTypeByTitle',
        ],
    ];

    public static function getGroupByCode(&$obj, $val, $record)
    {
        return Group::get()->filter('Code', $val)->First();
    }

    public static function getPermissionByCode(&$obj, $val, $record)
    {
        return Permission::get()->filter('Code', $val)->First();
    }

    public static function getRoadblockRequestTypeByTitle(&$obj, $val, $record)
    {
        return RoadblockRequestType::get()->filter('Title', $val)->First();
    }

}
