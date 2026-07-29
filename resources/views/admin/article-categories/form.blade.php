@extends('admin.layout')
@section('title', isset($articleCategory) ? 'Edit Category' : 'New Category')
@section('heading', isset($articleCategory) ? 'Edit Category' : 'New Category')

@section('content')
    @livewire('admin.article-category-form', ['categoryId' => $articleCategory->id ?? null])
@endsection
