<?php

namespace Roadblock\BulkLoader;

use SilverStripe\Dev\CsvBulkLoader;

class RoadblockIPRuleBulkLoader extends CsvBulkLoader
{

    public $duplicateChecks = [
        'Title' => 'Title'
    ];

}
