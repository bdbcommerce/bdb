@extends('admin.layout')
@section('title', isset($article) ? 'Edit Article' : 'New Article')
@section('heading', isset($article) ? 'Edit Article' : 'New Article')

@section('content')
    @livewire('admin.article-form', ['articleId' => $article->id ?? null])
@endsection
