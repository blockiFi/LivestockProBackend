@extends('emails.notifications.layout')

@section('heading', 'Task reassigned: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A farm task assignment changed.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Previously assigned to', 'value' => $previous_assignee ?? null])
@endsection
