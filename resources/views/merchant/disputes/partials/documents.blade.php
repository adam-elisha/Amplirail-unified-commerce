{{-- Evidence files for a dispute. Expects $d (dispute) and $portal ('admin' or 'merchant'). --}}
@php
  $docs = \Modules\Disputes\Infrastructure\DisputeDocument::query()->where('dispute_id', $d->id)->orderBy('created_at')->get();
  $checklist = app(\Modules\Onboarding\Application\DocumentChecklist::class);
@endphp
<section class="card">
  <h2>Evidence files</h2>
  @if ($docs->isEmpty())
    <p class="muted small">No files yet.</p>
  @else
    <ul>
      @foreach ($docs as $f)
        @php $doc = \Modules\Onboarding\Infrastructure\MerchantDocument::query()->whereKey($f->document_id)->first(); @endphp
        <li>{{ $f->label }} @if ($doc)· <a href="{{ $checklist->downloadUrl($doc, $portal) }}">{{ $doc->original_name }}</a> <span class="muted small">({{ number_format($doc->size_bytes / 1024) }} KB)</span>@endif <span class="muted small">· {{ $f->created_at->format('M j, Y') }}</span></li>
      @endforeach
    </ul>
  @endif
  @if ($d->isOpen() && in_array('payments.refund', $permissions ?? [], true))
    <form method="post" action="{{ route($portal === 'admin' ? 'admin.disputes.evidence' : 'merchant.disputes.evidence', $d->id) }}" enctype="multipart/form-data" class="inline-form">@csrf
      <div><label for="ev_label">What it shows</label><input id="ev_label" name="label" type="text" required maxlength="120" placeholder="e.g. Signed delivery receipt"></div>
      <div><label for="ev_file">File (PDF, JPEG or PNG)</label><input id="ev_file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
      <div><button class="btn secondary" type="submit">Upload</button></div>
    </form>
  @endif
</section>
