<?
require_once($_SERVER['DOCUMENT_ROOT'] . '/local/classes/FormBitrix24Handler.php');

use Bitrix\Main\EventManager;

$eventManager = EventManager::getInstance();

// Обработчик для веб-форм - отправка в Bitrix24
$eventManager->addEventHandler(
    'form',
    'onAfterResultAdd',
    ['FormBitrix24Handler', 'sendToBitrix24']
);

// Обработчик для заказов - отправка в Bitrix24
AddEventHandler('sale', 'OnSaleOrderSaved', ['FormBitrix24Handler', 'onOrderSaved']);
