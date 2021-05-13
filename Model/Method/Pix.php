<?php

namespace Paghiper\Magento2\Model\Method;

/**
 * Class Payment Billet
 *
 * @see       https://www.paghiper.com.br Official Website
 * @author    Tezus (and others) <suporte@tezus.com.br>
 * @copyright https://www.paghiper.com.br 
 * @license   https://www.gnu.org/licenses/gpl-3.0.pt-br.html GNU GPL, version 3
 * @package   Paghiper\Magento2\Model
 */
class Pix extends \Magento\Payment\Model\Method\AbstractMethod {
  /**
   * @var string
   */

  const CODE = 'paghiper_pix';

  protected $_code = self::CODE;

  /**
   * PagHiper Helper
   *
   * @var Paghiper\Magento2\Helper\Data;
   */
  protected $helperData;

  public function __construct(
    \Magento\Framework\Model\Context $context,
    \Magento\Framework\Registry $registry,
    \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
    \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
    \Magento\Payment\Helper\Data $paymentData,
    \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
    \Magento\Payment\Model\Method\Logger $logger,
    \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
    \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
    \Paghiper\Magento2\Helper\Data $helper,
    \Magento\Store\Model\StoreManagerInterface $storeManager,
    array $data = []
  ) {
    parent::__construct(
      $context,
      $registry,
      $extensionFactory,
      $customAttributeFactory,
      $paymentData,
      $scopeConfig,
      $logger,
      $resource,
      $resourceCollection,
      $data
    );
    $this->helperData = $helper;
    $this->_storeManager = $storeManager;
  }

  /**
   * Determine method availability based on quote amount and config data
   *
   * @param \Magento\Quote\Api\Data\CartInterface|null $quote
   * @return bool
   */
  public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null) {
    if (!$this->helperData->getStatusPix()) {
      return false;
    }
    return true;
  }

  public function order(\Magento\Payment\Model\InfoInterface $payment, $amount) {
    try {
      //Pegando informações adicionais do pagamento (CPF)
      $info = $this->getInfoInstance();
      $paymentInfo = $info->getAdditionalInformation();

      //Helper 
      $url = $this->helperData->getUrl();
      $days = $this->helperData->getDays();

      //pegando dados do pedido do clioente
      $order = $payment->getOrder();
      $billingaddress = $order->getBillingAddress();

      $dataUser['apiKey'] = $this->helperData->getAcessToken();
      $dataUser['order_id'] = $order->getIncrementId();
      $dataUser['payer_email'] = $billingaddress->getEmail();
      $dataUser['payer_name'] = $billingaddress->getFirstName() . ' ' . $billingaddress->getLastName();
      $dataUser['payer_cpf_cnpj'] = $paymentInfo['cpfCnpjCustomer'];
      $dataUser['payer_phone'] = $billingaddress->getTelephone();

      $dataUser['days_due_date'] = $days;

      $discount = str_replace("-", "", $order->getDiscountAmount()) * 100;
      if ($discount > 0) {
        $dataUser['discount_cents'] = $discount;
      }

      $dataUser['shipping_price_cents'] = $order->getShippingAmount() * 100;
      $dataUser['shipping_methods'] = $order->getShippingDescription();
      $dataUser['fixed_description'] = true;
      $dataUser['notification_url'] = $this->_storeManager->getStore()->getBaseUrl() . 'paghiper/notification/updatestatus';

      $items = $order->getAllItems();
      $i = 0;
      /** @var \Magento\Catalog\Model\Product */
      foreach ($items as $key => $item) {
        if ($item->getProductType() != 'configurable') {
          if ($item->getPrice() == 0) {
            $parentItem = $item->getParentItem();
            $price = $parentItem->getPrice();
          } else {
            $price = $item->getPrice();
          }
          $dataUser['items'][$i]['description'] = $item->getName();
          $dataUser['items'][$i]['quantity'] = $item->getQtyOrdered();
          $dataUser['items'][$i]['item_id'] = $item->getProductId();
          $dataUser['items'][$i]['price_cents'] = $price * 100;
          $i++;
        }
      }

      if ($order->getTaxAmount() > 0) {
        $dataUser['items'][$i]['description'] = "Imposto";
        $dataUser['items'][$i]['quantity'] = '1';
        $dataUser['items'][$i]['item_id'] = 'taxes';
        $dataUser['items'][$i]['price_cents'] = $order->getTaxAmount() * 100;
      }
      
      $dataUser['partners_id'] = 'POP52A2Z';

      $response = (array)$this->doPayment($dataUser);

      if ($response['pix_create_request']->result == 'reject') {
        throw new \Exception($response['pix_create_request']->response_message, 1);
      }

      $pixcode = $response['pix_create_request']->pix_code->qrcode_image_url;
      $emv = $response['pix_create_request']->pix_code->emv;
      $transactionToken = $response['pix_create_request']->transaction_id;

      $order->setPaghiperTransaction($transactionToken);
      $order->setPaghiperPix($pixcode);
      $order->setPaghiperChavepix($emv);
      $payment->setSkipOrderProcessing(true);
    } catch (\Exception $e) {
      throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
    }
    return $this;
  }

  public function doPayment($data) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://pix.paghiper.com/invoice/create/',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => array(
        "Content-Type: application/json"
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response);
  }

  public function assignData(\Magento\Framework\DataObject $data) {
    $info = $this->getInfoInstance();
    $info->setAdditionalInformation('cpfCnpjCustomer', $data['additional_data']['cpfCnpj'] ?? null);
    return $this;
  }
}
