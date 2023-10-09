<?php

namespace Roadblock\BulkLoader;

use SilverStripe\Dev\CsvBulkLoader;

class RoadblockURLRuleBulkLoader extends CsvBulkLoader
{

    public $duplicateChecks = [
        'Title' => 'Title'
    ];

}
