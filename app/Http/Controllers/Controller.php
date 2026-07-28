<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Policies guard every controller in this app, so the trait belongs here
    // rather than being pulled in case by case and occasionally forgotten.
    use AuthorizesRequests;
}
