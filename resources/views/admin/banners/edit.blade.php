@extends('admin.layout')
@section('title', 'Edit Banner')
@section('heading', 'Edit Banner')

@section('content')
<div class="w-full">
    <livewire:admin.banner-form :banner-id="$banner->id" />
</div>
@endsection
