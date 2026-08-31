@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A farm task was marked complete.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Completed by', 'value' => $completed_by ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Completed at', 'value' => $completed_at ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Signature', 'value' => $signature_text ?? null])
@endsection

@section('extra')
    @if (!empty($completion_notes))
        <p class="text" style="margin: 0;"><strong>Notes:</strong> {{ $completion_notes }}</p>
    @endif
@endsection
