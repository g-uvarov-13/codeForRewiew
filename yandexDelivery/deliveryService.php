<?php

namespace Sitecore\Delivery;

use Bitrix\Main\Loader;

use SiteCore\Entity\DeliveryCalculationTable;
use Bitrix\Main\Type\DateTime;
use SiteCore\Core;

class DeliveryService
{


    /**
     * @param $requestResult
     * @return bool|mixed
     * Получение id наиболее выгодного склада, исходя из цены доставки
     */
    public function yandexDeliveryCalculation($requestResult)
    {
        $deliveriesInfo = $requestResult['API_RESULT'];
        $infoTable = $requestResult['TABLE_INFO'];
        $prices = [];
        if (!empty($deliveriesInfo)) {
            //возвращаем id склада с наиболее дешевой доставкой
            foreach ($deliveriesInfo as $key => $deliveryInfo) {
                $prices[$key] = $deliveryInfo['price'];
            }
            foreach ($prices as $storeId => $price) {
                if ($price == min($prices)) {
                    $result = $storeId;
                }
            }
            return $result;
        } else {
            // если нам не пришла инфа с яндекса, будем брать наименьшую из табличек
            if (!empty($infoTable)) {
                //возвращаем id склада с наиболее дешевой доставкой
                foreach ($infoTable as $key => $info) {
                    $prices[$key] = $info['MIDDLE_PRICE'];
                }
                foreach ($prices as $storeId => $price) {
                    if ($price == min($prices)) {
                        $result = $storeId;
                    }
                }
                return $result;
            } else {
                //Если нету информации из таблицы
                return false;
            }
        }

    }

    /**
     * @param $deliveryInfo
     * Добавление новой записи в таблицу
     */
    public function addToYandexDeliveryTable($deliveryInfo)
    {
        $result = DeliveryCalculationTable::add([
            'STORE_ID' => $deliveryInfo['STORE_ID'],
            'CLIENT_FIAS' => $deliveryInfo['CLIENT_ADDRESS_FIAS'],
            'MIDDLE_PRICE' => $deliveryInfo['price'],
            'DISTANCE' => $deliveryInfo['distance_meters']
        ]);

        if (!$result->isSuccess()) {
            $log = [
                'date' => (new DateTime())->format('Y.m.d H:i:s'),
                'MESSAGE' => $deliveryInfo['message'],
                'code' => $deliveryInfo['code']
            ];
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/YandexDelivery.log', print_r($log, true), FILE_APPEND);
        } else {
            $log = [
                'date' => (new DateTime())->format('Y.m.d H:i:s'),
                'MESSAGE' => 'Успех',
                'code' => 'success'
            ];
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/YandexDelivery.log', print_r($log, true), FILE_APPEND);
        }
        return;
    }

    /**
     * @param $item
     * @param $deliveryInfo
     * Обновление данных по средней цене
     */
    public function updateYandexDeliveryTable($item, $deliveryInfo)
    {

        if (!empty($deliveryInfo['price'])) {
            $result = DeliveryCalculationTable::update(
                $item['ID'],
                [
                    //высчитываем среднюю цену
                    'MIDDLE_PRICE' => ($item['MIDDLE_PRICE'] + $deliveryInfo['price']) / 2,
                    'CALCULATION_DATE' => new DateTime()
                ]
            );
            if (!$result->isSuccess()) {
                var_dump($result->getErrorMessages());
            } else {
                $log = [
                    'date' => (new DateTime())->format('Y.m.d H:i:s'),
                    'MESSAGE' => 'Успех',
                    'code' => 'success'
                ];
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/YandexDelivery.log', print_r($log, true), FILE_APPEND);
            }
        } else {
            $log = [
                'date' => (new DateTime())->format('Y.m.d H:i:s'),
                'MESSAGE' => $deliveryInfo['message'],
                'code' => $deliveryInfo['code']
            ];
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/YandexDelivery.log', print_r($log, true), FILE_APPEND);
        }
        return;
    }


    /**
     * @param $info
     * @param $DELIVERY_ADDRESS
     * @param $DELIVERY_ADDRESS_FIAS
     * @return mixed
     * Отправка запроса в Яндекс, для получения цен доставки
     */
    public function requestYandexDelivery($info, $DELIVERY_ADDRESS, $DELIVERY_ADDRESS_FIAS)
    {
        $result = [];
        foreach ($info as $delivery) {
            if ($delivery['DELIVERY_ID'] == Core::DELIVERY_TO_ADDRESS_ID) {
                $storesId[] = $delivery['STORE']['ID'];

                $dataRequests[$delivery['STORE']['ID']] = array(
                    "items" => array(
                        ["quantity" => count($delivery['STORE']['BASKET']['ITEMS_AVAILABLE']),
                        ]),
                    "requirements" => array(
                        "taxi_class" => "express",

                    ),
                    "route_points" => array(
                        ["fullname" => $delivery['STORE']['ADDRESS']], // откуда
                        ["fullname" => $DELIVERY_ADDRESS], // куда

                    ),
                    "skip_door_to_door" => false
                );
            }
        }

        if (!empty($dataRequests)) {

            foreach ($dataRequests as $storeId => $dataRequest) {
                $url = "https://b2b.taxi.yandex.net/b2b/cargo/integration/v2/check-price";
                $curl = curl_init($url);
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept-Language: ru', 'Authorization: Bearer ' . CORE::YANDEX_TOKEN_DELIVERY));
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($dataRequest, JSON_UNESCAPED_UNICODE));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_HEADER, false);
                $result[$storeId] = curl_exec($curl);
                curl_close($curl);
            }
            foreach ($result as $storeId => $item) {
                $resultFormatted[$storeId] = json_decode($item, true);
                $resultFormatted[$storeId]['CLIENT_ADDRESS_FIAS'] = $DELIVERY_ADDRESS_FIAS;
                $resultFormatted[$storeId]['STORE_ID'] = $storeId;

            }
        }

        $result = DeliveryService::recordInTable($resultFormatted, $storesId, $DELIVERY_ADDRESS_FIAS);
        $result['DATA_REQUESTS_TO_YANDEX'] = $dataRequests;
        return $result;
    }

    /**
     * @param $deliveriesInfo
     * @param $storesId
     * @param $clientFias
     * @return mixed
     * Добавление/обновление информации по стоимости доставок от складов до адреса клиента
     */
    public function recordInTable($deliveriesInfo, $storesId, $clientFias)
    {
        $table = DeliveryCalculationTable::getList([
            'order' => ['ID' => 'asc'],
            'filter' => ['STORE_ID' => $storesId, 'CLIENT_FIAS' => $clientFias],
            'select' => ['ID', 'STORE_ID', 'CLIENT_FIAS', 'MIDDLE_PRICE', 'DISTANCE'],
        ]);
        while ($arRes = $table->fetch()) {
            $infoTable[$arRes['STORE_ID']] = $arRes;
        }
        if (!empty($deliveriesInfo)) {
            foreach ($deliveriesInfo as $key => $deliveryInfo) {
                // Если уже существует связка fias клиента - id склада - высчитываем среднюю цену, делаем апдейт
                if (!empty($infoTable[$key])) {
                    DeliveryService::updateYandexDeliveryTable($infoTable[$key], $deliveryInfo);
                } else { // иначе, добавляем новую связку
                    DeliveryService::addToYandexDeliveryTable($deliveryInfo);
                }
            }
        } else {
            // Пишем в лог, что с яндекса ничего не пришло
            $log = [
                'date' => (new DateTime())->format('Y.m.d H:i:s'),
                'MESSAGE' => 'Сервис Яндекс доставки не дал ответа',
                'code' => 'error'
            ];
        }
        file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/YandexDelivery.log', print_r($log, true), FILE_APPEND);
        $result['API_RESULT'] = $deliveriesInfo;
        $result['TABLE_INFO'] = $infoTable;

        return ($result);
    }

}