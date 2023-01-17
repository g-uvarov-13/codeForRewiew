<?php
require_once 'config.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;
use SiteCore\Core;
use Bitrix\Main\Entity;
use Bitrix\Highloadblock as HL;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Engine\Contract\Controllerable;

Loader::includeModule('iblock');
Loader::includeModule('highloadblock');


class Dadata
{
    private $clean_url = "https://cleaner.dadata.ru/api/v1/clean";
    private $suggest_url = "https://suggestions.dadata.ru/suggestions/api/4_1/rs";
    private $token;
    private $secret;
    private $handle;

    public function __construct($token, $secret=null)
    {
        $this->token = $token;
        $this->secret = $secret;
    }

    /**
     * Initialize connection.
     */
    public function init()
    {
        $this->handle = curl_init();
        curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Token " . $this->token,
            // "X-Secret: " . $this->secret,
        ));
        curl_setopt($this->handle, CURLOPT_POST, 1);
    }

    //Точный адрес, нужен secret.key
    public function clean($type, $value)
    {
        $url = $this->clean_url . "/$type";
        $fields = array($value);
        return $this->executeRequest($url, $fields);
    }

    /**
     * Close connection.
     */
    public function close()
    {
        curl_close($this->handle);
    }

    private function executeRequest($url, $fields)
    {
        curl_setopt($this->handle, CURLOPT_URL, $url);
        if ($fields != null) {
            curl_setopt($this->handle, CURLOPT_POST, 1);
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, json_encode($fields));
        } else {
            curl_setopt($this->handle, CURLOPT_POST, 0);
        }
        $result = $this->exec();
        $result = json_decode($result, true);
        return $result;
    }

    private function exec()
    {
        $result = curl_exec($this->handle);
        $info = curl_getinfo($this->handle);
        if ($info['http_code'] == 429) {
            throw new TooManyRequests();
        } elseif ($info['http_code'] != 200) {
            throw new Exception('Request failed with http code ' . $info['http_code'] . ': ' . $result);
        }
        return $result;
    }
    public function suggest($type, $fields)
    {
        $url = $this->suggest_url . "/suggest/$type";
        return $this->executeRequest($url, $fields);
    }
}
$token = option::get('integration', 'dadata_api_key');


$dadata = new Dadata($token,null);
$dadata->init();


//Адреса
$core = Core::getInstance();
$hlbl = $core->getHlBlockId(Core::HL_DELIVERY_ADDRESS);
$hlblock = HL\HighloadBlockTable::getById($hlbl)->fetch();
$entity = HL\HighloadBlockTable::compileEntity($hlblock);
$entity_data_class = $entity->getDataClass();

$rsData = $entity_data_class::getList(
    [
        "select" => ["*"],
        "order" => ["ID" => "ASC"],
    ]
);
while ($element = $rsData->Fetch()) {
    if (strlen($element['UF_FIAS_CODE']) == 23) {   //  Проверка по цифровому ФИАСу(старый формат кода ФИАСа был в цифрах )
        $adressToUpdate[$element['ID']] = $dadata->suggest("address",
            ["query" => $element['UF_REGION'] . ', ' . $element['UF_CITY'] . ',' . $element['UF_STREET'] . ',' . $element['UF_HOUSE'],
                "count" => 1])['suggestions'][0];
    }
}

file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/log/UpdatedAdresses.log', print_r($adressToUpdate, true), FILE_APPEND);
foreach ($adressToUpdate as $addressId => $address) {
    if (!empty($address['data']['house_fias_id'])) {
        $entity_data_class::update(
            $addressId,
            ["UF_FIAS_CODE" => $address['data']['house_fias_id']]);
    } elseif (!empty($address['data']['street_fias_id'])) {
        $entity_data_class::update(
            $addressId,
            ["UF_FIAS_CODE" => $address['data']['street_fias_id']]);
    }
}

?>
