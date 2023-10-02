<?php
namespace Roadblock\Traits;

use UAParser\Parser;

Trait UseragentNiceTrait
{
    public function getFriendlyUserAgent(): string
    {
        if (!$this->UserAgent) {
            return '';
        }

        $parser = Parser::create();
        $result = $parser->parse($this->UserAgent);

        return _t(
            __CLASS__ . '.BROWSER_ON_OS',
            "{browser} on {os}.",
            ['browser' => $result->ua->family, 'os' => $result->os->toString()]
        );
    }
}
