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

}
