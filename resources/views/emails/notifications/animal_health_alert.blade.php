@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A flock health indicator crossed its alert threshold.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Flock', 'value' => $flock_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Alert', 'value' => $alert_detail ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Recorded', 'value' => $recorded_at ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Threshold', 'value' => $threshold ?? null])
@endsection
