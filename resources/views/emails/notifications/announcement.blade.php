@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {!! nl2br(e($notification_body)) !!}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'From', 'value' => $app_name ?? 'Farm Central'])
@endsection
