<?php

namespace Marcusp\OrderImport\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Quote\Model\QuoteFactory $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
     
        \Magento\CatalogInventory\Model\Stock\StockItemRepository $stockItemRepository
        

    ) {
        $this->storeManager = $storeManager;
        $this->customerFactory = $customerFactory;
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->orderSender = $orderSender;
        $this->resourceConnection = $resourceConnection;
        $this->stockItemRepository = $stockItemRepository;
        parent::__construct($context);
    }
    /*
    * create order programmatically
    */
    public function createOrder($orderInfo) {
      
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/custom.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('start log');
        $i=0;
        foreach($orderInfo as $key=>$order):
         
                   
                    $store = $this->storeManager->getStore();
                    $storeId = $store->getStoreId();
                    $websiteId = $this->storeManager->getStore()->getWebsiteId();
                
                    $customer = $this->customerFactory->create();
                    $customer->setWebsiteId($websiteId);
                        $email = $order['email'];
                        if (!filter_var( $email, FILTER_VALIDATE_EMAIL)) {
                            $logger->info("invalid email $email");
                          
                          } else {


                                    $customer->loadByEmail($email);// load customet by email address
                                    if(!$customer->getId()){

                                

                                        $logger->info("customer created:   $email");
                                    //For guest customer create new cusotmer
                                        $groupID = $this->mapCustomerGroup($order['customer_group']);
                                        $customer->setWebsiteId($websiteId)
                                                ->setStore($store)
                                                ->setFirstname($order['firstname'])
                                                ->setLastname($order['lastname'])
                                                ->setEmail($email)
                                                ->setPassword('flags2022!@a!')
                                                ->setGroupId($groupID);
                                        $customer->save();
                                    
                                    }
                                    $quote=$this->quote->create(); //Create object of quote
                                    $quote->setStore($store); //set store for our quote
                                    /* for registered customer */
                                    $customer= $this->customerRepository->getById($customer->getId());
                                    $quote->setCurrency();
                            
                                    $quote->assignCustomer($customer); //Assign quote to customer
                                    $opencartOID =  $order['items'][0]['order_id'];
                                
                                    //add items in quote
                                    foreach($order['items'] as $item){
                                        try {
                                            if (isset($item['sku'])) {
                                                $product = $this->productRepository->get($item['sku']);
                                                $logger->info("sku  added: sku: $item[sku]"); 
                                                $quote->addProduct($product,intval($item['qty'])); 
                                        
                                            } else {
                                                $logger->info("sku failed: sku is blank $item[sku]");  
                                            }
                                             
                                        
                                        
                                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e){
                                            //continue;
                                            $logger->info("sku failed: sku not found $item[sku]");
                                        }
                                        
                                    }


                                    try {
                                        
                                            $shippingAddress=$quote->getShippingAddress();
                                            $shippingAddress->setCollectShippingRates(true)
                                                            ->collectShippingRates()
                                                            ->setShippingMethod('flatrate_flatrate'); //shipping method, please verify flat rate shipping must be enable
                                        
                                        
                                        
                                            $shippingAddress = $quote->getShippingAddress();
                                            $shippingAddress->setCollectShippingRates(true)
                                                                            ->collectShippingRates()
                                                                            ->setShippingMethod('freeshipping_freeshipping'); //shipping method
                                        
                                        
                                        
                                            $quote->setPaymentMethod('banktransfer'); //payment method, please verify checkmo must be enable from admin
                                            $quote->setInventoryProcessed(false); //decrease item stock equal to qty
                                            $quote->save(); //quote save 
                                            // Set Sales Order Payment, We have taken check/money order
                                            $quote->getPayment()->importData(['method' => 'banktransfer']);
                                    
                                            // Collect Quote Totals & Save
                                            $quote->collectTotals()->save();
                                    
                                            $order_quote = $this->quoteManagement->submit($quote);
                                        
                                            if ($order_quote) {
                                            
                                                $order_quote->setCreatedAt($order['date_added']);
                                                $order_quote->save();
                                                
                                                // Create Order From Quotee
                                            
                                                    /* for send order email to customer email id */
                                                //  $this->orderSender->send($order);
                                                    /* get order real id from order */
                                                    $orderId = $order_quote->getIncrementId();
                                                    $result['success']= $orderId;
                                                   echo 'ORDER ADDED';
                                                    $logger->info("order added: cart: $opencartOID  m2:   $email $orderId count: $i");
                                                } else {
                                                    echo 'ORDER FAILED';
                                                   $logger->info("order failed: cart: $opencartOID  email:   $email count: $i");
                                            }
                                            
                                                    
                                    
                                    }   catch (\Exception $e) {
                                        echo $e->getMessage();
                                    }
                                
                    }
         $i++;      
        endforeach;
        //return $result;
  
    }


   //gets historic data from opencart tables//
   public function getOrderData() {
    $connection = $this->resourceConnection->getConnection();
    $sql = "select o.date_added, o.order_id, c.firstname, c.lastname, c.customer_group_id, c.email, op.sku, op.quantity from oc_order o left join oc_customer c on c.customer_id = o.customer_id 
    left join oc_order_product op on o.order_id = op.order_id left join oc_product ocp on op.model = ocp.model 
    where o.date_added >= '2018-01-01' and  o.date_added < '2019-01-01' 
    order by o.order_id asc";

   
    $result = $connection->fetchAll($sql);

    //print_r($result );
    $prev ='';
    $i=0;
    $groupArrayByOrderId = $this->group_by("order_id", $result);
   //print_r($groupArrayByOrderId);
    //exit();

    foreach($groupArrayByOrderId as $key=>$value):
         
   
        $orderInfo[$key] = [
            'currency_id'  => 'GBP',
            'email'        => $value[0]['email'], //customer email id
            'firstname'    => $value[0]['firstname'],
            'lastname'   => $value[0]['lastname'],
            'customer_group' => $value[0]['customer_group_id'],
            'date_added' => $value[0]['date_added'],
               
        ];

        foreach($value as $item):
                //echo $item['sku'];
                $orderInfo[$key]['items'][] = [
                  // 'sku' => 'test flag-2',
                    'sku' => $item['sku'],
                    'qty' => $item['quantity'],
                    'order_id' => $item['order_id']
                ];
        endforeach;
    endforeach;

    return $orderInfo;

}


   public function group_by($key, $data) {
        $result = array();
    
        foreach($data as $val) {
            if(array_key_exists($key, $val)){
                $result[$val[$key]][] = $val;
            }else{
                $result[""][] = $val;
            }
        }
    
        return $result;
    }


    public function getStockItem($productId)
    {
      return $this->stockItemRepository->get($productId);
    }

    protected function mapCustomerGroup($id) {  

        switch($id)   
        {   
    
           /*OC CATEGORIES*/
            case 132:  
            case 150:    
            case 155:    
            case 116:   
                return 2;
            break;  
         
           
          

            case 131: 
            case 152:
            return 3;
            break;  
    
         
            case 114:  
            return 4;  
            break;  
               
         
            case 136: 
            return 5;  
            break; 
            
      
            case 133:
            return 6;  
            break;
            
           
            case 154:
            case 147:
            case 156:
            case 151:
              return 9;  
            break; 
            
        
            case 146:
            return 10;   
            break; 
    
            default:
                return 1;
            break;
            
    
            }
      }
       
}