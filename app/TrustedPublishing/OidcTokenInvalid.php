<?php

namespace App\TrustedPublishing;

/**
 * The OIDC token could not be verified (signature, issuer, audience, lifetime): 401.
 */
class OidcTokenInvalid extends \RuntimeException {}
