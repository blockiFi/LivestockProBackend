@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A security-related change was made to your account.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Event', 'value' => $security_event ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'When', 'value' => $event_at ?? ($timestamp ?? null)])
    @include('emails.notifications.partials.detail', ['label' => 'IP address', 'value' => $ip_address ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Device', 'value' => $user_agent ?? null])
@endsection

@section('extra')
    <p class="text" style="margin: 0;">If you did not make this change, reset your password immediately and contact your farm administrator.</p>
@endsection
