@extends('emails.notifications.layout')

@section('heading', 'Completion rejected: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A supervisor asked for this task to be done again.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Reviewed by', 'value' => $approved_by ?? null])
@endsection

@section('extra')
    @if (!empty($rejection_reason))
        <p class="text" style="margin: 0;"><strong>Reason:</strong> {{ $rejection_reason }}</p>
    @endif
@endsection
