<?php

namespace aSmithSummer\Roadblock\BulkLoader;

use SilverStripe\Dev\CsvBulkLoader;

class RoadblockIPRuleBulkLoader extends CsvBulkLoader
{

    /**
     * @param array $record CSV data column
     * @param array $columnMap
     * @return DataObject
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint, SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    public function findExistingObject($record, $columnMap = [])
    {
        return DataObject::get($this->objectClass)
            ->filter([
                'IPAddress' => $record['IPAddress'],
                'Permission' => $record['Permission'],
            ])->first();
    }

}
