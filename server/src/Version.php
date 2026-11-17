<?php

namespace Dolicli;

/**
 * Version of dolicli and of its data contracts. VERSION and BODY_SHA256 are
 * replaced by the build (build/build-server.php); 'dev' means the sources are run directly.
 */
final class Version
{
    const VERSION = 'dev';

    /**
     * SHA256 of the built body with this value left empty: recompute it by blanking
     * the value in the file and hashing the result.
     */
    const BODY_SHA256 = '';

    const ENVELOPE_SCHEMA = '1.0';

    const STATE_SCHEMA = '1.0';

    const MIN_PHP = '7.0.0';
}
