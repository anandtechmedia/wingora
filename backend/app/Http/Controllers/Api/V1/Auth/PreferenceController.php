<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\LocaleFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(LocaleFormat::activeCodes())]]);
        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return response()->json(['data' => ['locale' => $data['locale']]]);
    }
}
