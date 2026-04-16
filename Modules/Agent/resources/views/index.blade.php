@extends('agent::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('agent.name') !!}</p>
@endsection
