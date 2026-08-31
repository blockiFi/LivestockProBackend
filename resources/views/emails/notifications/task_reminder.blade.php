@extends('emails.notifications.layout')

@section('heading', 'Reminder: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'This is a reminder for an upcoming farm task.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'Reminder', 'value' => $reminder_label ?? null])
@endsection
