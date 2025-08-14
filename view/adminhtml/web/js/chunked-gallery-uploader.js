define([
    'Magento_Ui/js/modal/alert',
    'mage/adminhtml/product'
], function (alert) {
    'use strict';
    var uploader = product.galleryUploader.uploader;

    // 1) Small chunks + more retries
    uploader.settings.chunk_size  = '2mb';
    uploader.settings.max_retries = 5;

    // 2) If *any* chunk error, fallback to single-shot
    uploader.bind('Error', function (up, err) {
        if (!err.file._fallback) {
            err.file._fallback = true;
            alert({
                title: 'Chunked upload failed for ' + err.file.name,
                content: 'Switching to single-shot upload.',
                actions: { always: function() {} }
            });
            up.settings.chunk_size  = 0;
            up.settings.max_retries = 1;
            up.removeFile(err.file);
            up.addFile(err.file.getSource());
            up.start();
        }
    });

    // 3) If server returns an error *or* width/height === 0, retry full upload
    uploader.bind('FileUploaded', function (up, file, info) {
        var resp;
        try {
            resp = JSON.parse(info.response);
        } catch (e) {
            resp = { error: 'Invalid server response' };
        }
        if (resp.error || resp.width === 0 || resp.height === 0) {
            if (!file._serverFallback) {
                file._serverFallback = true;
                alert({
                    title: 'Upload incomplete',
                    content: 'Server didn’t process ' + file.name + ' correctly. Retrying full upload.',
                    actions: { always: function() {} }
                });
                up.settings.chunk_size  = 0;
                up.settings.max_retries = 1;
                up.removeFile(file);
                up.addFile(file.getSource());
                up.start();
            }
        }
    });

    return product.galleryUploader;
});
