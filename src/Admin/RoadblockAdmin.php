<?php

namespace Roadblock\Admin;

use Roadblock\BulkLoader\RoadblockIPRuleBulkLoader;
use Roadblock\BulkLoader\RoadblockRequestTypeBulkLoader;
use Roadblock\BulkLoader\RoadblockRuleBulkLoader;
use Roadblock\BulkLoader\RoadblockURLRuleBulkLoader;
use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockException;
use Roadblock\Model\RoadblockIPRule;
use Roadblock\Model\RoadblockRule;
use Roadblock\Model\RoadblockRequestType;
use Roadblock\Model\RoadblockURLRule;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Forms\Gridfield\Gridfield;
use UndefinedOffset\SortableGridField\Forms\GridFieldSortableRows;

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
        RoadblockRequestType::class => RoadblockRequestTypeBulkLoader::class,
        RoadblockIPRule::class => RoadblockIPRuleBulkLoader::class,
        RoadblockURLRule::class => RoadblockURLRuleBulkLoader::class,
    ];

    public function getExportFields()
    {
        $modelClass = singleton($this->modelClass);
        return $modelClass->hasMethod('getExportFields') ? $modelClass->getExportFields() : $modelClass->summaryFields();
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        // This check is simply to ensure you are on the managed model you want adjust accordingly
        if ($this->modelClass === RoadblockURLRule::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));

            if ($gridField instanceof GridField) {
                $gridField->getConfig()->addComponent(GridFieldSortableRows::create('Order'));
            }
        }

        return $form;
    }

}
