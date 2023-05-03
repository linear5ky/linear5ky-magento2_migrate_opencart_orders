<?php
require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
/** @var \Magento\Framework\App\Http $app */
$app = $bootstrap->createApplication(\Magento\Framework\App\Http::class);



$objectManager = $bootstrap->getObjectManager();

$state = $objectManager->get(Magento\Framework\App\State::class);
$state->setAreaCode('frontend');

$getHelper = $objectManager->get('\Marcusp\OrderImport\Helper\Data');
$data = $getHelper->getOrderData();


$getHelper->createOrder($data);
?>