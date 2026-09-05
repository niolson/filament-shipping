<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to approve a service nobody has named.
 *
 * ADR-0003 decision 2: "normalization is a precondition of approval, not a
 * parallel track". You cannot say automation may buy a thing before saying
 * what the thing is.
 */
class UnnormalizedServiceApprovalException extends RuntimeException {}
