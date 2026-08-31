@extends('emails.notifications.layout')

@section('heading', 'Task updated: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'Details of a task assigned to you have changed.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
    @include('emails.notifications.partials.detail', ['label' => 'What changed', 'value' => $changes ?? null])
@endsection
