@extends('emails.notifications.layout')

@section('heading', 'Task cancelled: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A task that was assigned to you has been cancelled. No action is needed.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Cancelled by', 'value' => $cancelled_by ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Reason', 'value' => $cancellation_reason ?? null])
@endsection
