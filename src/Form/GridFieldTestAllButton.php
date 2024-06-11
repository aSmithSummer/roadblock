<?php

namespace aSmithSummer\Roadblock\Form;

use aSmithSummer\Roadblock\Model\Rule;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridFieldExportButton;

/**
 * Adds a "Custom Export list" button to the bottom of a {@link GridField}.
 *
 * @package forms
 * @subpackage fields-gridfield
 */
// phpcs:ignore Generic.Files.LineLength.TooLong, SlevomatCodingStandard.Files.LineLength.LineTooLong
class GridFieldTestAllButton extends GridFieldExportButton implements
    GridField_HTMLProvider,
    GridField_ActionProvider
{

    public function getHTMLFragments($gridField): array
    {
        $button = new GridField_FormAction(
            $gridField,
            'runAllTests',
            'Run all tests',
            'doalltests',
            []
        );
        $button->addExtraClass('btn btn-secondary no-ajax font-icon-sync action_export');
        $button->setForm($gridField->getForm());

        return [
            $this->targetFragment => '<p class="grid-csv-button">' . $button->Field() . '</p>',
        ];
    }
    public function getActions($gridField): array
    {
        return ['doalltests'];
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== 'doalltests') {
            return null;
        }

        Rule::runAssessments();

        $response = HTTPResponse::create();
        $response->redirect($_SERVER['HTTP_REFERER']);
        return $response;
    }

}
