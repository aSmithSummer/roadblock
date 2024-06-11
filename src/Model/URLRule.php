<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;

class URLRule extends DataObject
{


    private static array $db = [
        'Title' => 'Varchar(64)',
        'Pregmatch' => 'Varchar(250)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
        'Order' => 'Int',
    ];

    private static string $table_name = 'URLRule';

    private static string $plural_name = 'URL Rules';

    private static array $indexes = [

        'UniqueTitle' => [
            'type' => 'unique',
            'columns' => ['Title'],
        ],
        'Order' => true,
    ];

    private static string $default_sort = 'Order';

    private static array $has_one = [
        'RequestType' => RequestType::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'Pregmatch' => 'Rule',
        'Status' => 'Status',
        'RequestType.Title' => 'Type',
    ];

    public function getExportFields(): array
    {

        $fields = [
            'Title' => 'Title',
            'Pregmatch' => 'Pregmatch',
            'Status' => 'Status',
            'RequestType.Title' => 'RequestType',
            'Order' => 'Order',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Pregmatch) {
            $result->addError(_t(self::class . '.FROM_VALIDATION', 'Pregmatch is required.'));
        }

        return $result;
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') || $member->canView();
    }

    /**
     * For bulk csv import, column is title of request type
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function importRequestType(string $csv, array $csvRow): void
    {
        if (!$csv || $csv !== $csvRow['RequestType']) {
            return;
        }

        $csv = trim($csv);

        $requestTypes = RequestType::get()->filter('Title', $csv);

        if ($requestTypes) {
            $requestType = $requestTypes->first();
        } else {

            $requestType = RequestType::create([
                'Title' => $csv,
                'Status' => 'Disabled',
            ]);
            $requestType->write();
        }

        $this->RequestTypeID = $requestType->ID;
    }

    public static function getURLTypes(string $url): string
    {
        $urlRules = self::get()->filter(['Status' => 'Enabled']);

        $results = [];

        if ($urlRules) {
            foreach ($urlRules as $urlRule) {
                if (preg_match($urlRule->Pregmatch, $url)) {
                    $results[] = $urlRule->RequestType()->Title;
                }
            }
        }

        return implode(',', $results);
    }

}
