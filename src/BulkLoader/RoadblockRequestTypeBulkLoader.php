<?php

namespace Roadblock\BulkLoader;

use Roadblock\Model\RequestLog;
use Roadblock\Model\RoadblockIPRule;
use Roadblock\Model\RoadblockRequestType;
use Roadblock\Model\RoadblockRule;
use Roadblock\Model\RoadblockURLRule;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

class RoadblockRequestTypeBulkLoader extends CsvBulkLoader
{

    public $duplicateChecks = [
        'Title' => 'Title'
    ];

    public $relationCallbacks = [
        'RoadblockURLRules.Title' => [
            'relationname' => 'RoadblockURLRule',
            'callback' => 'getRoadblockURLByTitle',
        ],
        'RoadblockRules.Title' => [
            'relationname' => 'RoadblockRule',
            'callback' => 'getRoadblockRuleByTitle',
        ],
        'RoadblockRequestType.Title' => [
            'relationname' => 'RoadblockRequestType',
            'callback' => 'getRoadblockRequestTypeByTitle',
        ],
        'RoadblockIPRules.Combination' => [
            'relationname' => 'RoadblockIPRule',
            'callback' => 'getRoadblockIPRuleByCombination',
        ],
    ];

    public static function getRoadblockURLByTitle(&$obj, $val, $record): RoadblockURLRule
    {
        return RoadblockURLRule::get()->filter('Title', $val)->First();
    }

    public static function getRoadblockRuleByTitle(&$obj, $val, $record): RoadblockRule
    {
        return RoadblockRule::get()->filter('Title', $val)->First();
    }

    public static function getRoadblockRequestTypeByTitle(&$obj, $val, $record): RoadblockRequestType
    {
        return RoadblockRequestType::get()->filter('Title', $val)->First();
    }

    public static function getRoadblockIPRuleByCombination(&$obj, $val, $record): RoadblockRequestType
    {
        if (strpos($val, '|') > 0) {
            [$permission, $ipAddress] = explode('|',$val);

            return RoadblockIPRule::get()->filter([
                'Permission' => $permission,
                'IPAddress' => $ipAddress,
            ])->First();
        }
    }

}
