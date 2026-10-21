<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * GET /v1/account
     */
    public function show(Request $request): AccountResource
    {
        return new AccountResource($request->user()->load('organizations'));
    }
}
