@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'An inventory item needs your attention.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Item', 'value' => $item_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Category', 'value' => $item_category ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Quantity remaining', 'value' => $quantity ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Expiry date', 'value' => $expiry_date ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Batch', 'value' => $batch_number ?? null])
@endsection
