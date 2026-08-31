@extends('emails.notifications.layout')

@section('heading', 'New task assigned: ' . ($task_name ?? $notification_title))

@section('intro')
    Hello {{ $user_name }},<br><br>
    You have been assigned a new farm task at {{ $farm_name }}.
@endsection

@section('details')
    @include('emails.notifications.partials.task-details')
@endsection

@section('extra')
    @if (!empty($task_description))
        <p class="text" style="margin: 0;">{{ $task_description }}</p>
    @endif
@endsection
