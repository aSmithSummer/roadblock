<?php

namespace Roadblock\Admin;

use Roadblock\BulkLoader\RoadblockRuleBulkLoader;
use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockException;
use Roadblock\Model\RoadblockIPRule;
use Roadblock\Model\RoadblockRule;
use Roadblock\Model\RoadblockRequestType;
use Roadblock\Model\RoadblockURLRule;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Dev\CsvBulkLoader;


class RoadblockAdmin extends ModelAdmin
{

    private static string $url_segment = 'roadblock';

    private static string $menu_title = 'Roadblocks';

    private static array $managed_models = [
        Roadblock::class,
        RoadblockRule::class,
        RoadblockRequestType::class,
        RoadblockIPRule::class,
        RoadblockURLRule::class,
        RoadblockException::class,
    ];

    private static array $model_importers = [
        RoadblockRule::class => RoadblockRuleBulkLoader::class,
        RoadblockRequestType::class => CsvBulkLoader::class,
        RoadblockIPRule::class => CsvBulkLoader::class,
        RoadblockURLRule::class => CsvBulkLoader::class,
    ];

    public function getExportFields()
    {
        $modelClass = singleton($this->modelClass);
        return $modelClass->hasMethod('getExportFields') ? $modelClass->getExportFields() : $modelClass->summaryFields();
    }

}
