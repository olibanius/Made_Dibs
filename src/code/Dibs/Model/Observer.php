<?php
/**
 * @author jonathan@madepeople.se
 */
class Made_Dibs_Model_Observer
{
    /**
     * We need to call the authorize function separately in order to maintain
     * correct transaction hierarchy when using the authorize+capture action,
     * and the only way to do that seems to be from within the
     * "sales_order_payment_capture" event
     *
     * @param Varien_Event_Observer $observer
     */
    public function authorizeBeforeCapture(Varien_Event_Observer $observer)
    {
        $payment = $observer->getEvent()->getPayment();
        $method = $payment->getMethodInstance();
        if (!($method instanceof Made_Dibs_Model_Payment_Api)) {
            return;
        }

        if ($payment->hasData('last_trans_id')
                || $method->getConfigPaymentAction() !== Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE) {
            // If we have last_trans_id it isn't a fresh transaction and we
            // might actually be capturing a previous authorization
            return;
        }

        $invoice = $observer->getEvent()->getInvoice();

        // @see Mage_Sales_Model_Order_Payment line 379
        $amount = Mage::app()->getStore()->roundPrice($invoice->getBaseGrandTotal());
        $payment->authorize(true, $amount);

        // Our capture method requires a parent transaction, and last_trans_id
        // might actually be something else in other cases, but here we choose
        // that the parent one for capture is the last one, from authorization
        $payment->setAuthorizeTransactionId($payment->getLastTransId());
    }

    /**
     * This method cleans up old pending_gateway orders as they are probably
     * left over from customers who closed their browsers, lost internet
     * connectivity, etc.
     *
     * @param Varien_Object $observer
     */
    public function cancelOldPendingGatewayOrders($observer)
    {
        $hoursUntilCancelled = 1;
        $date = date('Y-m-d H:i:s', strtotime("-$hoursUntilCancelled hour"));
        $orderCollection = Mage::getModel('sales/order')
                ->getCollection()
                ->addFieldToFilter('status', 'pending_payment')
                ->addAttributeToFilter('created_at', array('lt' => $date));

        foreach ($orderCollection as $order) {
            if (!$order->canCancel()) {
                continue;
            }

            $method = $order->getPayment()
                    ->getMethod();

            if (!strstr($method, 'made_dibs')) {
                continue;
            }

            $order->cancel();
            $order->addStatusHistoryComment("The order was automatically cancelled due to more than $hoursUntilCancelled hours of gateway inactivity.");
            $order->save();
        }
    }
}
