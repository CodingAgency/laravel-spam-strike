<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Spam Strike workbench</title></head>
<body style="font-family: system-ui; max-width: 40rem; margin: 2rem auto;">
    @if (session('result'))
        <p id="native-result">Native form went through: {{ json_encode(session('result')) }}</p>
    @endif
    @error('form') <p id="native-error" role="alert">{{ $message }}</p> @enderror

    <h2>Plain HTML form</h2>
    <form id="native" method="POST" action="/contact" data-spam-strike>
        @csrf
        <x-spam-strike::fields />
        <input name="name" placeholder="Name">
        <button id="native-submit">Send</button>
    </form>

    <h2>Form sent with fetch()</h2>
    <form id="ajax" method="POST" action="/contact" data-spam-strike>
        @csrf
        <x-spam-strike::fields />
        <input name="name" placeholder="Name">
        <button id="ajax-submit">Send</button>
    </form>
    <p id="ajax-result"></p>

    <script>
        document.getElementById('ajax').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            await window.SpamStrike.ready(form);
            const answer = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
            document.getElementById('ajax-result').textContent = answer.status + ' ' + await answer.text();
        });
    </script>
</body>
</html>
