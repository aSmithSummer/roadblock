<?php

namespace aSmithSummer\Roadblock\Tests;

use aSmithSummer\Roadblock\Model\RequestLog;
use aSmithSummer\Roadblock\Model\RoadblockRequestType;
use aSmithSummer\Roadblock\Model\RoadblockRule;
use aSmithSummer\Roadblock\Model\SessionLog;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Silverstripe\Security\Group;
use Silverstripe\Security\Member;
use Silverstripe\Security\Permission;

class RoadblockTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = 'fixture.yml';

    public function testCapture()
    {
        DBDatetime::set_mock_now('2020-01-01 00:00:00');

        $this->get('admin/');
        $requestLog = RequestLog::get()->first()->toMap();

        $expected = [
            'LastEdited' => '2020-01-01 00:00:00',
            'Created' => '2020-01-01 00:00:00',
            'URL' => 'admin',
            'Verb' => 'GET',
            'IPAddress' => '127.0.0.1',
            'UserAgent' => 'CLI',
        ];

        foreach ($expected as $k => $v) {
            $this->assertEquals($expected[$k], $requestLog[$k]);
        }

        $sessionLog = SessionLog::get()->byID($requestLog['SessionLogID'])->toMap();

        $expected = [
            'LastEdited' => '2020-01-01 00:00:00',
            'Created' => '2020-01-01 00:00:00',
            'IPAddress' => '127.0.0.1',
            'UserAgent' => 'CLI',
        ];

        foreach ($expected as $k => $v) {
            $this->assertEquals($expected[$k], $sessionLog[$k]);
        }

        $requestType = $this->objFromFixture(RoadblockRequestType::class, 'admin');

        $this->assertEquals($requestLog['RoadblockRequestTypeID'], $requestType->ID);

        DBDatetime::clear_mock_now();
    }

    public function testCheckOK()
    {
        $cookie = ['PHPSESSID' => 'test'];
        $response = $this->get('admin/',null, null, $cookie);

        $this->assertEquals(302, $response->getStatusCode());

        $requestType = $this->objFromFixture(RoadblockRequestType::class, 'admin');
        $rule = RoadblockRule::create([
            'Level' => 'Session',
            'LoginAttemptsStatus' => 'Any',
            'TypeCount' => 0,
            'TypeStartOffset' => 60,
            'Verb' => 'Any',
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
            'RoadblockRequestTypeID' => $requestType->ID,
        ]);
        $rule->write();

        try {
            $this->get('admin/', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
    }

    public function testVerb()
    {
        $homepage = $this->objFromFixture(SiteTree::class, 'home_page');
        $homepage->publish("Stage", "Live");

        $cookie = ['PHPSESSID' => 'test'];
        $response = $this->get('test',null, null, $cookie);

        $this->assertEquals(200, $response->getStatusCode());

        $rule = RoadblockRule::create([
            'Level' => 'Session',
            'LoginAttemptsStatus' => 'Any',
            'Verb' => 'GET',
            'VerbCount' => 0,
            'VerbStartOffset' => 60,
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
        ]);
        $rule->write();

        $response = $this->post('test',null, null, $cookie);

        $this->assertEquals(200, $response->getStatusCode());

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
    }

    public function testCumulative()
    {
        $homepage = $this->objFromFixture(SiteTree::class, 'home_page');
        $homepage->publish("Stage", "Live");

        $cookie = ['PHPSESSID' => 'test'];
        $response = $this->get('test',null, null, $cookie);

        $this->assertEquals(200, $response->getStatusCode());

        $rule = RoadblockRule::create([
            'Level' => 'Session',
            'LoginAttemptsStatus' => 'Any',
            'Verb' => 'GET',
            'VerbCount' => 0,
            'VerbStartOffset' => 60,
            'Score' => 50.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
        ]);
        $rule->write();

        $response = $this->get('test',null, null, $cookie);

        $this->assertEquals(200, $response->getStatusCode());

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
    }

    public function testIPAddress()
    {
        $homepage = $this->objFromFixture(SiteTree::class, 'home_page');
        $homepage->publish("Stage", "Live");

        $request1 = New HTTPRequest('GET', 'test');
        $request1->setIP('127.0.0.1');

        $cookie = ['PHPSESSID' => 'test1'];
        $response1 = Director::test('admin/', null, null, 'GET', null, null, $cookie, $request1);
        $this->assertEquals(302, $response1->getStatusCode());

        $requestType = $this->objFromFixture(RoadblockRequestType::class, 'admin');
        $rule = RoadblockRule::create([
            'IPAddress' => 'Allowed',
            'IPAddressNumber' => '1',
            'IPAddressOffset' => '1',
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
            'RoadblockRequestTypeID' => $requestType->ID,
        ]);
        $rule->write();

        $cookie = ['PHPSESSID' => 'test2'];
        $response1 = Director::test('admin/', null, null, 'GET', null, null, $cookie, $request1);
        $this->assertEquals(302, $response1->getStatusCode());

        $rule->IPAddress = 'Denied';
        $rule->IPAddressNumber = 0;
        $rule->write();

        $cookie = ['PHPSESSID' => 'test3'];
        $response1 = Director::test('admin/', null, null, 'GET', null, null, $cookie, $request1);
        $this->assertEquals(302, $response1->getStatusCode());

        $request2 = New HTTPRequest('GET', 'test');
        $request2->setIP('127.0.0.2');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.2';

        $cookie = ['PHPSESSID' => 'test4'];

        try {
            Director::test('admin/', null, null, 'GET', null, null, $cookie, $request2);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }

        $rule->IPAddress = 'Allowed';
        $rule->IPAddressNumber = 1;
        $rule->write();

        $cookie = ['PHPSESSID' => 'test5'];

        try {
            Director::test('admin/', null, null, 'GET', null, null, $cookie, $request2);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
    }

    public function testGroups()
    {
        $homepage = $this->objFromFixture(SiteTree::class, 'home_page');
        $homepage->publish("Stage", "Live");

        $cookie = ['PHPSESSID' => 'test1'];
        $response = $this->get('test', null, null, $cookie);
        $this->assertEquals(200, $response->getStatusCode());

        $group = $this->objFromFixture(Group::class, 'groupone');
        $rule = RoadblockRule::create([
            'ExcludeGroup' => 0,
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
            'GroupID' => $group->ID,
        ]);
        $rule->write();

        $cookie = ['PHPSESSID' => 'test2'];

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }

        $rule->ExcludeGroup = 1;
        $rule->write();

        $cookie = ['PHPSESSID' => 'test3'];
        $response = $this->get('test', null, null, $cookie);
        $this->assertEquals(200, $response->getStatusCode());

        $member = $this->objFromFixture(Member::class, 'memberone');
        $this->logInAs($member);
        $cookie = ['PHPSESSID' => 'test4'];

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }

        $rule->ExcludeGroup = 0;
        $rule->write();

        $member = $this->objFromFixture(Member::class, 'membertwo');
        $this->logInAs($member);
        $cookie = ['PHPSESSID' => 'test5'];
        $response = $this->get('test', null, null, $cookie);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPermissions()
    {
        $homepage = $this->objFromFixture(SiteTree::class, 'home_page');
        $homepage->publish("Stage", "Live");

        $permission = $this->objFromFixture(Permission::class, 'testpermission');
        $rule = RoadblockRule::create([
            'ExcludePermission' => 1,
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
            'PermissionID' => $permission->ID,
        ]);
        $rule->write();

        $member = $this->objFromFixture(Member::class, 'memberone');
        $this->logInAs($member);

        $cookie = ['PHPSESSID' => 'test2'];

        $response = $this->get('test', null, null, $cookie);
        $this->assertEquals(200, $response->getStatusCode());

        $rule->ExcludePermission = 0;
        $rule->write();

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }

        $member = $this->objFromFixture(Member::class, 'membertwo');
        $this->logInAs($member);
        $group = $this->objFromFixture(Group::class, 'groupone');
        Permission::grant($group->ID,'testpermission');

        $cookie = ['PHPSESSID' => 'test3'];
        $response = $this->get('test', null, null, $cookie);
        $this->assertEquals(200, $response->getStatusCode());

        $rule->ExcludePermission = 1;
        $rule->write();

        $cookie = ['PHPSESSID' => 'test4'];

        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
    }

}
