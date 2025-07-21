<?php

namespace aSmithSummer\Roadblock\BulkLoader;

use SilverStripe\Dev\CsvBulkLoader;

class RequestTypeBulkLoader extends CsvBulkLoader
{

    /**
     * @var array
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    public $duplicateChecks = [
        'Title' => 'Title',
    ];

}
