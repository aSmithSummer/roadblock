<?php

namespace aSmithSummer\Roadblock\Admin;

use aSmithSummer\Roadblock\Model\SessionLog;
use SilverStripe\Admin\ModelAdmin;

class SessionsAdmin extends ModelAdmin
{

    private static string $url_segment = 'sessions';

    private static string $menu_title = 'Sessions';

    private static array $managed_models = [SessionLog::class];

}
