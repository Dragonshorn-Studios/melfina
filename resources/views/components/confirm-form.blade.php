@props(['action', 'confirm', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" {{ $attributes }}
    onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="{{ $confirm }}">
    @csrf
    @unless ($method === 'POST')
        @method($method)
    @endunless
    {{ $slot }}
</form>
