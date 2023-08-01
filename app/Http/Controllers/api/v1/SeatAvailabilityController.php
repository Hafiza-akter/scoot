<?php


namespace App\Http\Controllers\api\v1;
use App\Http\Controllers\Controller;
use App\Services\v1\SeatAvailabilityService;
use App\Http\Requests\SeatAvailabilityRequest;
use Throwable;


class SeatAvailabilityController extends Controller
{
     /**
     * Handle Scoot Seat Availability request & response
     *
     * @param SeatAvailabilityRequest $request
     * @return mixed
     */
    public function __invoke(SeatAvailabilityRequest $request)
    {
        try {
            $this->httpRequestLog(__FILE__, __LINE__, __FUNCTION__);


            $seatAvailabilityService = new SeatAvailabilityService($request);
            $response = $seatAvailabilityService->getResponse();
        } catch (Throwable $th) {
            $response = $this->httpErrorLog($th);
        } finally {
            $this->httpResponseLog($response, __FILE__, __LINE__, __FUNCTION__);


            $statusCode = $response->status_code;
            if (!property_exists($response, "err_msg")) {
                unset($response->status_code);
            }


            return response()->json($response, $statusCode);
        }
    }
}
