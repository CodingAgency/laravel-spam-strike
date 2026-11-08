{{--
    Inside a form that has data-spam-strike:
        <form method="POST" action="/contact" data-spam-strike> <x-spam-strike::fields /> ... </form>
--}}
<input type="hidden" name="{{ config('spam-strike.fields.token') }}" value="" data-spam-strike-token>
<div style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
    <label>Leave this field empty <input type="text" name="{{ config('spam-strike.fields.honeypot') }}" tabindex="-1" autocomplete="off" value=""></label>
</div>
<noscript>
    <style>[data-spam-strike] > :not(noscript) { display: none !important; }</style>
    <iframe src="{{ route('spam-strike.noscript') }}" title="{{ config('app.name') }}" style="display: block; width: 100%; height: {{ config('spam-strike.noscript.height') }}; border: 0;"></iframe>
</noscript>
@once
    <script data-spam-strike-script data-token-url="{{ route('spam-strike.token') }}" data-minimum-seconds="{{ config('spam-strike.minimum_seconds') }}">{!! \CodingAgency\SpamStrike\SpamStrikeServiceProvider::script() !!}</script>
@endonce
