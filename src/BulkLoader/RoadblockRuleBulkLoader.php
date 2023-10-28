<?php

namespace aSmithSummer\Roadblock\BulkLoader;

use aSmithSummer\Roadblock\Model\RoadblockRequestType;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

class RoadblockRuleBulkLoader extends CsvBulkLoader
{

    /**
     * @var array
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    public $duplicateChecks = [
        'Title' => 'Title',
    ];

    /**
     * @var array
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    public $relationCallbacks = [
        'Group.Code' => [
            'callback' => 'getGroupByCode',
            'relationname' => 'Group',
        ],
        'Permission.Code' => [
            'callback' => 'getPermissionByCode',
            'relationname' => 'Group',
        ],
        'RoadblockRequestType.Title' => [
            'callback' => 'getRoadblockRequestTypeByTitle',
            'relationname' => 'RoadblockRequestType',
        ],
    ];

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint, SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public static function getGroupByCode(&$obj, $val, $record): ?Group
    {
        return Group::get()->filter('Code', $val)->First();
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint, SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public static function getPermissionByCode(&$obj, $val, $record): ?Permission
    {
        return Permission::get()->filter('Code', $val)->First();
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint, SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public static function getRoadblockRequestTypeByTitle(&$obj, $val, $record): ?RoadblockRequestType
    {
        return RoadblockRequestType::get()->filter('Title', $val)->First();
    }

}
