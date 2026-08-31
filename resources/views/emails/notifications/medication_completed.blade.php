@extends('emails.notifications.layout')

@section('heading', $notification_title)

@section('intro')
    Hello {{ $user_name }},<br><br>
    {{ $notification_body ?? 'A medication task was administered and recorded.' }}
@endsection

@section('details')
    @include('emails.notifications.partials.detail', ['label' => 'Medication', 'value' => $medication_name ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Animal group', 'value' => $animal_group ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Dosage', 'value' => $dosage_instructions ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Administered by', 'value' => $completed_by ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Administered at', 'value' => $completed_at ?? null])
    @include('emails.notifications.partials.detail', ['label' => 'Signature', 'value' => $signature_text ?? null])
@endsection
