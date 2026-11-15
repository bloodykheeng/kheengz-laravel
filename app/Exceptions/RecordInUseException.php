<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A delete refused because the record is still needed: orders point at it, products use it,
 * or reference data is still Active. Thrown from a model's `deleting` hook, so single
 * deletes, bulk deletes and tinker all meet the same rule, and rendered as a 409 whose
 * message the UI shows as it is. See docs/deletion-policy.md.
 */
class RecordInUseException extends ConflictHttpException {}
