@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A feeding is coming up.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Flock', 'value' => $flock_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Feed', 'value' => $feed_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Quantity', 'value' => $quantity ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Date', 'value' => $due_date ?? ($scheduled_date ?? null)])
    @include('emails.notifications.partials.detail', ['label' => 'Time', 'value' => $due_time ?? ($start_time ?? null)])
    @include('emails.notifications.partials.detail', ['label' => 'Section', 'value' => $section_label ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Assigned to', 'value' => $assigned_to ?? null])
@endsection
