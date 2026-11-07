<div class="nx-document-preview-modal" id="documentPreviewModal" hidden>
    <section
        class="nx-document-preview-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Private document preview"
    >
        <button
            type="button"
            class="nx-document-preview-close"
            id="documentPreviewClose"
            aria-label="Close document preview"
        >&times;</button>
        <iframe
            id="documentPreviewFrame"
            title="Private document preview"
            referrerpolicy="no-referrer"
        ></iframe>
    </section>
</div>
<script src="<?php echo e(nxCodeUrl('JS/document_preview_modal.js')); ?>" defer></script>
