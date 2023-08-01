<?php


namespace App\Http\Controllers\api\v1;


use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\v1\ServiceListService;
use App\Http\Requests\ServiceListRequest;


class ServiceListController extends Controller
{
    // create service list function
    public function __invoke( ServiceListRequest $request)
    {
        try {
            $this->httpRequestLog(__FILE__,__LINE__,__FUNCTION__);
            $serviceListService = new ServiceListService($request);
            $response = $serviceListService->getResponse();
            // dd($response);
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
