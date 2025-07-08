<?php

namespace aSmithSummer\Roadblock\Form;

use aSmithSummer\Roadblock\Model\Rule;
use aSmithSummer\Roadblock\Model\RuleInspector;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionMenuItem;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;

class GridFieldTestAction extends AbstractGridFieldComponent implements
    GridField_ColumnProvider,
    GridField_ActionProvider,
    GridField_ActionMenuItem
{
    public function getTitle($gridField, $record, $columnName)
    {
        return 'Run assessment';
    }

    public function getGroup($gridField, $record, $columnName)
    {
        return GridField_ActionMenuItem::DEFAULT_GROUP;
    }

    public function getExtraData($gridField, $record, $columnName)
    {
        $field = $this->getCustomAction($gridField, $record);
        if ($field) {
            return array_merge($field->getAttributes(), [
                'classNames' => 'font-icon-sync action-detail',
            ]);
        }

        return [];
    }

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('Actions', $columns)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        if ($columnName === 'Actions') {
            return ['title' => ''];
        }
        return [];
    }

    public function getColumnsHandled($gridField)
    {
        return ['Actions'];
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        return $this->getCustomAction($gridField, $record)?->Field();
    }

    public function getActions($gridField)
    {
        return ['doruntest'];
    }

    private function getCustomAction($gridField, $record)
    {
        if (!$record->hasMethod('canEdit') || !$record->canEdit()) {
            return;
        }

        return GridField_FormAction::create(
            $gridField,
            'CustomAction' . $record->ID,
            'Custom action',
            'doruntest',
            ['RecordID' => $record->ID]
        )->addExtraClass(
            'action-menu--handled btn btn-outline-dark'
        );
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        // Note: The action name here MUST be lowercase. GridField does a strtolower transformation
        // before passing it in.
        if ($actionName !== 'doruntest' || !isset($arguments['RecordID'])) {
            return;
        }

        $inspector = RuleInspector::get_by_id($arguments['RecordID']);

        if (!$inspector) {
            return;
        }

        $rule = $inspector->Rule();
        $rule->assessmentResult($inspector);

        // output a success message to the user
        Controller::curr()->getResponse()
            ->setStatusCode(200)
            ->addHeader('X-Status', 'Test updated.');
    }
}
