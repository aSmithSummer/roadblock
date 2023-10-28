<?php

namespace aSmithSummer\Roadblock\Traits;

use UAParser\Parser;

// phpcs:ignore SlevomatCodingStandard.Classes.SuperfluousTraitNaming.SuperfluousSuffix
trait UseragentNiceTrait
{

    public function getFriendlyUserAgent(): string
    {
        if (!$this->UserAgent) {
            return '';
        }

        $parser = Parser::create();
        $result = $parser->parse($this->UserAgent);

        return _t(
            self::class . '.BROWSER_ON_OS',
            '{browser} on {os}.',
            ['browser' => $result->ua->family, 'os' => $result->os->toString()]
        );
    }

}
