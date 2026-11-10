<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HolidayCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', HolidayCalendar::class);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return response()->json(HolidayCalendar::query()->orderBy('name')->paginate((int) $request->input('per_page', 25))
            ->through(fn (HolidayCalendar $c) => $this->present($c)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', HolidayCalendar::class);
        $country = (string) $request->input('country_code');
        $calendar = HolidayCalendar::create($request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('holiday_calendars')->where('tenant_id', $request->user()->tenant_id)],
            'country_code' => ['required', 'string', Rule::exists('countries', 'code')->where('is_active', true)],
            'subdivision_code' => ['nullable', 'string', Rule::exists('subdivisions', 'code')->where('country_code', $country)],
            'include_statutory' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $this->present($calendar)], 201);
    }

    public function show(HolidayCalendar $holidayCalendar): JsonResponse
    {
        Gate::authorize('view', $holidayCalendar);

        return response()->json(['data' => $this->present($holidayCalendar) + [
            'holidays' => $holidayCalendar->holidays->map(fn (Holiday $h) => $this->holiday($h)),
        ]]);
    }

    public function update(Request $request, HolidayCalendar $holidayCalendar): JsonResponse
    {
        Gate::authorize('update', $holidayCalendar);
        $holidayCalendar->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160', Rule::unique('holiday_calendars')->where('tenant_id', $request->user()->tenant_id)->ignore($holidayCalendar->id)],
            'include_statutory' => ['sometimes', 'boolean'],
            'country_code' => ['prohibited'],
            'subdivision_code' => ['prohibited'],
        ]));

        return response()->json(['data' => $this->present($holidayCalendar)]);
    }

    public function storeHoliday(Request $request, HolidayCalendar $holidayCalendar): JsonResponse
    {
        Gate::authorize('update', $holidayCalendar);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:160', Rule::unique('holidays')->where('holiday_calendar_id', $holidayCalendar->id)->where('date', $request->input('date'))],
            'is_paid' => ['sometimes', 'boolean'],
        ]);

        $holiday = new Holiday($data + ['type' => 'company']);
        $holiday->forceFill(['holiday_calendar_id' => $holidayCalendar->id])->save();

        return response()->json(['data' => $this->holiday($holiday)], 201);
    }

    public function destroyHoliday(Holiday $holiday): Response
    {
        Gate::authorize('update', $holiday->calendar);
        $holiday->delete();

        return response()->noContent();
    }

    private function present(HolidayCalendar $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'country_code' => $c->country_code, 'subdivision_code' => $c->subdivision_code, 'include_statutory' => $c->include_statutory];
    }

    private function holiday(Holiday $h): array
    {
        return ['id' => $h->public_id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'type' => $h->type, 'is_paid' => $h->is_paid];
    }
}
