@extends('emails.notifications.layout')

@section('heading', 'Overdue: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A farm task has passed its due time without being completed.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Overdue by', 'value' => $overdue_for ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Escalation', 'value' => $escalation_stage ?? null])
@endsection
