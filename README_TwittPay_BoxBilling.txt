===========================================================================
 TWITTPAY - BoxBilling payment gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your BoxBilling root - the folder that has bb-library/
   and index.php in it. One file lands in place:

     bb-library/Payment/Adapter/TwittPay.php

   Keep the file name exactly as it is. BoxBilling finds an adapter by matching
   the file name to the class name, and Linux servers are case sensitive.

 INSTALL
   1. Admin area -> Configuration -> Payment Gateways.
   2. Find "TwittPay" in the list of new gateways and click it to install.
   3. Fill in:

        Endpoint URL      your own gateway address, e.g.
                          https://checkout.twittpay.com
                          (the API host shown on your gateway's developer page)

        Brand Key           from your gateway dashboard, under Brands

        USD to BDT Rate   only used when the invoice is not already in BDT

   4. Tick "Enabled", save, and pay a test invoice.

 HOW IT WORKS
   * The invoice page shows a Pay Now button. The payment is created the moment
     that page is rendered, so the button just carries the customer over.
   * The gateway calls BoxBilling's IPN URL from its own server. That is where
     the invoice is actually settled.
   * The IPN handler verifies the transaction against the API first. A hand-typed
     status does nothing at all.
   * COMPLETED credits the client and pays the invoice.
   * PENDING pays nothing yet - the customer has sent the money and your merchant
     has not approved it. The gateway calls again with the answer, and that call
     settles the invoice. Do not ask the customer to pay twice.
   * A transaction id that has already been recorded as complete is refused, so
     a webhook that arrives twice cannot pay the same invoice twice.

 CURRENCY
   The gateway charges BDT.

   * A BDT invoice is sent as it is.
   * Any other currency is multiplied by the USD to BDT Rate, and the invoice's
     own amount and currency ride along in metadata - so the transaction and the
     client credit BoxBilling records stay in the invoice's currency.

 WHAT TO WATCH
   * The Endpoint URL is your API host. Pasting the whole endpoint or a trailing
     /api is fine - only the scheme and host are used.
   * Tick "Send the customer straight to the payment page" if you do not want the
     extra button click.
   * Refunds are not done through the API. Refund on the gateway side, then
     record it in BoxBilling by hand.
   * Subscriptions are not supported - one-off invoice payments only.

 TWO CHANGES FROM THE ORIGINAL
   * The PipraPay version wrote every IPN, including the full verification
     response, into piprapay_ipn_log.txt in the web root - a file anybody could
     download. This port does not write that file. BoxBilling's own gateway log
     already keeps the record.
   * The original refused a duplicate on any transaction row with that id. Here
     the check only counts rows already marked complete, so the second webhook -
     the one that turns a pending payment into a paid one - is still allowed
     through.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
