<?php

namespace aSmithSummer\Roadblock\Tests;

use aSmithSummer\Roadblock\Model\RequestLog;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;

class RoadblockMemberAuthenticatorExtensionTest extends SapphireTest
{
    public function testUpdateLoginAttempt(): void
    {
        $loginAttempt = LoginAttempt::create([
            'IP' => '100.100.100.100',
            'EmailHashed' => '123456789',
            'Status' => LoginAttempt::SUCCESS,
            'MemberID' => 1,
        ]);

        $request = New HTTPRequest('GET', 'test');
        $data = [];

        $memberAuthenticator = new MemberAuthenticator();
        $memberAuthenticator->invokeWithExtensions('updateLoginAttempt', $loginAttempt, $data, $request);

        $expected = [
            'IP' => '100.100.100.100',
            'EmailHashed' => '123456789',
            'Status' => 'Success',
            'MemberID' => 1,
            'UserAgent' => 'CLI',
            'RequestLogID' => null,
        ];

        foreach ($expected as $k => $v) {
            $this->assertEquals($expected[$k], $loginAttempt->{$k});
        }

        $requestLog = RequestLog::create([
            'ID' => 1,
            'MemberID' => 1,
            'URL' => 'test',
        ]);

        $requestLog->write();
        $this->assertEquals(1, $requestLog->ID);

        $_COOKIE['PHPSESSID'] = 'test1';

        $sessionLog = RequestLog::getCurrentSession();
        $sessionLog->MemberID = 1;
        $sessionLog->write();
        $sessionLog->Requests()->add($requestLog);
        $controller = Controller::curr();
        $controller->setRequest($request);

        $memberAuthenticator->invokeWithExtensions('updateLoginAttempt', $loginAttempt, $data, $request);

        $expected = [
            'IP' => '100.100.100.100',
            'EmailHashed' => '123456789',
            'Status' => 'Success',
            'MemberID' => 1,
            'UserAgent' => 'CLI',
            'RequestLogID' => 1,
        ];

        foreach ($expected as $k => $v) {
            $this->assertEquals($expected[$k], $loginAttempt->{$k});
        }
    }

}
