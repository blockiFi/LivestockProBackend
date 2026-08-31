@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A medication or vaccination is due.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Medication', 'value' => $medication_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Vaccine', 'value' => $vaccine_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Flock', 'value' => $flock_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Animal group', 'value' => $animal_group ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Scheduled date', 'value' => $due_date ?? ($scheduled_date ?? null)])
    @include('emails.notifications.partials.detail', ['label' => 'Due time', 'value' => $due_time ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Dosage', 'value' => $dosage_instructions ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Assigned to', 'value' => $assigned_to ?? null])
@endsection
