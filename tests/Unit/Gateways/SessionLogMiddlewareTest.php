<?php

namespace aSmithSummer\Roadblock\Tests;

use aSmithSummer\Roadblock\Gateways\SessionLogMiddleware;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestSession;

class SessionLogMiddlewareTest extends SapphireTest
{
    public static function setUpBeforeClass(): void
    {
        // TODO make this nicer
        $request = New HTTPRequest('GET', 'test');
        $testSession = new TestSession();
        $request->setSession($testSession->session());
        $controller = Controller::curr();
        $controller->setRequest($request);
    }
    public function testProcess()
    {
        $_SERVER['REMOTE_ADDR'] = '100.100.100.100';
        $request = Controller::curr()->getRequest()
            ->setUrl('/pages')
            ->setIP($_SERVER['REMOTE_ADDR']);

        Injector::inst()->registerService($request, HTTPRequest::class);

        $this->assertEquals('100.100.100.100', $request->getIP());

        $middleware = Injector::inst()->get(SessionLogMiddleware::class);

        $handler = function (HTTPRequest $request) {
            return AdminRootController::create()
                ->handleRequest($request);
        };

        /** @var HTTPResponse $response */
        $response = $middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
    }

}
