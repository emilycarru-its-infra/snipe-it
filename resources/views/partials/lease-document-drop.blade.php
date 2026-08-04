{{-- Drag-and-drop intake for lessor lease documents. Posts straight to the
     parse endpoint, which previews the extracted fields before anything is
     committed. --}}
@can('update', \App\Models\Order::class)
<form method="POST" action="{{ route('lease-documents.parse') }}" enctype="multipart/form-data" id="lease-drop-form">
    @csrf
    <div id="lease-drop-zone" tabindex="0" role="button"
         aria-label="{{ trans('admin/lease-intake/general.drop_title') }}">
        <i class="fas fa-file-signature" aria-hidden="true"></i>
        <strong>{{ trans('admin/lease-intake/general.drop_title') }}</strong>
        <p>{{ trans('admin/lease-intake/general.drop_hint') }}</p>
        <input type="file" name="document" id="lease-drop-input" accept=".pdf,.xlsx" class="sr-only">
    </div>
</form>

<style nonce="{{ csrf_token() }}">
    #lease-drop-zone {
        border: 2px dashed #d2d6de;
        border-radius: 4px;
        padding: 18px 20px;
        text-align: center;
        color: #666;
        cursor: pointer;
        transition: border-color 120ms ease, background-color 120ms ease;
    }
    #lease-drop-zone:hover, #lease-drop-zone:focus, #lease-drop-zone.lease-drop-over {
        border-color: #3c8dbc;
        background-color: rgba(60, 141, 188, 0.06);
        color: #333;
    }
    #lease-drop-zone i { font-size: 22px; display: block; margin-bottom: 6px; }
    #lease-drop-zone p { margin: 4px 0 0; font-size: 12px; }
</style>

<script nonce="{{ csrf_token() }}">
    (function () {
        var zone = document.getElementById('lease-drop-zone');
        var input = document.getElementById('lease-drop-input');
        var form = document.getElementById('lease-drop-form');

        zone.addEventListener('click', function () { input.click(); });
        zone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', function () {
            if (input.files.length) { form.submit(); }
        });
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (e) {
                e.preventDefault();
                zone.classList.add('lease-drop-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (e) {
                e.preventDefault();
                zone.classList.remove('lease-drop-over');
            });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                form.submit();
            }
        });
    })();
</script>
@endcan
