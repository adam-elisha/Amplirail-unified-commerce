// Live pay page glue for processor-hosted fields (NMI Collect.js inline mode).
// TODO before go-live (AR-016 to AR-019): confirm every option and callback field against docs/providers/nmi/.
// The card number, expiry and CVV are typed into iframes owned by the processor; only the returned token is posted.
(function () {
  var form = document.getElementById('pay-form');
  if (!form || !window.CollectJS) { return; }
  window.CollectJS.configure({
    variant: 'inline',
    fields: { ccnumber: { selector: '#ccnumber' }, ccexp: { selector: '#ccexp' }, cvv: { selector: '#cvv' } },
    callback: function (response) {
      document.getElementById('payment_token').value = response.token;
      if (response.card) {
        document.getElementById('card_brand').value = (response.card.type || 'other').toLowerCase();
        document.getElementById('card_last4').value = String(response.card.number || '').slice(-4);
      }
      form.submit();
    }
  });
  form.addEventListener('submit', function (e) {
    if (!document.getElementById('payment_token').value) { e.preventDefault(); window.CollectJS.startPaymentRequest(); }
  });
})();
