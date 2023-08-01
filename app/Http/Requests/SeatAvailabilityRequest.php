<?php


namespace App\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;


class SeatAvailabilityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gds_code' => 'nullable|numeric',
            'ndc_params.fff' => 'required|string',
            'ndc_params.order_id' => 'required_without:ndc_params.responses_id|string',
            'ndc_params.offer_id' => 'required_without:ndc_params.order_id|string',
            'ndc_params.responses_id' => 'required_without:ndc_params.order_id|exclude_with:ndc_params.order_id|string',
            "ndc_params.pax_references" => "nullable|array|min:1|max:3",
            'ndc_params.pax_references.adt_pax_ref' => 'nullable|array',
            'ndc_params.pax_references.chd_pax_ref' => 'nullable|array',
            'ndc_params.pax_references.inf_pax_ref' => 'nullable|array'
        ];
    }


    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */


    public function attributes(): array
    {
        return [
            'gds_code' => 'GDS Code',
            'ndc_params.order_id' => 'Order Id',
            'ndc_params.offer_id' => 'Offer Id',
            'ndc_params.responses_id' => 'Response Id',
            'ndc_params.pax_references' => 'Passenger References',
            'ndc_params.pax_references.adt_pax_ref' => 'Passenger Adult',
            'ndc_params.pax_references.chd_pax_ref' => 'Passenger Child',
            'ndc_params.pax_references.inf_pax_ref' => 'Passenger Infant',


        ];
    }
}
