@extends('layouts.merchant')
@section('title', 'Documents')
@section('content')
<section class="card">
  <h2>What we need</h2>
  <div class="table-wrap"><table><thead><tr><th>Document</th><th>Why</th><th>Status</th></tr></thead><tbody>
    @foreach ($items as $i)
      <tr><td>{{ $i['label'] }}</td><td class="small muted">{{ $i['source'] === 'requirement' ? 'Required for your application' : 'Requested by '.$i['source'] }}</td>
        <td>@if ($i['satisfied'])<span class="badge green">Received</span>@else<span class="badge amber">Needed</span>@endif</td></tr>
    @endforeach
  </tbody></table></div>
</section>
@if (in_array('documents.manage', $permissions ?? [], true))
<section class="card">
  <h2>Upload</h2>
  <p class="muted small">PDF, JPEG or PNG up to 10 MB. Files are encrypted and only visible to your account and our underwriting team.</p>
  <form method="post" action="{{ route('merchant.documents.upload') }}" enctype="multipart/form-data" class="inline-form">@csrf
    <div><label>Document</label><select name="document_type">@foreach (collect($items)->where('satisfied', false) as $i)<option value="{{ $i['document_type'] }}">{{ $i['label'] }}</option>@endforeach<option value="other_evidence">Other</option></select></div>
    <div><label>File</label><input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
    <div><button class="btn" type="submit">Upload securely</button></div>
  </form>
</section>
@endif
<section class="card">
  <h2>Uploaded</h2>
  <div class="rows">
    @forelse ($documents as $d)
      <div><span>{{ $d->original_name }} <span class="muted small">{{ $d->document_type }} · {{ $d->created_at->format('M j, Y') }}</span></span><a class="btn secondary small" href="{{ $checklist->downloadUrl($d, 'merchant') }}">Download</a></div>
    @empty<div class="muted">No documents yet.</div>@endforelse
  </div>
</section>
@endsection
