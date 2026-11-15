<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // `$this->authorize('update', $product)`: the policy says on which record.
    use AuthorizesRequests;
}
