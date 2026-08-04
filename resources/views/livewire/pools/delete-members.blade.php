{{-- The pool's attached apps, shown in the delete confirmation. Height-capped and
     scrollable, so a long membership stays a tidy box instead of a runaway modal.
     The count and consequence live in the modal description; this is just the list. --}}
<div style="max-height:13rem; overflow-y:auto; border:1px solid rgba(255,255,255,.08); border-radius:.5rem; padding:.4rem .7rem;">
    <ul style="margin:0; padding:0; list-style:none;">
        @foreach ($apps as $app)
            <li style="font-family:ui-monospace,monospace; font-size:.8rem; padding:.2rem 0; opacity:.85;">{{ $app }}</li>
        @endforeach
    </ul>
</div>
