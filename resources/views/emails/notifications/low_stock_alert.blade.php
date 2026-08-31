@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'Stock levels have dropped below the alert threshold.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Item', 'value' => $item_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Category', 'value' => $item_category ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Quantity remaining', 'value' => $quantity ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Threshold', 'value' => $threshold ?? null])
@endsection

@section('extra')
    <p class="text" style="margin: 0;">Restock before the shortfall affects feeding or treatment schedules.</p>
@endsection
