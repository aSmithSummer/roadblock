<?php

namespace aSmithSummer\Roadblock\Admin;

use aSmithSummer\Roadblock\BulkLoader\RoadblockIPRuleBulkLoader;
use aSmithSummer\Roadblock\BulkLoader\RoadblockRequestTypeBulkLoader;
use aSmithSummer\Roadblock\BulkLoader\RoadblockRuleBulkLoader;
use aSmithSummer\Roadblock\BulkLoader\RoadblockURLRuleBulkLoader;
use aSmithSummer\Roadblock\Form\GridFieldTestAction;
use aSmithSummer\Roadblock\Form\GridFieldTestAllButton;
use aSmithSummer\Roadblock\Model\Roadblock;
use aSmithSummer\Roadblock\Model\RoadblockException;
use aSmithSummer\Roadblock\Model\RoadblockIPRule;
use aSmithSummer\Roadblock\Model\RoadblockRequestType;
use aSmithSummer\Roadblock\Model\RoadblockRule;
use aSmithSummer\Roadblock\Model\RoadblockRuleInspector;
use aSmithSummer\Roadblock\Model\RoadblockURLRule;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\Gridfield\Gridfield;
use UndefinedOffset\SortableGridField\Forms\GridFieldSortableRows;

class RoadblockAdmin extends ModelAdmin
{

    private static string $url_segment = 'roadblock';

    private static string $menu_title = 'Roadblocks';

    private static $menu_icon_class = 'font-icon-block';

    private static array $managed_models = [
        Roadblock::class,
        RoadblockRule::class,
        RoadblockRequestType::class,
        RoadblockIPRule::class,
        RoadblockURLRule::class,
        RoadblockException::class,
        RoadblockRuleInspector::class,
    ];
    // phpcs:ignore SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
    private static array $model_importers = [
        RoadblockIPRule::class => RoadblockIPRuleBulkLoader::class,
        RoadblockRequestType::class => RoadblockRequestTypeBulkLoader::class,
        RoadblockRule::class => RoadblockRuleBulkLoader::class,
        RoadblockURLRule::class => RoadblockURLRuleBulkLoader::class,
        RoadblockRuleInspector::class => CsvBulkLoader::class,
    ];

    /**
     * @return array
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    public function getExportFields()
    {
        $modelClass = singleton($this->modelClass);

        return $modelClass->hasMethod(
            'getExportFields'
        ) ? $modelClass->getExportFields() : $modelClass->summaryFields();
    }

    /**
     * @param int|null $id
     * @param \SilverStripe\Forms\FieldList $fields
     * @return \SilverStripe\Forms\Form A Form object with one tab per {@link \SilverStripe\Forms\GridField\GridField}
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    public function getEditForm($id = null, $fields = null): Form
    {
        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === RoadblockURLRule::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));

            if ($gridField instanceof GridField) {
                $gridField->getConfig()->addComponent(GridFieldSortableRows::create('Order'));
            }
        }

        if ($this->modelClass === RoadblockRuleInspector::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));

            if ($gridField instanceof GridField) {
                $gridField->getConfig()->addComponent(GridFieldTestAction::create());
                $gridField->getConfig()->addComponent(GridFieldTestAllButton::create('buttons-before-left'));
            }
        }

        return $form;
    }

}
