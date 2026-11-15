<?php

namespace App\Payments;

use RuntimeException;

/**
 * A payment gateway could not answer: unreachable, not set up, or the service switched off. Never
 * a refusal (a wrong account, a declined payment), which is an answer and comes back as data. The
 * caller decides what waits (a payout number waits for the Admin) and what fails.
 */
class GatewayUnavailable extends RuntimeException {}
