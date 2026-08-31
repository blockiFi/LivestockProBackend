@extends('emails.notifications.layout')

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? $notification_title }}
@endsection
