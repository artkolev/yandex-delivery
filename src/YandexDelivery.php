<?php

namespace artkolev\yandex_delivery;

use palax\onec_catalog\core\order\model\Order;
use palax\onec_catalog\core\order\model\OrderItem;
use palax\onec_catalog\core\user\interface\UserModelInterface;

/**
 * Класс для работы c доставкой через Яндекс Такси
 */
class YandexDelivery
{
	public bool $debugMode = true;
    public string $langCode = 'ru_RU';
    public string $responseUrl = 'https://b2b.taxi.tst.yandex.net/api/';
    public string $taxiToken = 'test';
    public string $geoToken = 'test';
    public array $LOG = [];
    public array $ERRORS = [];

    private string $calculatePriceUrl = 'b2b/platform/pricing-calculator';
    private string $offersCreate = 'b2b/platform/offers/create';
    private string $geocoderUrl = 'https://geocode-maps.yandex.ru/1.x/';

    public function __construct() {
        $this->responseUrl = getenv('YANDEX_DELIVERY_URL');
        $this->taxiToken = getenv('YANDEX_DELIVERY_TOKEN');
        $this->geoToken = getenv('YANDEX_GEOCODER_TOKEN');
    }

    public function checkCoordinates($coordinates)
    {
        if (empty($coordinates)) {
            return [];
        }

        if (!is_array($coordinates)) {
            $coordinates = $this->parseCoordinates($coordinates);
        }

        return $coordinates;
    }

    public function parseCoordinates($coordinates): array
    {

        if (is_string($coordinates)) {
            $coords = explode(',', $coordinates);
            rsort($coords);

            return [
                'lat' => (float) $coords[0], // Широта в градусах.
                'lng' => (float) $coords[1], // Долгота в градусах.
            ];
        }

        return [];
    }

    public function calculateDeliveryPrice(
        int $total_weight,
        string $sourceStationId,
        string $destinationAddress,
        string $tariff
    ): string {

        $result = $this->sendPostResponse(
            json_encode([
                "source" => [
                    "platform_station_id" => $sourceStationId,
                ],
                "destination" => [
                    "address" => $destinationAddress
                ],
                "tariff" => $tariff,
                'total_weight' => $total_weight,
            ]),
            $this->responseUrl . $this->calculatePriceUrl
        );
        if (isset($result['error_details'])) {
            return 0;
        }

        return $result['pricing_total'];
    }

    public function createOffer(YandexOfferDTO $yandexOfferDTO): array
    {
        $items = [];

        /** @var OrderItem $item */
        foreach ($yandexOfferDTO->order->getItems()->all() as $item) {
            $items[] = [
                'count' => $item->getQuantity(),
                'name' => $item->getName(),
                'article' => $item->getId(),
                'billing_details' =>  [
                    'unit_price' =>  $item->getTotal(),
                    'assessed_unit_price' =>  $item->getTotal()
                ],
                'place_barcode' => $yandexOfferDTO->order->getId()
            ];
        }

        $data = [
            'info' =>  [
                'operator_request_id' => $yandexOfferDTO->order->id
            ],
            'source' =>  [
                'platform_station' =>  [
                    'platform_id' =>  $yandexOfferDTO->source_platform_id
                ],
            ],
            'destination' =>  [
                'type' =>  'custom_location',
                'custom_location' =>  [
                    'details' =>  [
                        'full_address' => $yandexOfferDTO->address,
                        'room' => $yandexOfferDTO->room
                    ]
                ],
            ],
            'items' => $items,
            'places' =>  [
                [
                    'physical_dims' =>  [
                        'weight_gross' =>  $yandexOfferDTO->order->getItemsCount()
                    ],
                    'barcode' =>  $yandexOfferDTO->order->getId(),
                ]
            ],
            'billing_info' =>  [
                'payment_method' =>  'already_paid'
            ],
            'recipient_info' =>  [
                'first_name' =>  $yandexOfferDTO->user->getName(),
                'last_name' =>  $yandexOfferDTO->user->getSurname(),
                'partonymic' =>  $yandexOfferDTO->user->getPatronymic(),
                'phone' =>  $yandexOfferDTO->user->getPhone(),
                'email' =>  $yandexOfferDTO->user->getEmail()
            ],
            'last_mile_policy' =>  'time_interval',
            'particular_items_refuse' =>  false
        ];

        return json_decode($this->sendPostResponse(json_encode($data), $this->responseUrl . $this->offersCreate), true);
    }

    /**
     * Пример $address = 'Москва, Тверская, д.7';
     */
    public function getCoordinatesByAddress(string $address)
    {
        $responseUrl = sprintf("%s?apikey=%s&format=json&geocode=%s", $this->geocoderUrl, $this->geoToken, urlencode($address));
        $result = $this->sendGetResponse($responseUrl);

        if (!empty($result) && !empty($result['response']) && !empty($result['response']['GeoObjectCollection']['featureMember'])) {
            return $result['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'];
        }

        return [];
    }

    private function sendGetResponse(string $url)
    {
        if (empty($url)) {
            return false;
        }

        $request = curl_init($url);

        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_HEADER         => FALSE,
        ]);

        $result = curl_exec($request);
        curl_close($request);

        return $result ? json_decode($result, true) : [];
    }

    private function sendPostResponse(string $body, string $url)
    {

        if (empty($body) || empty($url)) {
            return false;
        }

        $request = curl_init();

        curl_setopt_array($request, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Accept-Language: ' . $this->langCode,
                'Authorization: Bearer ' . $this->taxiToken,
                'Content-Type: application/json',
            ],
        ]);

        $result = curl_exec($request);
        curl_close($request);

        return !empty($result) ? json_decode($result, true) : [];
    }

	private function requestError(string $error_code): void
    {
		$this->ERRORS[$error_code] = $error_code;
	}

	private function setErrorLog(string $phone, string $smsText, string $sendDateTime = ''): bool
    {

        if (!$this->debugMode) {
            return false;
        }

        if (empty($sendDateTime)) {
            $sendDateTime = date('d-m-Y H:i:s');
        }

		$this->LOG[] = sprintf("В %s на телефон %s будет отправлено сообщение: %s <br> \n", $sendDateTime, $phone, $smsText);

        return true;
	}
}