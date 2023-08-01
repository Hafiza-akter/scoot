<?php


namespace App\Services\v1;


use stdClass;
use App\Enums\Agency;
use Spatie\ArrayToXml\ArrayToXml;
use App\Services\Common\Farelogix;
use App\Services\Common\Navitaire;
use App\Services\Common\AbstractService;
use App\Services\Common\NavitaireSoapClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


class SeatAvailabilityService extends AbstractService
{
    /**
     * Fetch SeatAvailability data form Farelogix web service
     * Create product array and return
     * product array contains the proper format of SeatAvailability api
     *
     * @return stdClass
     */
    public function getResponse(): stdClass
    {


        $this->ndcResponse = (new NavitaireSoapClient($this))->doNavitaireRequest();
        $this->validateIATASeatAvailabilityRS();
        $this->makeServiceList();
        $this->makeFlightDetails();
        $this->makeSeatDetails();
        $this->makeOfferItemIDAndSeatNameMap();
        $this->flightList();
        $this->seatInfo();
        return (object) [
            "status_code" => 200,
            "responses_id" => $this->ndcResponse->SeatAvailabilityRS->ShoppingResponseID->ResponseID,
            "offer_id" => $this->ndcResponse->SeatAvailabilityRS->ALaCarteOffer->attributes->OfferID,
            "flight_list" => $this->clientResponse->flightList,
            "seat_info" => $this->clientResponse->seatInfo
        ];
    }


    /**
     * Host Links function for url make
     *
     * @return mixed
     */
    public function getHostLinks()
    {
        return app()->make(Navitaire::class)->getHostLink() . 'Selling/r3.x/v21.3/SeatAvailability';
    }


    /**
     * Prepare the whole seat availability SOAP XML request to make the NDC request
     *
     * @return string
     */
    public function getXmlRequest(): string
    {


        $buildReqData = [
            "DistributionChain" =>   app()->make(Navitaire::class)->getDistributionChain(),
            "PayloadAttributes" => app()->make(Navitaire::class)->getPayloadAttributes(),
            "POS" => $this->getPosForSeatAvailability(),
            "Request" => [
                "SeatAvailCoreRequest" => [
                    "_attributes" => [
                        "xmlns" => "http://www.iata.org/IATA/2015/EASD/00/IATA_OffersAndOrdersCommonTypes",
                    ],
                    "OfferRequest" => [
                        "Offer" => [
                            "OfferID" => $this->formRequest['ndc_params']['offer_id'] ?? 'No Offer Id',
                            "OwnerCode" => $this->formRequest['ndc_params']['owner_code']??'No Owner Code',
                            "Offeritem" => [
                                "OwnerCode" => $this->formRequest['ndc_params']['owner_code']??'No Owner Code',
                                "PaxSegmentRefID" => $this->formRequest['ndc_params']['pax_segment_ref_id']??'No Pax Segment Ref ID'
                            ]
                        ],
                    ],
                ],


            ]
        ];


       return ArrayToXml::convert($buildReqData, $this->root('IATA_SeatAvailabilityRQ'));
    }


    /**
     * For get pos xml code
     *
     */
    private function getPosForSeatAvailability(){
     return [
        "Country" =>[
            '_attributes' => [
                "xmlns" => "http://www.iata.org/IATA/2015/EASD/00/IATA_OffersAndOrdersCommonTypes",
            ],
            "CountryCode" => "SG"
            ]
     ];
    }


    /**
     * Prepare order or offer information to make the NDC request
     *
     * @return array
     */
    private function getOfferOrOrderRef(): array
    {
        $owner = Agency::Owner->value;
        if (isset($this->formRequest->ndc_params['order_id']) && !empty($this->formRequest->ndc_params['order_id'])) {
            return [
                'Order' => [
                  '@attributes' => [
                    'OrderID' => $this->formRequest->ndc_params['order_id'],
                    'Owner' => $owner,
                  ],
                ]
            ];
        }


        return [
            'Offer' => [
              '@attributes' => [
                'Owner' => $owner,
                'ResponseID' => $this->formRequest->ndc_params['responses_id'],
                'OfferID' => $this->formRequest->ndc_params['offer_id'],
              ],
            ]
        ];
    }


    /**
     * Findout passenger reference type
     *
     * @param  string $type
     * @return string
     */
    private function getPaxType(string $type): string
    {
        $paxType = '';
        if ($type === "adt_pax_ref") {
            $paxType = "ADT";
        } elseif ($type === 'chd_pax_ref') {
            $paxType = 'CNN';
        } else {
            $paxType = 'INF';
        }
        return $paxType;
    }


    /**
     * Prepare passenger reference info to make the NDC request
     *
     * @return array
     */
    private function getPassengerRef(): array
    {
        if (isset($this->formRequest->ndc_params['order_id']) && !empty($this->formRequest->ndc_params['order_id'])) {
            return [];
        }


        $passenger = [];
        $paxReferences = isset($this->formRequest->ndc_params['pax_references']) ? $this->formRequest->ndc_params['pax_references'] : [];
        $paxReferences = array_filter($paxReferences);


        $hasInfants = isset($paxReferences['inf_pax_ref']) ? count($paxReferences['inf_pax_ref']) : 0;
        $paxReferences  = [$paxReferences];


        foreach ($paxReferences as $refs) {
            foreach ($refs as $type => $paxIds) {
                foreach ($paxIds as $paxId) {
                    if ($paxId) {
                        $pax = [
                            'PTC' => $this->getPaxType($type),
                            '@attributes' => [
                            'PassengerID' => $paxId
                            ]
                        ];


                        if ($hasInfants) {
                            $infantRef = [];
                            $inf_pax_references = $paxReferences[0]['inf_pax_ref'];
                            foreach ($inf_pax_references as $inf) {
                                if (explode('.', $inf)[0] === $paxId) {
                                    $infantRef = ['InfantRef' => $inf];
                                }
                            }


                            $pax = array_merge($pax, $infantRef);
                        }


                        $passenger[] = $pax;
                    }
                }
            }
        }


        if (!count($passenger)) {
            return [];
        }


        return [
            'PassengerList' => [
              'Passenger' => $passenger
            ]
        ];
    }


    /**
     * Validate first the NDC response before preparing the client response
     *
     * @return void
     */
    private function validateIATASeatAvailabilityRS(): void
    {
        if (empty((array) $this->ndcResponse)) {
            throw new NotFoundHttpException("Invalid seat availability form request.", null, 400);
        }
        if (!property_exists($this->ndcResponse, "Response") || property_exists($this->ndcResponse, "Errors")) {
            throw new NotFoundHttpException("No seat available in the airline profile.", null, 400);
        }


    }


    /**
     * Prepare the list of available services
     *
     * @return void
     */
    private function makeServiceList(): void
    {
        $services = [];
        $rawItems = $this->ndcResponse->Response->ALaCarteOffer->OfferItem;


        $rawItems = is_array($rawItems) ? $rawItems : [$rawItems];




        $rawDefinitioins = isset($this->ndcResponse->Response->DataLists->ServiceDefinitionList->ServiceDefinition) ?
        $this->ndcResponse->Response->DataLists->ServiceDefinitionList->ServiceDefinition : [];
        $rawDefinitioins = is_array($rawDefinitioins) ? $rawDefinitioins : [$rawDefinitioins];
        foreach ($rawItems as $key => $offerItem) {
            $serviceDefinitionsID = (string) $offerItem->Service->ServiceDefinitionRefID;
            $seatName = '';
            $baseAmount = 0;
            foreach ($rawDefinitioins as $definition) {
                if ($serviceDefinitionsID === (string) $definition->ServiceDefinitionID) {
                    $seatName = (string) $definition->Name;
                    break;
                }
            }




            if ($offerItem->UnitPrice->BaseAmount === null) {
                $baseAmount = isset($offerItem->UnitPrice->TotalAmount->content) ?
                 (int) $offerItem->UnitPrice->TotalAmount->content : 0;
            } else {


                $baseAmount = isset($offerItem->UnitPrice->BaseAmount->content) ?
                                (int) $offerItem->UnitPrice->BaseAmount->content :
                                ((isset($offerItem->UnitPrice->BaseAmount) && is_numeric($offerItem->UnitPrice->BaseAmount)) ? (int) $offerItem->UnitPrice->BaseAmount : 0);
            }


            $paxRefIDs = [];
            $rawItemPaxRefIDs = $offerItem->Eligibility->PaxRefID;
            $rawItemPaxRefIDs = is_array($rawItemPaxRefIDs) ? $rawItemPaxRefIDs : [$rawItemPaxRefIDs];
            foreach ($rawItemPaxRefIDs as $paxRefID) {
                $paxRefIDs[] = (string) $paxRefID;
            }
            $totalTax = 0;




            if (isset($offerItem->UnitPrice->BaseAmount->attributes->Taxable) && $offerItem->UnitPrice->BaseAmount->attributes->Taxable === "false") {
                $totalTax = 0;
            } else {
                $totalTax = isset($offerItem->UnitPrice->Taxes->Total->content) ? (int) $offerItem->UnitPrice->Taxes->Total->content : 0;
            }


            $totalAmount = isset($offerItem->UnitPrice->TotalAmount->content) ?
                (int) $offerItem->UnitPrice->TotalAmount->content : 0;
            $currency = isset($offerItem->UnitPrice->TotalAmount->attributes->CurCode) ?
                (string) $offerItem->UnitPrice->TotalAmount->attributes->CurCode : "";


            $service = [
                'serviceDefinitionID' => $serviceDefinitionsID,
                'offerItemID' => (string)$offerItem->OfferItemID,
                'totalAmount' => $totalAmount,
                'baseAmount' => $baseAmount,
                'totalTax' => $totalTax,
                'currency' => $currency,
                'paxRefs' => $paxRefIDs,
                'seatName' => $seatName,
            ];


            $rawItemSegRefIDs =  $offerItem->Eligibility->OfferFlightAssociations->PaxSegmentReferences->PaxSegmentRefID;
            $rawItemSegRefIDs = is_array($rawItemSegRefIDs) ? $rawItemSegRefIDs : [$rawItemSegRefIDs];
            foreach ($rawItemSegRefIDs as $segmentId) {
                $services[(string)$segmentId][] = $service;
            }
        }


        $this->clientResponse->serviceList = $services;
    }


    /**
     * Prepare the details of available flights
     *
     * @return void
     */
    private function makeFlightDetails(): void
    {
        $flightDetails = [];
        $rawPaxSegment = $this->ndcResponse->Response->DataLists;
        $rawPaxSegment = is_array($rawPaxSegment) ? $rawPaxSegment : [$rawPaxSegment];
        foreach ($rawPaxSegment as $segment) {
            $isCodeShare = false;


            if (isset($segment->OperatingCarrierInfo) && $segment->OperatingCarrierInfo !== null) {
                $isCodeShare = true;
            } else {
                $isCodeShare = false;
            }


            $flightDetail = [
                'marketingCarrierCode' => (string)$segment->DatedMarketingSegmentList->DatedMarketingSegment->CarrierDesigCode,
                'flightNo' => (string)$segment->DatedMarketingSegmentList->DatedMarketingSegment->MarketingCarrierFlightNumberText,
                'depAirPortCode' => (string)$segment->DatedMarketingSegmentList->DatedMarketingSegment->Dep->IATA_LocationCode,
                'depDatetime' => $segment->DatedMarketingSegmentList->DatedMarketingSegment->Dep->AircraftScheduledDateTime,
                'arrAirPortCode' => (string)$segment->DatedMarketingSegmentList->DatedMarketingSegment->Arrival->IATA_LocationCode,
                'arrDatetime' => isset($segment->DatedMarketingSegmentList->DatedMarketingSegment->Arrival->AircraftScheduledDateTime) ? $segment->DatedMarketingSegmentList->DatedMarketingSegment->Arrival->AircraftScheduledDateTime : '',
                'tecnicalStopCount' => null,
                'isCodeShare' => $isCodeShare,
            ];
            $flightDetails[(string)$segment->PaxSegmentID] = $flightDetail;
        }


        $this->clientResponse->flightDetails = $flightDetails;
    }


    /**
     * Prepare the details of available seats
     *
     * @return void
     */
    private function makeSeatDetails(): void
    {
        $seatNameList = [];
        $rawServiceDefinition = isset($this->ndcResponse->SeatAvailabilityRS->DataLists->ServiceDefinitionList->ServiceDefinition) ?
            $this->ndcResponse->SeatAvailabilityRS->DataLists->ServiceDefinitionList->ServiceDefinition : [];
        $rawServiceDefinition = is_array($rawServiceDefinition) ? $rawServiceDefinition : [$rawServiceDefinition];
        foreach ($rawServiceDefinition as $serviceDefinition) {
            $segmentId = '';


            foreach ($this->clientResponse->serviceList as $seg => $segments) {
                foreach ($segments as $info) {
                    if ($info['serviceDefinitionID'] === $serviceDefinition->attributes->ServiceDefinitionID) {
                        $segmentId = $seg;


                        if (isset($seatNameList[$segmentId])) {
                            if (!in_array($serviceDefinition->Name, $seatNameList[$segmentId])) {
                                $seatNameList[$segmentId][] = $serviceDefinition->Name;
                            }
                        } else {
                            $seatNameList[$segmentId][] = $serviceDefinition->Name;
                        }


                        break;
                    }
                }
            }
        }


        $columnList = [];
        $rawSeatMap = $this->ndcResponse->SeatAvailabilityRS->SeatMap;
        $rawSeatMap = is_array($rawSeatMap) ? $rawSeatMap : [$rawSeatMap];
        foreach ($rawSeatMap as $seatMap) {
            $rawCabinCompartment = $seatMap->Cabin->CabinLayout;
            $rawCabinCompartment = is_array($rawCabinCompartment) ? $rawCabinCompartment : [$rawCabinCompartment];
            foreach ($rawCabinCompartment as $column) {
                $rawColumnID = is_array($column->Columns) ? $column->Columns : [$column->Columns];
                foreach ($rawColumnID as $columnID) {
                    $columnList[$seatMap->SegmentRef][] = $columnID->content;
                }
            }
        }


        $seatDetails = [];
        foreach ($seatNameList as $segmentId => $seat) {
            $seatDetail = [
                'seatNameList' => $seat,
                'columnList' => $columnList[$segmentId],
            ];


            $seatDetails[$segmentId] = $seatDetail;
        }
        $this->clientResponse->seatDetails = $seatDetails;
    }


    /**
     * Prepare the list of available offer item and seat name mapping
     *
     * @return void
     */
    private function makeOfferItemIDAndSeatNameMap(): void
    {
        $offerItemIDAndSeatNames = [];
        foreach ($this->clientResponse->serviceList as $services) {
            foreach ($services as $service) {
                if (isset($service['offerItemID']) && isset($service['seatName'])) {
                    $offerItemIDAndSeatNames[$service['offerItemID']] = $service['seatName'];
                }
            }
        }


        $this->clientResponse->offerItemIDAndSeatNames = $offerItemIDAndSeatNames;
    }


    /**
     * Prepare the final flight information for the client response
     *
     * @return void
     */
    private function flightList(): void
    {
        $flightList = [];
        foreach ($this->clientResponse->flightDetails as $flightDetail) {
            $flight = [];
            $flight['marketing_airline_cd'] = $flightDetail['marketingCarrierCode'];
            $flight['flight_no'] = $flightDetail['flightNo'];
            $flight['flight_number'] = sprintf("%s%s", $flightDetail['marketingCarrierCode'], $flightDetail['flightNo']);
            $flight['dep_datetime'] = $flightDetail['depDatetime'];
            $flight['dep_airport_cd'] = $flightDetail['depAirPortCode'];
            $flight['arr_datetime'] = $flightDetail['arrDatetime'];
            $flight['arr_airport_cd'] = $flightDetail['arrAirPortCode'];
            $flight['technical_stop'] = $flightDetail['tecnicalStopCount'];
            $flightList[] = $flight;
        }
        $this->clientResponse->flightList = $flightList;
    }




    /**
     * Prepare the final seat information for the client response
     *
     * @return void
     */
    private function seatInfo(): void
    {
        $seatInfo = [];
        foreach ($this->clientResponse->flightDetails as $segmentId => $flightDetail) {
            $seatDetail = [];
            $seatDetail['flight_number'] = sprintf("%s%s", $flightDetail['marketingCarrierCode'], $flightDetail['flightNo']);
            $seatDetail['seat_name_list'] = isset($this->clientResponse->seatDetails[$segmentId]['seatNameList']) ? $this->clientResponse->seatDetails[$segmentId]['seatNameList'] : '';
            $seatDetail['seat_price_list'] = $this->seatPrice($segmentId);
            $seatDetail['column_list'] = isset($this->clientResponse->seatDetails[$segmentId]['columnList']) ? $this->clientResponse->seatDetails[$segmentId]['columnList'] : [];
            $seatDetail['seat_map'] = $this->seatMap($segmentId);


            if (!empty($seatDetail['seat_name_list'])) {
                $responseSeatList = [];
                $count = 1;
                foreach ($seatDetail['seat_name_list'] as $key1 => $seatNameList) {
                    $changedSeatType = 'Seat Type ' . $count;
                    $responseSeatList[$seatNameList] = $changedSeatType;
                    $seatDetail['seat_name_list'][$key1] = $changedSeatType;
                    $count++;
                }


                foreach ($seatDetail['seat_price_list'] as $key2 => $seatPriceList) {
                    $changedSeatType = (!empty($responseSeatList[$seatPriceList['seat_name']])) ? $responseSeatList[$seatPriceList['seat_name']] : '-';
                    $seatDetail['seat_price_list'][$key2]['seat_name'] = $changedSeatType;
                }


                foreach ($seatDetail['seat_map'] as $key3 => $seatMap) {
                    $changedSeatType = (!empty($responseSeatList[$seatMap['seat_name']])) ? $responseSeatList[$seatMap['seat_name']] : '-';
                    $seatDetail['seat_map'][$key3]['seat_name'] = $changedSeatType;
                }
            }
            $seatInfo[] = $seatDetail;
        }
        $this->clientResponse->seatInfo = $seatInfo;
    }


    /**
     * Prepare the seat price
     *
     * @param  string $segmentId
     * @return array
     */
    private function seatPrice(string $segmentId): array
    {
        $seatPrices = [];
        foreach ($this->clientResponse->serviceList[$segmentId] as $service) {
            $seatPrice = [
                'seat_name' => $service['seatName'],
                'offer_item_id' => $service['offerItemID'],
                'amount' => $service['totalAmount'],
                'currency_code' => $service['currency'],
                'pax_references' => $service['paxRefs'],
                'segment_id'     => $segmentId,
            ];


            array_push($seatPrices, $seatPrice);
        }


        return $seatPrices;
    }


    /**
     * Check if the seat is assiableable to passenger
     *
     * @param  object $seat
     * @param  bool $chd_flg
     * @return bool
     */
    private function isAssignableSeatBySeatCharacteristics(object $seat, bool $chd_flg): bool
    {
        $is_assignable_seat = false;


        $required_seat_characteristics = config('const.REQUIRED_SEAT_CHARACTERISTICS');
        if ($chd_flg) {
            $required_seat_characteristics[] = 'IE';
        }


        if (isset($seat->SeatCharacteristics)) {
            $seat_characteristic_codes = (array) $seat->SeatCharacteristics->Code;


            foreach ($seat_characteristic_codes as $seat_characteristic_code) {
                if (in_array($seat_characteristic_code, $required_seat_characteristics)) {
                    $is_assignable_seat = true;
                    break;
                }
            }
        }


        return $is_assignable_seat;
    }


    /**
     * Check is there has any child passenger
     *
     * @return bool
     */
    private function hasAnyChildPassenger(): bool
    {
        $hasChild = false;
        $passengers = isset($this->ndcResponse->SeatAvailabilityRS->DataLists->PassengerList->Passenger) ?
        $this->ndcResponse->SeatAvailabilityRS->DataLists->PassengerList->Passenger : [];


        foreach ($passengers as $passenger) {
            if (isset($passenger->PTC) && $passenger->PTC === 'CNN') {
                $hasChild = true;
                break;
            }
        }


        return $hasChild;
    }


    /**
     * Prepare the seat map
     *
     * @param  string $segmentId
     * @return array
     */
    private function seatMap(string $segmentId): array
    {
        $seatMaps = [];
        $rawSeatMap = $this->ndcResponse->SeatAvailabilityRS->SeatMap;
        $rawSeatMap = is_array($rawSeatMap) ? $rawSeatMap : [$rawSeatMap];
        foreach ($rawSeatMap as $seatMap) {
            if ($segmentId === $seatMap->SegmentRef) {
                if ($this->clientResponse->flightDetails[$segmentId]['isCodeShare']) {
                    continue;
                }


                $rawCabinCompartment = $seatMap->Cabin;
                $rawCabinCompartment = is_array($rawCabinCompartment) ? $rawCabinCompartment : [$rawCabinCompartment];
                foreach ($rawCabinCompartment as $compartment) {
                    $rawSeatRow = is_array($compartment->Row) ? $compartment->Row : [$compartment->Row];
                    foreach ($rawSeatRow as $seatRow) {
                        $line = strval($seatRow->Number);
                        $rawSeat = is_array($seatRow->Seat) ? $seatRow->Seat : [$seatRow->Seat];
                        foreach ($rawSeat as $seat) {
                            if ($seat->OfferItemRefs === null) {
                                continue;
                            }


                            $offerItemIDs = explode(" ", $seat->OfferItemRefs);
                            $seatName = $this->clientResponse->offerItemIDAndSeatNames[$offerItemIDs[0]];
                            $availability = ($seat->SeatStatus === 'A') ? true : false;


                            $SeatCharacteristicsCode = [];
                            if (isset($seat->SeatCharacteristics->Code) && !empty($seat->SeatCharacteristics->Code)) {
                                $SeatCharacteristicsCode = (array) $seat->SeatCharacteristics->Code;
                            }


                            if ($this->isAssignableSeatBySeatCharacteristics($seat, $this->hasAnyChildPassenger())) {
                                $availability = false;
                            }


                            $seatMap = [];
                            $seatMap['seat_name'] = $seatName;
                            $seatMap['offer_item_ids'] = $offerItemIDs;
                            $seatMap['designator'] = sprintf("%s%s", $line, $seat->Column);
                            $seatMap['line'] = $line;
                            $seatMap['column'] = $seat->Column;
                            $seatMap['availability'] = $availability;
                            $seatMap['characteristics'] = $SeatCharacteristicsCode;
                            $seatMaps[] = $seatMap;
                        } // end seat loop
                    } // end seatRow loop
                } // end compartment loop
            } // end if segmentID
        } // end for seatMap


        return $seatMaps;
    }
}
