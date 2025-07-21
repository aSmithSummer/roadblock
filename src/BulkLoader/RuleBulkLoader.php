<?php

namespace aSmithSummer\Roadblock\BulkLoader;

use SilverStripe\Dev\CsvBulkLoader;

class RuleBulkLoader extends CsvBulkLoader
{

    /**
     * @var array
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    public $duplicateChecks = [
        'Title' => 'Title',
    ];

}