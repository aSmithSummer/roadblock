<?php

namespace aSmithSummer\Roadblock\Admin;

use aSmithSummer\Roadblock\BulkLoader\IPRuleBulkLoader;
use aSmithSummer\Roadblock\BulkLoader\TitleDuplicateCheckBulkLoader;
use aSmithSummer\Roadblock\Form\GridFieldTestAction;
use aSmithSummer\Roadblock\Form\GridFieldTestAllButton;
use aSmithSummer\Roadblock\Model\Roadblock;
use aSmithSummer\Roadblock\Model\Infringement;
use aSmithSummer\Roadblock\Model\IPRule;
use aSmithSummer\Roadblock\Model\RequestType;
use aSmithSummer\Roadblock\Model\Rule;
use aSmithSummer\Roadblock\Model\RuleInspector;
use aSmithSummer\Roadblock\Model\URLRule;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Dev\CsvBulkLoader;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\Gridfield\Gridfield;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use UndefinedOffset\SortableGridField\Forms\GridFieldSortableRows;

class RoadblockAdmin extends ModelAdmin
{

    private static string $url_segment = 'roadblock';

    private static string $menu_title = 'Roadblocks';

    private static $menu_icon_class = 'font-icon-block';

    public $showImportForm = [
        IPRule::class,
        RequestType::class,
        Rule::class,
        URLRule::class,
        RuleInspector::class,
    ];

    private static array $managed_models = [
        Roadblock::class,
        Rule::class,
        RequestType::class,
        IPRule::class,
        URLRule::class,
        Infringement::class,
        RuleInspector::class,
    ];

    private static array $model_importers = [
        IPRule::class => IPRuleBulkLoader::class,
        RequestType::class => TitleDuplicateCheckBulkLoader::class,
        Rule::class => TitleDuplicateCheckBulkLoader::class,
        URLRule::class => TitleDuplicateCheckBulkLoader::class,
        RuleInspector::class => TitleDuplicateCheckBulkLoader::class,
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

        if ($this->modelClass === URLRule::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));

            if ($gridField instanceof GridField) {
                $gridField->getConfig()->addComponent(GridFieldSortableRows::create('Order'));
            }
        }

        if ($this->modelClass === RuleInspector::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));

            if ($gridField instanceof GridField) {
                $gridField->getConfig()->addComponent(GridFieldTestAction::create());
                $gridField->getConfig()->addComponent(GridFieldTestAllButton::create('buttons-before-left'));
            }
        }

        return $form;
    }

    public function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();

        // Remove import button for specific models
        if ($this->modelClass === Roadblock::class) {
            $config->removeComponentsByType(GridFieldImportButton::class);
        }

        return $config;
    }

}
