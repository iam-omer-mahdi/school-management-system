@props(['minor' => 0])

<bdi dir="ltr">{{ \App\Support\Money::format((int) $minor) }}</bdi>
