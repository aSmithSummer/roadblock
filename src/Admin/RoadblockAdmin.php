<?php

namespace Roadblock\Admin;

use Roadblock\Model\Roadblock;
use Roadblock\Model\RoadblockRule;
use Roadblock\Model\RoadblockRequestType;
use SilverStripe\Admin\ModelAdmin;


class RoadblockAdmin extends ModelAdmin
{

    private static string $url_segment = 'roadblock';

    private static string $menu_title = 'Roadblocks';

    private static array $managed_models = [
        Roadblock::class,
        RoadblockRule::class,
        RoadblockRequestType::class,
    ];

}
