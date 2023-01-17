<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
use SiteCore\Core;
use SiteCore\Tools\HLBlock;
use SiteCore\Tools\DataAlteration;
use Bitrix\Sale\Fuser;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;
use Bitrix\Sale\PersonType;
use Bitrix\Sale\PropertyValue;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\BasketItemBase;
use Bitrix\Sale\BasketPropertiesCollection;
use Bitrix\Main\UserTable;
use Bitrix\Sale;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Location\GeoIp;
use Bitrix\Sale\Location\LocationTable;

use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;

use Bitrix\Sale\Result;
use Bitrix\Sale\Services\Company;
use Sitecore\GiftCard\GiftCardAction;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Application;


use Bitrix\Main\Context;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;

class CardOrderComponent extends CBitrixComponent implements Controllerable
{
    /** @var HLBlock */
    private $designEntity; //Стили карты

    private $post;
    private $session;
    private $activeCard;

    public function onPrepareComponentParams($arParams)
    {
        $arParams = parent::onPrepareComponentParams($arParams);
        $arParams['NOMINAL_VALUES'] = [
            500,
            1000,
            2000,
            3000,
            5000,
        ];

        $arParams['NOMINAL_MIN'] = 500;
        $arParams['NOMINAL_MAX'] = 10000;

        $arParams['PAYMENT'] = 'sberbank';
        return $arParams;
    }

    /**
     * Настройки для action
     * @return array
     */
    public function configureActions()
    {
        return [
            'checkPhone' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod(
                        [
                            ActionFilter\HttpMethod::METHOD_GET,
                            ActionFilter\HttpMethod::METHOD_POST
                        ]
                    ),
                    new ActionFilter\Csrf(),
                ],
                'postfilters' => []
            ],
            'checkCode' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod(
                        [
                            ActionFilter\HttpMethod::METHOD_GET,
                            ActionFilter\HttpMethod::METHOD_POST
                        ]
                    ),
                    new ActionFilter\Csrf(),
                ],
                'postfilters' => []
            ]
        ];
    }
    /**
     * CardOrderComponent constructor.
     * @param CBitrixComponent|null $component
     * @throws SystemException
     */
    public function __construct(?CBitrixComponent $component = null)
    {
        parent::__construct($component);

        !$_SESSION['CARD_ORDER'] && ($_SESSION['CARD_ORDER'] = []);

        $this->post = DataAlteration::getPostData();
        $this->session = &$_SESSION['CARD_ORDER'];

        $this->activeCard = $this->post['EDIT_CARD'] ?? $this->session['CARDS'] ? count($this->session['CARDS']) - 1 : 0;

        $this->designEntity = (new HLBlock())->getHlEntityByName(Core::HL_BLOCK_CARD_DESIGN);
    }

    public function executeComponent()
    {
        $this->designValues();
        $this->getPayments();
        $this->defaultData();
        $this->post && $this->postAction();
        if ($this->arResult['ORDER']) {
            unset($_SESSION['CARD_ORDER']);
            $this->includeComponentTemplate('confirm');
            return;
        }

        $this->arResult = array_merge($this->arResult, $this->session);

        !$this->arResult['CARDS'][$this->activeCard] && $this->arResult['ACTIVE_FROM_POST'] && ($this->arResult['CARDS'][$this->activeCard] = $this->arResult['ACTIVE_FROM_POST']);
        !$this->arResult['CARDS'][$this->activeCard] && ($this->arResult['CARDS'][$this->activeCard] = [
            'DESIGN' => $this->arResult['DESIGN_VALUES'][0]['VALUE'],
            'NOMINAL' => $this->arParams['NOMINAL_VALUES'][0],
            'PAYMENT' => $this->arParams['PAYMENT'],
        ]);
        $this->arResult['CARDS'][$this->activeCard] && ($this->arResult['CARDS'][$this->activeCard]['ACTIVE'] = true);

        $this->arResult['TOTAL_PRICE'] = 0;
        foreach ($this->arResult['CARDS'] as $k => $card) {
            $k !== $this->activeCard && ($this->arResult['TOTAL_PRICE'] += (int)$card['NOMINAL']);
        }

        $this->IncludeComponentTemplate();

    }

    /**
     * Получение стилей ПК
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function designValues(): void
    {
        /** @var $optimPictures \OptimPictures\WebP */
        global $optimPictures;

        $cardDesign = $this->designEntity::getList([
            'select' => ['UF_IMAGE', 'UF_NAME'],
            'order' => ['UF_SORT' => 'asc'],
        ])->fetchAll();

        foreach ($cardDesign as $design) {
            $picture = CFile::ResizeImageGet($design['UF_IMAGE'], ['width' => 300, 'height' => 200])['src'];
            if (\Bitrix\Main\Loader::includeModule('optimpictures')) {
                $picture = $optimPictures->get($picture);
            }
            ($this->arResult['DESIGN_VALUES'][] = [
                'IMAGE' => $picture,
                'VALUE' => $design['UF_NAME'],
            ]);
        }
    }

    private function getPayments()
    {
        $this->arResult['PAYMENTS'] = PaySystemManager::getList(['filter' => [
            'ACTIVE' => 'Y',
            'XML_ID' => ['sberbank','sberpay'],
        ]])->fetchAll();
        foreach ($this->arResult['PAYMENTS'] as $key => $payment) {
            $this->arResult['PAYMENTS'][$key]['LOGOTIP'] = CFile::GetFileArray($payment['LOGOTIP'])['SRC'];
        }
    }

    private function defaultData(): void
    {
        global $USER;
        $user = $USER->IsAuthorized() ? CUser::GetByID($USER->GetID())->fetch() : [];
        !$this->session['PAYER_NAME'] && ($this->arResult['PAYER_NAME'] = $user['NAME']);
        !$this->session['PAYER_LAST_NAME'] && ($this->arResult['PAYER_LAST_NAME'] = $user['LAST_NAME']);
        !$this->session['PAYER_PHONE'] && ($this->arResult['PAYER_PHONE'] = DataAlteration::clearPhone($user['PERSONAL_PHONE']));
        !$this->session['PAYER_EMAIL'] && ($this->arResult['PAYER_EMAIL'] = $user['EMAIL']);
        if (!$this->session['CARDS']) {
            $this->arResult['CARDS'][0]['DESIGN'] = $this->arResult['DESIGN_VALUES'][0]['VALUE'];
            $this->arResult['CARDS'][0]['NOMINAL'] = $this->arParams['NOMINAL_VALUES'][0];
            $this->arResult['CARDS'][0]['PAYMENT'] = $this->arParams['PAYMENT'];
        }
    }

    private function postAction(): void
    {
        $this->session['PAYER_NAME'] = !empty($this->post['PAYER_NAME']) ? $this->post['PAYER_NAME'] : $this->arResult['PAYER_NAME'];
        $this->session['PAYER_LAST_NAME'] = !empty($this->post['PAYER_LAST_NAME']) ? $this->post['PAYER_LAST_NAME'] : $this->arResult['PAYER_LAST_NAME'];
        $this->session['PAYER_PHONE'] = !empty(DataAlteration::clearPhone($this->post['PAYER_PHONE'])) ? DataAlteration::clearPhone($this->post['PAYER_PHONE']) : $this->arResult['PAYER_PHONE'];
        $this->session['PAYER_EMAIL'] =!empty($this->post['PAYER_EMAIL']) ? $this->post['PAYER_EMAIL'] : $this->arResult['PAYER_EMAIL'];
        $this->session['PAYMENT'] = $this->post['PAYMENT'];
        $this->session['GIFT_CARD_NUMBER'] = $this->getCardNumber();
        $postCard = [
            'RECEIVER_NAME' => $this->post['RECEIVER_NAME'],
            'RECEIVER_LAST_NAME' => $this->post['RECEIVER_LAST_NAME'],
            'RECEIVER_PHONE' => DataAlteration::clearPhone($this->post['RECEIVER_PHONE']),
            'RECEIVER_EMAIL' => $this->post['RECEIVER_EMAIL'],
            'DESIGN' => $this->post['DESIGN'],
            'TEXT' => $this->post['TEXT'],
            'NOMINAL' => str_replace(' ', '', $this->post['NOMINAL_TEXT'] ?: $this->post['NOMINAL_RADIO']),
        ];
        if ($this->post['ACTIVE_CARD'] && $postCard['RECEIVER_EMAIL'] ) {
            $this->session['CARDS'][$this->post['ACTIVE_CARD'] - 1] = $postCard;
        }

        if ($this->post['ADD_CARD']) {
            $this->activeCard = count($this->session['CARDS']);
        }

        if ($this->post['EDIT_CARD']) {
            $this->activeCard = $this->post['EDIT_CARD'] - 1;
        }

        if ($this->post['REMOVE_CARD']) {
            $this->post['ACTIVE_CARD'] && ($this->activeCard = $this->post['ACTIVE_CARD'] - 1);
            $this->post['ACTIVE_CARD'] && ($this->arResult['ACTIVE_FROM_POST'] = $postCard);

            array_splice($this->session['CARDS'], $this->post['REMOVE_CARD'] - 1, 1);
            $this->post['REMOVE_CARD'] - 1 <= $this->activeCard && $this->activeCard--;
        }

        if ($this->post['SAVE_ORDER'])
        {
            $this->post['ACTIVE_CARD'] && ($this->activeCard = $this->post['ACTIVE_CARD'] - 1);

            if (!$this->validateOrderData()) {
                return;
            }

            try {
                $this->arResult['ORDER'] = $this->createOrder();
            } catch (Exception $e) {

                // daily_log('cardOrder', $e->getMessage(), "\n" . $e->getTraceAsString());
                $this->arResult['ERROR'] = 'Ошибка создания заказа';
                return;
            }
        }
    }
    private function validateOrderData(): bool
    {
        $message = [];
        if(!$this->session['PAYER_NAME']){
            $message[] = 'Поле Имя плательщика обязательно для заполнения';
        }
        if(!$this->session['PAYER_LAST_NAME']){
            $message[] = 'Поле Фамилия плательщика обязательно для заполнения';
        }
        if(!$this->session['PAYER_PHONE']){
            $message[] = 'Поле Телефон плательщика обязательно для заполнения';
        }

        if(!$this->session['PAYMENT']){
            $message[] = 'Оплата должна быть обязательно выбрана';
        }

        if (!$this->session['PAYER_EMAIL']) {
            $message[] = 'Поле E-mail плательщика обязательно для заполнения';
        } elseif (!check_email($this->session['PAYER_EMAIL'])) {
            $message[] = 'Введен некорректный E-mail плательщика';
        }

        $cards = $this->session['CARDS'];
        if(!$cards){
            $cards = [[
                'DESIGN' => $this->arResult['DESIGN_VALUES'][0]['VALUE'],
                'NOMINAL' => $this->arParams['NOMINAL_VALUES'][0],
            ]];
        }
        foreach ($cards as $i => $card) {
            $this->validateCardData($card, $i, $message);
        }

        if ($message) {
            $this->arResult['ERROR'] = implode('<br>', $message);
        }

        return !$this->arResult['ERROR'];
    }

    private function validateCardData(array $card, int $i, array &$message): void
    {
        $n = 'Карта №' . ($i + 1) . '. ';

        if(!$card['RECEIVER_EMAIL']){
            $message[] = $n . 'Поле EMAIL получателя обязательно для заполнения';
        }
        if (
            !$card['DESIGN']
            || !in_array($card['DESIGN'], array_column($this->arResult['DESIGN_VALUES'], 'VALUE'), true)
        ) {
            $message[] = $n . 'Необходимо выбрать дизайн карты';
        }
        if (
            !$card['NOMINAL']
            || !is_numeric($card['NOMINAL'])
            || $card['NOMINAL'] < $this->arParams['NOMINAL_MIN']
            || $card['NOMINAL'] > $this->arParams['NOMINAL_MAX']
        ) {
            $message[] = $n . "Необходимо указать номинал карты в размере от {$this->arParams['NOMINAL_MIN']} до {$this->arParams['NOMINAL_MAX']} руб.";
        }
    }

    /**
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws ArgumentTypeException
     * @throws NotImplementedException
     * @throws NotSupportedException
     * @throws ObjectException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function createOrder(): Order
    {
        $registry = Sale\Registry::getInstance(Sale\Registry::REGISTRY_TYPE_ORDER);
        /** @var Sale\Order $orderClass */
        $orderClass = $registry->getOrderClassName();

        $order = $orderClass::create(SITE_ID, $this->getUserId());

        $this->fillOrderFields($order);
        $this->fillOrderProperties($order);
        $this->fillOrderBasket($order);
        $this->fillOrderShipment($order);
        $this->fillOrderPayment($order);


        $result = $order->save();
        if (!$result->isSuccess()) {
            throw new RuntimeException(implode("\n", $result->getErrorMessages()));
        } else {
            GiftCardAction::addInfoToTable($this->session,$order->getId(),$order->getUserId());
            $this->arResult['PAYMENT_INFO'] =  $this->getPayment($order->GetId());
        }
        $_SESSION['SALE_ORDER_ID'][] = $order->getId();

        return $order;
    }

    /**
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getUserId(): string
    {
        global $USER;
        $userId = $USER->IsAuthorized() ? $USER->GetID() : $this->authorizeIfExists();

        if (!$userId) {
            $userId = $this->createUser();

        }

        return $userId;
    }

    /**
     *
     * @throws ArgumentException
     * @throws SystemException
     * @throws ObjectPropertyException
     */
    private function authorizeIfExists()
    {
        global $USER;
        $loginAndPhone = array(
            "LOGIC" => "OR",
            array("=LOGIN" => DataAlteration::clearPhone($this->session['PAYER_PHONE']), 'ACTIVE' => 'Y'),
            array("=PERSONAL_PHONE" => DataAlteration::clearPhone($this->session['PAYER_PHONE']), 'ACTIVE' => 'Y')
        );
        if ($USER->IsAuthorized() == false) {
            $arUser = UserTable::getList([
                'select' => ['ID', 'PASSWORD', 'LOGIN', 'PERSONAL_PHONE', 'EMAIL', 'NAME', 'SECOND_NAME', 'LAST_NAME'],
                'filter' => $loginAndPhone
            ])->fetch();
            if ($arUser['ID']) {
                $USER->Authorize($arUser['ID']);
                return $arUser['ID'];
            }
        }
    }

    /**
     * @return int
     */
    private function createUser()
    {
        $pass = $this->randString(12);
        $def_group = COption::GetOptionString("main", "new_user_registration_def_group", "");

        $fields = [
            'LOGIN' => DataAlteration::clearPhone($this->session['PAYER_PHONE']),
            'NAME' => $this->session['PAYER_NAME'],
            'LAST_NAME' => $this->session['PAYER_LAST_NAME'],
            'PASSWORD' => $pass,
            'CONFIRM_PASSWORD' => $pass,
            'EMAIL' => $this->session['PAYER_EMAIL'],
            'ACTIVE' => 'Y',
            'PERSONAL_PHONE' => DataAlteration::clearPhone($this->session['PAYER_PHONE']),

        ];
        if ($def_group != "") {
            $fields["GROUP_ID"] = explode(",", $def_group);
        }
        if (!empty($this->session['CONFIRM_CODE']['successCode'])) {
            $user = new CUser;
            $addResult = $user->Add($fields);

            if (intval($addResult) <= 0) {
                throw new RuntimeException($user->LAST_ERROR);
            } else {
                global $USER;
                $userId = intval($addResult);
                $USER->Authorize($addResult);
                if ($USER->IsAuthorized()) {
                    return $userId;
                }
            }
        }
    }
    /**
     * @param Order $order
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function fillOrderFields(Order $order): void
    {
        $personTypeId = null;
        foreach (PersonType::load(SITE_ID) as $personType) {
            if ($personType['CODE'] === 'PHYSICAL') {
                $personTypeId = $personType['ID'];
                break;
            }
        }
        if (!$personTypeId) {
            throw new RuntimeException('Не найден тип плательщика');
        }

        $order->setField('PERSON_TYPE_ID', $personTypeId);
    }
    /**
     * @param Order $order
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function fillOrderProperties(Order $order): void
    {
        /** @var PropertyValue $property */
        foreach ($order->getPropertyCollection() as $property) {
            switch ($property->getField('CODE')) {
                case 'FIO':
                    $property->setValue($this->session['PAYER_NAME']);
                    break;
                case 'PHONE':
                    $property->setValue($this->session['PAYER_PHONE']);
                    break;
                case 'EMAIL':
                    $property->setValue($this->session['PAYER_EMAIL']);
                    break;
                case 'GIFT_CARD':
                    $property->setValue('Y');
                    break;
                case 'GIFT_CARD_NUMBER':
                    $property->setValue($this->session['GIFT_CARD_NUMBER']);
                    break;
            }
        }
    }

    /**
     * @param Order $order
     * @return void
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ArgumentTypeException
     * @throws NotSupportedException
     * @throws ObjectNotFoundException
     */
    private function fillOrderBasket(Order $order): void
    {
        $cardProductId = CIBlockElement::GetList([], [
            'IBLOCK_CODE' => Core::IBLOCK_CODE_CATALOG_CARDS,
            'CODE' => 'card',
        ], false, false, ['ID'])->fetch()['ID'];
        if (!$cardProductId) {
            throw new RuntimeException('Не найден id товара электронной карты');
        }
        $basket = Basket::create(SITE_ID);
        $basket->setFUserId(Fuser::getIdByUserId($order->getUserId()));

        foreach ($this->session['CARDS'] as $card) {
            $basketItem = $basket->createItem('catalog', $cardProductId);
            $basketItem->setFields([
                'QUANTITY' => 1,
                'CURRENCY' => CurrencyManager::getBaseCurrency(),
                'LID' => SITE_ID,
                'NAME' => 'Подарочная карта номиналом '. $card['NOMINAL'] . 'руб.',
                'PRICE' => $card['NOMINAL'],
                'CUSTOM_PRICE' => 'Y',
                'MEASURE_CODE' => 796,
                'MEASURE_NAME' => 'шт',
                'VAT_RATE' => 0.2,
                'VAT_INCLUDED' => 'Y',

            ]);


            $this->fillBasketItemFields($basketItem, $card);
        }

        $order->appendBasket($basket);
    }

    /**
     * @param BasketItemBase $basketItem
     * @param $card
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws ArgumentTypeException
     * @throws NotImplementedException
     * @throws NotSupportedException
     * @throws ObjectNotFoundException
     */
    private function fillBasketItemFields(BasketItemBase $basketItem, $card): void
    {

        /** @var BasketPropertiesCollection $propertyCollection */
        $propertyCollection = $basketItem->getPropertyCollection();

//        $propertyItem = $propertyCollection->createItem();
//        $propertyItem->setFields([
//            'NAME' => 'Имя получателя',
//            'CODE' => 'RECEIVER_NAME',
//            'VALUE' => $card['RECEIVER_NAME'],
//        ]);
//        $propertyItem->setFields([
//            'NAME' => 'Фамилия получателя',
//            'CODE' => 'RECEIVER_LAST_NAME',
//            'VALUE' => $card['RECEIVER_NAME'],
//        ]);
//
//        $propertyItem = $propertyCollection->createItem();
//        $propertyItem->setFields([
//            'NAME' => 'Телефон получателя',
//            'CODE' => 'RECEIVER_PHONE',
//            'VALUE' => $card['RECEIVER_PHONE'],
//        ]);
//
//        if ($card['RECEIVER_EMAIL']) {
//            $propertyItem = $propertyCollection->createItem();
//            $propertyItem->setFields([
//                'NAME' => 'E-mail получателя',
//                'CODE' => 'RECEIVER_EMAIL',
//                'VALUE' => $card['RECEIVER_EMAIL'],
//            ]);
//        }
//
//        $propertyItem = $propertyCollection->createItem();
//        $propertyItem->setFields([
//            'NAME' => 'Дизайн карты',
//            'CODE' => 'DESIGN',
//            'VALUE' => $card['DESIGN'],
//        ]);

        $propertyItem = $propertyCollection->createItem();
        $propertyItem->setFields([
            'NAME' => 'Номер карты',
            'CODE' => 'GIFT_CARD_NUMBER',
            'VALUE' => $this->session['GIFT_CARD_NUMBER'],
        ]);

        $propertyItem = $propertyCollection->createItem();
        $propertyItem->setFields([
            'NAME' => 'Подарочная карта',
            'CODE' => 'GIFT_CARD',
            'VALUE' => "Y",
        ]);
    }
    private function fillOrderShipment(Order $order): void
    {
        /** @var Shipment $shipment */
        $shipment = $order->getShipmentCollection()->createItem();
        $service = DeliveryManager::getById(EmptyDeliveryService::getEmptyDeliveryServiceId());

        $shipment->setFields([
            'DELIVERY_ID' => $service['ID'],
            'DELIVERY_NAME' => $service['NAME'],
        ]);

        /** @var BasketItem $item */
        foreach ($order->getBasket()->getBasket() as $item) {
            $shipment->getShipmentItemCollection()
                ->createItem($item)
                ->setQuantity($item->getQuantity());
        }
    }

    /**
     * @param Order $order
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws NotSupportedException
     */
    private function fillOrderPayment(Order $order): void
    {
        $service = PaySystemManager::getList(['filter' => [
            'ID' => $this->session['PAYMENT'],
        ]])->fetch();

        $payment = $order->getPaymentCollection()->createItem();
        $payment->setFields([
            'PAY_SYSTEM_ID' => $service['PAY_SYSTEM_ID'],
            'PAY_SYSTEM_NAME' => $service['NAME'],
            'SUM' => $order->getPrice(),
        ]);

    }

    private function getPayment($orderID)
    {

        $registry = Sale\Registry::getInstance(Sale\Registry::REGISTRY_TYPE_ORDER);
        /** @var Order $orderClassName */
        $orderClassName = $registry->getOrderClassName();
        $orderId = $orderID;
        $order = $orderClassName::loadByAccountNumber($orderId);



        $paymentCollection = $order->getPaymentCollection();
        /** @var Payment $payment */
        foreach ($paymentCollection as $payment)
        {
            if (intval($payment->getPaymentSystemId()) > 0 && !$payment->isPaid())
            {
                $paySystemService = PaySystem\Manager::getObjectById($payment->getPaymentSystemId());
                if (!empty($paySystemService))
                {

                    $initResult = $paySystemService->initiatePay($payment, null, PaySystem\BaseServiceHandler::STRING);
                    $arPaySysAction['BUFFERED_OUTPUT'] = $initResult->getTemplate();
                    $arPaySysAction['PAYMENT_URL'] = $initResult->getPaymentUrl();
                    return $arPaySysAction;
                }
            }
        }
    }

    /**
     * @return false|string
     */
    private function getCardNumber()
    {
        $permitted_chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle($permitted_chars), 0, 13);
    }

    /**
     * Обработка ajax запроса (ввод кода)
     * @return mixed
     */
    public function checkCodeAction()
    {
        $this->isAjax = true;
        $requestData = Application::getInstance()->getContext()->getRequest()->toArray();
        if (!empty($requestData)) {
            $this->session['CONFIRM_CODE'] = $requestData;
        }
    }

}
