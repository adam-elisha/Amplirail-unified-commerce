<label for="response">Your response</label>
<textarea id="response" name="response" rows="6" required minlength="20" maxlength="10000" placeholder="Explain why the charge is valid: what was sold, when it was delivered, and any contact with the customer.">{{ old('response') }}</textarea>
<p class="muted small">Describe each piece of evidence you have. Do not type full card numbers.</p>
@foreach ([
  'receipt' => 'Receipt or invoice',
  'delivery_proof' => 'Proof of delivery or service',
  'customer_communication' => 'Messages with the customer',
  'refund_policy' => 'Refund or cancellation policy shown',
  'usage' => 'Proof the customer used the product or service',
  'other' => 'Other',
] as $k => $label)
  <label for="ev_{{ $k }}">{{ $label }}</label>
  <input id="ev_{{ $k }}" name="evidence[{{ $k }}]" type="text" maxlength="500" value="{{ old('evidence.'.$k) }}">
@endforeach
