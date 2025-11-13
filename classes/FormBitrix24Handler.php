<?php

use Bitrix\Main\EventManager;

class FormBitrix24Handler
{
    const BITRIX24_WEBHOOK = '';
    const RESPONSIBLE_ID = 1;
    const SOURCE_ID = 15; // Веб-сайт из справочника CRM
    
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
                        // Обработка файлов
                        if (!empty($arAnswer['USER_FILE_ID'])) {
                            $data[$sid] = $arAnswer['USER_FILE_ID'];
                            break;
                        }
                        elseif (!empty($arAnswer['ANSWER_FILE_ID'])) {
                            $data[$sid] = $arAnswer['ANSWER_FILE_ID'];
                            break;
                        }
                        elseif (!empty($arAnswer['USER_FILE']) && is_array($arAnswer['USER_FILE'])) {
                            $data[$sid] = $arAnswer['USER_FILE']['ID'];
                            break;
                        }
                        elseif (!empty($arAnswer['VALUE'])) {
                            if (is_numeric($arAnswer['VALUE'])) {
                                $fileCheck = CFile::GetFileArray($arAnswer['VALUE']);
                                if ($fileCheck && !empty($fileCheck['SRC'])) {
                                    $data[$sid] = $arAnswer['VALUE'];
                                    break;
                                }
                            }
                            $data[$sid] = $arAnswer['VALUE'];
                            break;
                        }
                        elseif (!empty($arAnswer['USER_TEXT'])) {
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
        $fields = [
            'TITLE' => 'Заявка с сайта',
            'ASSIGNED_BY_ID' => self::RESPONSIBLE_ID,
            'SOURCE_ID' => self::SOURCE_ID,
        ];
        
        $comments = [];
        
        // Получаем название формы
        $formName = self::getFormName($formId);
        
        $comments[] = '📋 Форма: ' . $formName;
        $comments[] = 'ID формы: ' . $formId;
        $comments[] = 'Дата: ' . date('d.m.Y H:i:s');
        $comments[] = '---';
        
        // Имя
        if (!empty($formData['NAME'])) {
            $fields['NAME'] = $formData['NAME'];
            $fields['TITLE'] = 'Заявка от ' . $formData['NAME'];
        }
        
        // Телефон
        if (!empty($formData['PHONE'])) {
            $fields['PHONE'] = [
                [
                    'VALUE' => $formData['PHONE'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ];
        }
        
        // Email
        if (!empty($formData['EMAIL'])) {
            $fields['EMAIL'] = [
                [
                    'VALUE' => $formData['EMAIL'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ];
        }
        
        // Все остальные поля в комментарий
        foreach ($formData as $fieldCode => $fieldValue) {
            if (in_array($fieldCode, ['NAME', 'PHONE', 'EMAIL', 'FILE']) || empty($fieldValue)) {
                continue;
            }
            
            $comments[] = $fieldCode . ': ' . $fieldValue;
        }
        
        // Обработка файлов
        if (!empty($formData['FILE'])) {
            $fileData = self::getFileContent($formData['FILE']);
            if ($fileData) {
                // Поле для загрузки файла в Bitrix24 (нужно узнать ID поля)
                // $fields['UF_CRM_XXXXXXXXX'] = [
                //     [
                //         'fileData' => [
                //             $fileData['name'],
                //             base64_encode($fileData['content'])
                //         ]
                //     ]
                // ];
                $comments[] = "Файл прикреплен: " . $fileData['name'];
            }
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
            self::log('=== START ORDER PROCESSING ===');
            
            // Проверяем, что это новый заказ
            if (!$isNew) {
                self::log('Order is not new, skipping');
                return;
            }
            
            // Проверяем, что передан объект заказа
            if (!($order instanceof \Bitrix\Sale\Order)) {
                self::log('Invalid order object');
                return;
            }
            
            $orderId = $order->getId();
            if (!$orderId) {
                self::log('Order ID is empty');
                return;
            }
            
            self::log('Processing new order ID: ' . $orderId);
            
            // Обрабатываем заказ
            self::processOrder($order);
            
            self::log('=== ORDER PROCESSED SUCCESSFULLY ===');
            
        } catch (Exception $e) {
            self::log('ERROR in onOrderSaved: ' . $e->getMessage());
        }
    }
    
    private static function processOrder(\Bitrix\Sale\Order $order)
    {
        $orderId = $order->getId();
        $accountNumber = $order->getField('ACCOUNT_NUMBER');
        $price = $order->getPrice();
        
        self::log("Order #{$accountNumber}, Amount: {$price} RUB");
        
        // Получаем свойства заказа
        $properties = [];
        $propertyCollection = $order->getPropertyCollection();
        foreach ($propertyCollection as $property) {
            $code = $property->getField('CODE');
            $value = $property->getValue();
            if (!empty($code) && !empty($value)) {
                $properties[$code] = $value;
                self::log("Property: {$code} = {$value}");
            }
        }
        
        // Получаем товары
        $products = [];
        $basket = $order->getBasket();
        foreach ($basket as $item) {
            $productName = $item->getField('NAME');
            $quantity = $item->getQuantity();
            $price = $item->getPrice();
            
            if (!empty($productName)) {
                $productInfo = "{$productName} (x{$quantity}) - {$price} RUB";
                $products[] = $productInfo;
                self::log("Product: {$productInfo}");
            }
        }
        
        // Подготавливаем данные для Bitrix24
        $leadData = self::prepareOrderData($order, $properties, $products);
        
        // Создаем лид в Bitrix24
        $leadId = self::createBitrix24Lead($leadData);
        
        if ($leadId) {
            self::log("SUCCESS: Lead created in Bitrix24 with ID: {$leadId}");
        } else {
            self::log("ERROR: Failed to create lead in Bitrix24");
        }
        
        return $leadId;
    }
    
    private static function prepareOrderData($order, $properties, $products)
    {
        $orderId = $order->getId();
        $accountNumber = $order->getField('ACCOUNT_NUMBER');
        $price = $order->getPrice();
        $dateInsert = $order->getField('DATE_INSERT') ? $order->getField('DATE_INSERT')->format('d.m.Y H:i:s') : date('d.m.Y H:i:s');
        
        // Формируем название лида
        $leadTitle = "Заказ #{$accountNumber}";
        if (!empty($properties['NAME'])) {
            $leadTitle = "Заказ от {$properties['NAME']}";
        } elseif (!empty($properties['FIO'])) {
            $leadTitle = "Заказ от {$properties['FIO']}";
        }
        
        // Основные данные лида
        $fields = [
            'TITLE' => $leadTitle,
            'ASSIGNED_BY_ID' => self::RESPONSIBLE_ID,
            'SOURCE_ID' => self::SOURCE_ID,
            'OPPORTUNITY' => (float)$price,
            'CURRENCY_ID' => 'RUB',
        ];
        
        // Имя контакта
        if (!empty($properties['NAME'])) {
            $fields['NAME'] = $properties['NAME'];
        } elseif (!empty($properties['FIO'])) {
            $fields['NAME'] = $properties['FIO'];
        }
        
        // Телефон
        if (!empty($properties['PHONE'])) {
            $fields['PHONE'] = [
                [
                    'VALUE' => $properties['PHONE'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ];
        }
        
        // Email
        if (!empty($properties['EMAIL'])) {
            $fields['EMAIL'] = [
                [
                    'VALUE' => $properties['EMAIL'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ];
        }
        
        // Формируем комментарий
        $comments = [];
        $comments[] = "🛒 Заказ с интернет-магазина";
        $comments[] = "Номер заказа: {$accountNumber}";
        $comments[] = "Сумма: {$price} RUB";
        $comments[] = "Дата: {$dateInsert}";
        $comments[] = "---";
        
        // Товары в корзине
        if (!empty($products)) {
            $comments[] = "ТОВАРЫ:";
            foreach ($products as $product) {
                $comments[] = "- {$product}";
            }
            $comments[] = "---";
        }
        
        // Дополнительные свойства заказа
        foreach ($properties as $code => $value) {
            if (in_array($code, ['NAME', 'FIO', 'PHONE', 'EMAIL']) || empty($value)) {
                continue;
            }
            $comments[] = "{$code}: {$value}";
        }
        
        $fields['COMMENTS'] = implode("\n", $comments);
        
        return $fields;
    }
    
    // ========== ОБЩИЕ МЕТОДЫ ДЛЯ BITRIX24 ==========
    public static function createBitrix24Lead($fields)
    {
        try {
            $url = self::BITRIX24_WEBHOOK . 'crm.lead.add.json';
            
            $postData = http_build_query([
                'fields' => $fields,
                'params' => ['REGISTER_SONET_EVENT' => 'Y']
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_error($ch)) {
                self::log('CURL Error: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300 && $response) {
                $result = json_decode($response, true);
                
                if (isset($result['result'])) {
                    self::log('Lead created successfully. ID: ' . $result['result']);
                    return $result['result'];
                }
            }
            
            self::log("API Error. HTTP: {$httpCode}, Response: {$response}");
            return false;
            
        } catch (Exception $e) {
            self::log('Error creating lead: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получить название формы по ID
     * @param int $formId
     * @return string
     */
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
    
    /**
     * Получить содержимое файла для загрузки в Bitrix24
     * @param int $fileId
     * @return array|false
     */
    private static function getFileContent($fileId)
    {
        try {
            $arFile = CFile::GetFileArray($fileId);
            
            if (!$arFile) {
                return false;
            }
            
            $filePath = $_SERVER['DOCUMENT_ROOT'] . $arFile['SRC'];
            
            if (!file_exists($filePath)) {
                return false;
            }
            
            $content = file_get_contents($filePath);
            
            if ($content === false) {
                return false;
            }
            
            return [
                'name' => $arFile['ORIGINAL_NAME'],
                'content' => $content
            ];
            
        } catch (Exception $e) {
            return false;
        }
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