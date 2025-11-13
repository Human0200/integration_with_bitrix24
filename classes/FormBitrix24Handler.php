<?php

use Bitrix\Main\EventManager;

class FormBitrix24Handler
{
    const BITRIX24_WEBHOOK = '';
    const RESPONSIBLE_ID = 1;
    const SOURCE_ID = 15;

    const FIELD_MAPPING = [
        'NAME' => 'NAME',
        'CLIENT_NAME' => 'NAME',
        'FIO' => 'NAME',
        'PHONE' => 'PHONE',
        'CLIENT_PHONE' => 'PHONE',
        'EMAIL' => 'EMAIL',
        'CLIENT_EMAIL' => 'EMAIL',
        'COMPANY' => 'COMPANY_TITLE',
    ];

    // ========== ОБРАБОТЧИК ФОРМ ==========
    public static function sendToBitrix24($WEB_FORM_ID, $RESULT_ID)
    {
        try {
            if (!CModule::IncludeModule('form')) {
                return;
            }

            $formData = self::getFormData($RESULT_ID);

            if (empty($formData)) {
                return;
            }

            $leadData = self::prepareLead($formData, $WEB_FORM_ID);
            self::createBitrix24Lead($leadData);
        } catch (Exception $e) {
            self::log('Form Error: ' . $e->getMessage());
        }
    }

    private static function getFormData($resultId)
    {
        $data = [];

        CFormResult::GetDataByID(
            $resultId,
            [],
            $arForm,
            $arQuestions,
            $arAnswers
        );

        if (!empty($arQuestions)) {
            foreach ($arQuestions as $sid => $arQuestion) {
                if (is_array($arQuestion)) {
                    foreach ($arQuestion as $answerId => $arAnswer) {
                        if (!empty($arAnswer['VALUE'])) {
                            $data[$sid] = $arAnswer['VALUE'];
                            break;
                        } elseif (!empty($arAnswer['USER_TEXT'])) {
                            $data[$sid] = $arAnswer['USER_TEXT'];
                            break;
                        }
                    }
                }
            }
        }

        return $data;
    }

    private static function prepareLead($formData, $formId)
    {
        $formName = self::getFormName($formId);

        $fields = [
            'TITLE' => $formName,
            'ASSIGNED_BY_ID' => self::RESPONSIBLE_ID,
            'SOURCE_ID' => self::SOURCE_ID,
        ];

        $comments = [];
        $mappedFields = [];

        $comments[] = 'Форма: ' . $formName;
        $comments[] = 'ID формы: ' . $formId;
        $comments[] = 'Дата: ' . date('d.m.Y H:i:s');
        $comments[] = '---';

        foreach ($formData as $fieldCode => $fieldValue) {
            if (empty($fieldValue)) {
                continue;
            }

            if (isset(self::FIELD_MAPPING[$fieldCode])) {
                $bitrixField = self::FIELD_MAPPING[$fieldCode];
                $mappedFields[] = $fieldCode;

                switch ($bitrixField) {
                    case 'NAME':
                    case 'COMPANY_TITLE':
                        if (empty($fields[$bitrixField])) {
                            $fields[$bitrixField] = $fieldValue;
                        }
                        break;

                    case 'PHONE':
                        if (!isset($fields['PHONE'])) {
                            $fields['PHONE'] = [];
                        }
                        $fields['PHONE'][] = [
                            'VALUE' => $fieldValue,
                            'VALUE_TYPE' => 'WORK'
                        ];
                        break;

                    case 'EMAIL':
                        if (!isset($fields['EMAIL'])) {
                            $fields['EMAIL'] = [];
                        }
                        $fields['EMAIL'][] = [
                            'VALUE' => $fieldValue,
                            'VALUE_TYPE' => 'WORK'
                        ];
                        break;

                    default:
                        $fields[$bitrixField] = $fieldValue;
                        break;
                }
            }
        }

        foreach ($formData as $fieldCode => $fieldValue) {
            if (in_array($fieldCode, $mappedFields) || empty($fieldValue)) {
                continue;
            }

            $comments[] = $fieldCode . ': ' . $fieldValue;
        }

        if (!empty($comments)) {
            $fields['COMMENTS'] = implode("\n", $comments);
        }

        return $fields;
    }

    // ========== ОБРАБОТЧИК ЗАКАЗОВ ==========
    public static function onOrderSaved($order, $isNew, $isCanceled, $fields)
    {
        try {
            if (!$isNew || !($order instanceof \Bitrix\Sale\Order)) {
                return;
            }

            $orderId = $order->getId();
            if (!$orderId) {
                return;
            }

            self::processOrder($order);
        } catch (Exception $e) {
            self::log('Order Error: ' . $e->getMessage());
        }
    }

    private static function processOrder(\Bitrix\Sale\Order $order)
    {
        $properties = [];
        $propertyCollection = $order->getPropertyCollection();
        foreach ($propertyCollection as $property) {
            $code = $property->getField('CODE');
            $value = $property->getValue();
            if (!empty($code) && !empty($value)) {
                $properties[$code] = $value;
            }
        }

        $products = [];
        $basket = $order->getBasket();
        foreach ($basket as $item) {
            $productName = $item->getField('NAME');
            $quantity = $item->getQuantity();
            $itemPrice = $item->getPrice();

            if (!empty($productName)) {
                $products[] = "{$productName} (x{$quantity}) - {$itemPrice} RUB";
            }
        }

        $fields = self::prepareOrderData($order, $properties, $products);
        self::createBitrix24Lead($fields);
    }

private static function prepareOrderData($order, $properties, $products)
{
    $accountNumber = $order->getField('ACCOUNT_NUMBER');
    $price = $order->getPrice();
    $dateInsert = $order->getField('DATE_INSERT') ? $order->getField('DATE_INSERT')->format('d.m.Y H:i:s') : date('d.m.Y H:i:s');
    
    // Получаем комментарий покупателя к заказу
    $userComment = $order->getField('USER_DESCRIPTION');
    
    $fields = [
        'TITLE' => "Заказ #{$accountNumber}",
        'ASSIGNED_BY_ID' => self::RESPONSIBLE_ID,
        'SOURCE_ID' => self::SOURCE_ID,
        'OPPORTUNITY' => (float)$price,
        'CURRENCY_ID' => 'RUB',
    ];
    
    $comments = [];
    $mappedFields = [];
    
    $comments[] = "Заказ с интернет-магазина";
    $comments[] = "Номер заказа: {$accountNumber}";
    $comments[] = "Сумма: {$price} RUB";
    $comments[] = "Дата: {$dateInsert}";
    
    foreach ($properties as $propCode => $propValue) {
        if (empty($propValue)) {
            continue;
        }
        
        if (isset(self::FIELD_MAPPING[$propCode])) {
            $bitrixField = self::FIELD_MAPPING[$propCode];
            $mappedFields[] = $propCode;
            
            switch ($bitrixField) {
                case 'NAME':
                case 'COMPANY_TITLE':
                    if (empty($fields[$bitrixField])) {
                        $fields[$bitrixField] = $propValue;
                        if ($bitrixField == 'NAME') {
                            $fields['TITLE'] = "Заказ от {$propValue}";
                        }
                    }
                    break;
                    
                case 'PHONE':
                    if (!isset($fields['PHONE'])) {
                        $fields['PHONE'] = [];
                    }
                    $fields['PHONE'][] = [
                        'VALUE' => $propValue,
                        'VALUE_TYPE' => 'WORK'
                    ];
                    break;
                    
                case 'EMAIL':
                    if (!isset($fields['EMAIL'])) {
                        $fields['EMAIL'] = [];
                    }
                    $fields['EMAIL'][] = [
                        'VALUE' => $propValue,
                        'VALUE_TYPE' => 'WORK'
                    ];
                    break;
                    
                default:
                    $fields[$bitrixField] = $propValue;
                    break;
            }
        }
    }
    
    $comments[] = "---";
    
    
    if (!empty($userComment)) {
        $comments[] = "Комментарий покупателя:";
        $comments[] = $userComment;
        $comments[] = "---";
    }
    
    if (!empty($products)) {
        $comments[] = "ТОВАРЫ В ЗАКАЗЕ:";
        foreach ($products as $product) {
            $comments[] = $product;
        }
        $comments[] = "---";
    }
    
    foreach ($properties as $propCode => $propValue) {
        if (in_array($propCode, $mappedFields) || empty($propValue)) {
            continue;
        }
        $comments[] = "{$propCode}: {$propValue}";
    }
    
    $fields['COMMENTS'] = implode("\n", $comments);
    
    return $fields;
}

    // ========== ОБЩИЕ МЕТОДЫ ==========
    public static function createBitrix24Lead($fields)
    {
        try {
            $url = self::BITRIX24_WEBHOOK . 'crm.lead.add.json';

            $postData = [
                'fields' => $fields,
                'params' => ['REGISTER_SONET_EVENT' => 'Y']
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && $response) {
                $result = json_decode($response, true);

                if (isset($result['result'])) {
                    return $result['result'];
                }
            }

            return false;
        } catch (Exception $e) {
            self::log('Error: ' . $e->getMessage());
            return false;
        }
    }

    private static function getFormName($formId)
    {
        if (!CModule::IncludeModule('form')) {
            return 'Форма #' . $formId;
        }

        $rsForm = CForm::GetByID($formId);
        if ($arForm = $rsForm->Fetch()) {
            return $arForm['NAME'] ?: 'Форма #' . $formId;
        }

        return 'Форма #' . $formId;
    }

    private static function log($message)
    {
        try {
            $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/bitrix24.log';
            $logDir = dirname($logFile);

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            file_put_contents(
                $logFile,
                date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Exception $e) {
            // ignore
        }
    }
}
