<?php

namespace aSmithSummer\Roadblock\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;

/**
 * Tracks a session.
 */
class IPRule extends DataObject
{


    private static array $db = [
        'Description' => 'Varchar(250)',
        'Permission' => "Enum('Allowed,Denied','Allowed')",
        'FromIPAddress' => 'Varchar(45)',
        'FromIPNumber' => 'BigInt',
        'ToIPAddress' => 'Varchar(45)',
        'ToIPNumber' => 'BigInt',
        'CIDRBlock' => 'Varchar(50)',
        'Status' => "Enum('Enabled,Disabled','Enabled')",
    ];

    private static string $table_name = 'IPRule';

    private static string $plural_name = 'IP Addresses';

    private static array $indexes = [

        'UniqueCombination' => [
            'type' => 'unique',
            'columns' => ['Permission', 'FromIPNumber', 'ToIPNumber'],
        ],
    ];

    private static array $summary_fields = [
        'Permission' => 'Permission',
        'FromIPAddress' => 'From IP Address',
        'ToIPAddress' => 'To IP Address',
        'CIDRBlock' => 'CIDR Block',
        'Status' => 'Status',
        'Description' => 'Description',
    ];

    private static array $searchable_fields = [
        'Description',
        'FromIPAddress',
        'FromIPNumber',
        'ToIPAddress',
        'ToIPNumber',
        'CIDRBlock',
        'Status',
    ];

    private static string $default_sort = 'FromIPNumber';

    private static array $belongs_many_many = [
        'RequestTypes' => RequestType::class,
    ];


    public function Title()
    {
        if (!empty($this->CIDRBlock)) {
            return "{$this->CIDRBlock} - {$this->Permission}";
        }

        if ($this->FromIPAddress === $this->ToIPAddress) {
            return "{$this->FromIPAddress} - {$this->Permission}";
        }

        return "{$this->FromIPAddress} - {$this->ToIPAddress} - {$this->Permission}";
    }


    public function validate(): ValidationResult
    {
        $this->applyIPResolution();

        $result = parent::validate();

        $conflicts = IPRule::get()
            ->exclude('ID', $this->ID)
            ->filter([
                'FromIPNumber:LessThanOrEqual' => (int) $this->ToIPNumber,
                'ToIPNumber:GreaterThanOrEqual' => (int) $this->FromIPNumber,
                'Permission' => $this->Permission,
            ]);

        if ($conflicts->exists()) {
            $result->addError(_t(self::class . '.FROM_VALIDATION', 'This rule overlaps with another ' .
                'rule that has a conflicting permission.'));
        }

        return $result;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->applyIPResolution();
    }

    public function applyIPResolution(): void
    {
        if ($this->CIDRBlock) {
            [$from, $to] = $this->cidrToRange($this->CIDRBlock);

            $this->FromIPAddress = self::numericToIp($from);
            $this->ToIPAddress = self::numericToIp($to);
            $this->FromIPNumber = $from;
            $this->ToIPNumber = $to;
        } else {
            $this->FromIPNumber = self::ipToNumeric($this->FromIPAddress);
            $this->ToIPNumber = self::ipToNumeric($this->ToIPAddress);
        }
    }

    protected function cidrToRange(string $cidr): array
    {
        if (!strpos($cidr, '/')) {
            throw new \InvalidArgumentException("Invalid CIDR format: {$cidr}");
        }

        [$ip, $mask] = explode('/', $cidr);
        $mask = (int)$mask;

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException("Only IPv4 CIDR blocks are supported. Invalid IP: {$ip}");
        }

        if ($mask < 0 || $mask > 32) {
            throw new \InvalidArgumentException("Invalid CIDR mask: /{$mask}");
        }

        $ipLong = ip2long($ip);
        $netmask = ~((1 << (32 - $mask)) - 1);
        $from = $ipLong & $netmask;
        $to = $from | ~$netmask;

        return [sprintf('%u', $from), sprintf('%u', $to)];
    }



    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }
    // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any') ||  ($member && $member->canView());
    }

    public function getExportFields(): array
    {

        $fields = [
            'Description' => 'Description',
            'FromIPAddress' => 'FromIPAddress',
            'FromIPNumber' => 'FromIPNumber',
            'ToIPAddress' => 'ToIPAddress',
            'ToIPNumber' => 'ToIPNumber',
            'CIDRBlock' => 'CIDRBlock',
            'Permission' => 'Permission',
            'Status' => 'Status',
            'getRequestTypesForCSV' => 'RequestTypes',
        ];

        $this->extend('updateExportFields', $fields);

        return $fields;
    }

    public function getRequestTypesForCSV(): string
    {
        return implode(',', $this->RequestTypes()->column('Title'));
    }

    /**
     *  For bulk csv import, column is comma separated list of request type titles within the cell
     *
     * @param string $csv
     * @param array $csvRow
     * @return void
     */
    public function importRequestTypes(string $csv, array $csvRow): void
    {
        if ($csv !== $csvRow['RequestTypes']) {
            return;
        }

        // Removes all relationships with request type
        $this->RequestTypes()->removeAll();

        foreach (explode(',', trim($csv) ?? '') as $identifier) {
            $filter = ['Title' => $identifier];
            $requestTypes = RequestType::get()->filter($filter);

            if (!$requestTypes) {
                continue;
            }

            foreach ($requestTypes as $requestType) {
                $this->RequestTypes()->add($requestType);
            }
        }
    }

    public static function ipToNumeric(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return sprintf('%u', ip2long($ip));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);

            if ($ipBin === false) {
                throw new \InvalidArgumentException("Invalid IPv6 address: {$ip}");
            }

            $bin = '';
            for ($i = 0; $i < strlen($ipBin); $i++) {
                $bin .= str_pad(decbin(ord($ipBin[$i])), 8, '0', STR_PAD_LEFT);
            }

            // Convert binary string to decimal using BCMath
            $decimal = '0';
            for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
                $decimal = bcmul($decimal, '2');
                $decimal = bcadd($decimal, $bin[$i]);
            }

            return $decimal;
        }

        throw new \InvalidArgumentException("Invalid IP address: {$ip}");
    }

    public static function numericToIp(string $numeric): string
    {
        // Try IPv4 first (fits within unsigned 32-bit integer)
        if (bccomp($numeric, '4294967295') <= 0) {
            return long2ip((int)$numeric);
        }

        // IPv6: Convert decimal back to 128-bit binary string
        $bin = '';
        $dec = $numeric;

        while (bccomp($dec, '0') > 0) {
            $bin = bcmod($dec, '2') . $bin;
            $dec = bcdiv($dec, '2', 0);
        }

        // Pad to 128 bits
        $bin = str_pad($bin, 128, '0', STR_PAD_LEFT);

        // Convert binary string to raw binary
        $ipBin = '';
        for ($i = 0; $i < 128; $i += 8) {
            $byte = substr($bin, $i, 8);
            $ipBin .= chr(bindec($byte));
        }

        $ip = inet_ntop($ipBin);

        if ($ip === false) {
            throw new \InvalidArgumentException("Invalid numeric value for IP: {$numeric}");
        }

        return $ip;
    }

    public static function getIPsForRange(string $from, string $to): array
    {
        $iPAddresses = [];

        while (bccomp($from, $to) <= 0) {
            $iPAddresses[] = IPRule::numericToIp($from);
            $from = bcadd($from, '1');
        }

        return $iPAddresses;
    }
}
