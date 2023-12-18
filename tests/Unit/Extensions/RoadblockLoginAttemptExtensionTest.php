<?php

namespace aSmithSummer\Roadblock\Tests;

use aSmithSummer\Roadblock\Extensions\RoadblockLoginAttemptExtension;
use aSmithSummer\Roadblock\Model\RequestLog;
use ReflectionClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class RoadblockLoginAttemptExtensionTest extends SapphireTest
{
    public function testInstanceProperties(): void
    {
        $class = Injector::inst()->get(RoadblockLoginAttemptExtension::class);

        $db = [
            'UserAgent' => 'Varchar(255)',
        ];

        $reflection = new ReflectionClass(get_class($class));
        $props = $reflection->getProperty('db');
        $props->setAccessible(true);
        $value = $props->getValue($class);

        $this->assertEquals($db, $value);

        $has_one = [
            'RequestLog' => RequestLog::class, // only linked if the member actually exists
        ];

        $props = $reflection->getProperty('has_one');
        $props->setAccessible(true);
        $value = $props->getValue($class);

        $this->assertEquals($has_one, $value);
    }

}
