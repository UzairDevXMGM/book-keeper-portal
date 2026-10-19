{{--
    Round picture for a person, or initials when there is no picture (and always for a business).
    <x-avatar :user="$user" size="md" />   <x-avatar :name="$company->name" square />
--}}
@props(['name' => null, 'user' => null, 'size' => 'sm', 'square' => false])

@php
    $name ??= $user?->name ?? '';
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    // "?v=" changes with every new picture, so browsers do not keep showing the old one
    $photo = $user?->avatar_path ? route('profile.photo', ['user' => $user, 'v' => substr(md5($user->avatar_path), 0, 8)]) : null;
@endphp

@if ($photo)
    <img src="{{ $photo }}" alt="" {{ $attributes->class(['avatar', 'avatar-photo', "avatar-{$size}", 'avatar-square' => $square]) }}>
@else
    <span {{ $attributes->class(['avatar', "avatar-{$size}", 'avatar-square' => $square]) }} aria-hidden="true">{{ $initials ?: '?' }}</span>
@endif
