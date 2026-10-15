@php
  $cls = match ($state) {
    'active', 'approved', 'captured', 'delivered', 'passed', 'issued', 'succeeded', 'paid', 'enabled', 'published' => 'green',
    'test_mode', 'validation', 'boarding', 'authorized', 'pending', 'pending_approval', 'draft', 'open', 'submitted', 'kyb_pending', 'underwriting', 'partially_refunded' => 'grey',
    'needs_info', 'manual_review', 'failed', 'unknown', 'refunded', 'voided', 'suspended' => 'amber',
    'declined', 'blocked', 'closed', 'abandoned', 'rejected', 'disabled' => 'red',
    default => '',
  };
@endphp
<span class="badge {{ $cls }}">{{ str_replace('_', ' ', $state) }}</span>
