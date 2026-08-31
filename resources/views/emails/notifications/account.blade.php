@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'There has been a change to your account.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Farm', 'value' => $farm_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Change', 'value' => $change_detail ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Role', 'value' => $role_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Performed by', 'value' => $actor_name ?? null])
@endsection
