<?php

namespace App\TrustedPublishing;

/**
 * A valid OIDC token that no trusted publisher of the extension accepts: 403. The message is
 * the same whether or not the extension exists.
 */
class NoMatchingPublisher extends \RuntimeException {}
