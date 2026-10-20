{{--
    One confirmation dialog for the whole app, used by every <form data-confirm="...">. Optional on the form:
    data-confirm-title, data-confirm-button (label), data-confirm-tone="danger" (red, for deleting or undoing).
--}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content confirm-modal">
            <div class="modal-body p-4">
                <div class="d-flex gap-3">
                    <span class="confirm-icon" data-confirm-icon aria-hidden="true"><i class="bi bi-question-lg"></i></span>
                    <div class="min-w-0">
                        <h2 class="h5 mb-1" id="confirmModalTitle" data-confirm-title>Please confirm</h2>
                        <p class="text-secondary mb-0" data-confirm-message></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-ok>Yes, continue</button>
            </div>
        </div>
    </div>
</div>
