@extends('emails.notifications.layout')

@section('heading', 'Welcome to ' . $app_name)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'Your account is ready. You can now manage flocks, schedules and daily farm tasks in one place.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Farm', 'value' => $farm_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Signed in as', 'value' => $user_email ?? null])
@endsection
