<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;

/**
 * A screen that acts on instances: the palette runs its commands on them.
 */
interface TargetAware
{
    /**
     * Target of the pilot (instance, or instances separated by commas), '' when none.
     */
    public function target(App $app): string;
}
