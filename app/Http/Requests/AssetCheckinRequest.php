<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Setting;

class AssetCheckinRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $settings = Setting::getSettings();

        $rules = [
            'set_requestable' => 'nullable|boolean',
            'storage_location_id' => 'nullable|integer|exists:locations,id',
        ];

        // The check-in form asks where the device is stored. Once storage
        // rooms exist that answer is required: a checked-in device with no
        // room is exactly the one nobody can find.
        if ($this->has('storage_location_id') && Location::storageRooms()->exists()) {
            $rules['storage_location_id'] = 'required|integer|exists:locations,id';
        }

        if ($settings->require_checkinout_notes) {
            $rules['note'] = 'string|required';
        }

        return $rules;
    }

    public function response(array $errors)
    {
        return $this->redirector->back()->withInput()->withErrors($errors, $this->errorBag);
    }
}
