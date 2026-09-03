<?php

namespace App\Services;

/**
 * A vendor link that could not be turned into a catalog row.
 *
 * Carries a message meant for the person who pasted the link, because every
 * cause they can do anything about — wrong site, no product on the page, the
 * vendor unreachable — is one they need told plainly.
 */
class CdwLookupFailed extends \RuntimeException {}
