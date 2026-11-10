<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Emergency contacts follow the personal-details rules: the employee and HR only. */
class EmergencyContactController extends Controller
{
    public const MAX_CONTACTS = 5;

    public function index(Employee $employee): JsonResponse
    {
        Gate::authorize('viewPersonal', $employee);

        return response()->json(['data' => $employee->emergencyContacts()->orderByDesc('is_primary')->orderBy('id')->get()->map($this->present(...))]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('updatePersonal', $employee);
        $data = $request->validate($this->rules(true));
        abort_if($employee->emergencyContacts()->count() >= self::MAX_CONTACTS, 422, __('An employee may have at most :max emergency contacts.', ['max' => self::MAX_CONTACTS]));

        $contact = DB::transaction(function () use ($employee, $data) {
            $contact = new EmergencyContact($data);
            $contact->forceFill(['employee_id' => $employee->id]);
            $this->keepSinglePrimary($employee, $contact);
            $contact->save();

            return $contact;
        });

        return response()->json(['data' => $this->present($contact)], 201);
    }

    public function update(Request $request, Employee $employee, EmergencyContact $contact): JsonResponse
    {
        abort_unless((int) $contact->employee_id === $employee->id, 404);
        Gate::authorize('updatePersonal', $employee);
        $contact->fill($request->validate($this->rules(false)));
        DB::transaction(function () use ($employee, $contact) {
            $this->keepSinglePrimary($employee, $contact);
            $contact->save();
        });

        return response()->json(['data' => $this->present($contact)]);
    }

    public function destroy(Employee $employee, EmergencyContact $contact): JsonResponse
    {
        abort_unless((int) $contact->employee_id === $employee->id, 404);
        Gate::authorize('updatePersonal', $employee);
        $contact->delete();

        return response()->json(null, 204);
    }

    private function keepSinglePrimary(Employee $employee, EmergencyContact $contact): void
    {
        if ($contact->is_primary) {
            $employee->emergencyContacts()->where('is_primary', true)->whereKeyNot($contact->id ?? 0)->get()->each->update(['is_primary' => false]);
        }
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:160'],
            'relationship' => [$req, Rule::in(EmergencyContact::RELATIONSHIPS)],
            'phone' => [$req, 'string', 'max:30', 'regex:/^\+?[0-9 ().-]{6,30}$/'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function present(EmergencyContact $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'relationship' => $c->relationship, 'phone' => $c->phone, 'email' => $c->email, 'is_primary' => $c->is_primary];
    }
}
