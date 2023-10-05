<?php

namespace Roadblock\Tests;

use Roadblock\Model\RequestLog;
use Roadblock\Model\RoadblockRequestType;
use Roadblock\Model\RoadblockRule;
use Roadblock\Model\SessionLog;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;

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
        $request1->setIP('127.0.0.2');

        $request2 = New HTTPRequest('GET', 'test');
        $request2->setIP('127.0.0.1');

        $response1 = Director::test('admin/', null, null, 'GET', null, null, null, $request1);
        $response2 = Director::test('admin/', null, null, 'GET', null, null, null, $request2);

        $this->assertEquals(302, $response1->getStatusCode());
        $this->assertEquals(302, $response2->getStatusCode());

        $requestType = $this->objFromFixture(RoadblockRequestType::class, 'admin');
        $rule = RoadblockRule::create([
            'IPAddress' => 'Allowed',
            'IPAddressNumber' => '0',
            'IPAddressOffset' => '1',
            'Score' => 100.00,
            'Cumulative' => 'No',
            'Status' => 'Enabled',
            'RoadblockRequestTypeID' => $requestType->ID,
        ]);
        $rule->write();

        $response1 = Director::test('admin/', null, null, 'GET', null, null, null, $request1);
        $response2 = Director::test('admin/', null, null, 'GET', null, null, null, $request2);

        $this->assertEquals(302, $response1->getStatusCode());
        $this->assertEquals(302, $response2->getStatusCode());
        var_dump($response1);
        var_dump($response2);

        $rule->IPAddress = 'Denied';
        $rule->write();

        $response1 = Director::test('admin/', null, null, 'GET', null, null, null, $request);
        $response2 = Director::test('admin/', null, null, 'GET', null, null, null, $request2);

        $this->assertEquals(302, $response1->getStatusCode());
        $this->assertEquals(302, $response2->getStatusCode());

/*
        try {
            $this->get('test', null, null, $cookie);
        } catch(HTTPResponse_Exception $ex) {
            $this->assertEquals('Page Not Found. Please try again later.', $ex->getResponse()->getBody());
        }
*/
    }

}
