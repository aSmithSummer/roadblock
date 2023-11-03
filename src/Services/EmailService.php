<?php

namespace aSmithSummer\Roadblock\Services;

use aSmithSummer\Roadblock\Model\RequestLog;
use aSmithSummer\Roadblock\Model\Roadblock;
use aSmithSummer\Roadblock\Model\SessionLog;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;

/**
 * Tracks a session.
 */
class EmailService
{

    use Extensible;
    use Injectable;
    use Configurable;

    public string $from = '';
    public string $to = '';
    public string $subject = '';
    public string $body = '';

    private static array $email_fields = [
        'from',
        'to',
        'subject',
        'body',
    ];

    public function __construct()
    {
        $this->from = self::config()->get('email_from');
        $this->to = self::config()->get('email_to');
    }

    public function updateInfoNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $this->to,
        ];

        $this->extend('updateInfoNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateMemberInfoNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $to,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $to,
        ];

        $this->extend('updateMemberInfoNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updatePartialNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $this->to,
        ];

        $this->extend('updatePartialNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateMemberPartialNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $to,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $to,
        ];

        $this->extend('updateMemberPartialNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateBlockedNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $this->to,
        ];

        $this->extend('updateBlockedNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateMemberBlockedNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $to,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $to,
        ];

        $this->extend('updateMemberBlockedNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateLatestNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $this->to,
        ];

        $this->extend('updateLatestNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    public function updateMemberLatestNotification(
        ?Member $member,
        SessionLog $sessionLog,
        ?Roadblock $roadblock,
        RequestLog $requestLog,
        string $to,
        string $subject,
        string $body
    ): void {
        $values = [
            'body' => $body,
            'from' => $this->from,
            'subject' => $subject,
            'to' => $to,
        ];

        $this->extend('updateMemberLatestNotification', $values, $member, $sessionLog, $roadblock, $requestLog);

        $this->update($values);
    }

    // phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
    public function createEmail()
    {
        return Email::create($this->from, $this->to, $this->subject, $this->body);
    }

    public function update(array $values): void
    {
        foreach ($values as $k => $v) {
            if (!in_array($k, self::$email_fields)) {
                continue;
            }

            $this->$k = $v;
        }
    }

}
