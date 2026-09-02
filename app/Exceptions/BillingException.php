<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule violation that should be shown to the trainer as a form error.
 */
class BillingException extends RuntimeException {}
